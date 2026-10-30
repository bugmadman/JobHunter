<?php

declare(strict_types=1);

namespace App\Infrastructure\Llm;

use App\Domain\Llm\ApiKey;
use App\Domain\Llm\LlmModel;
use App\Domain\Llm\LlmModelList;
use App\Domain\Llm\LlmProvider;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsTaggedItem(index: LlmProvider::Gemini->value)]
final readonly class GeminiClient implements LlmClientInterface
{
    private const string MODELS_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    /**
     * Gemini sends nothing until the whole answer is ready, so the default idle timeout
     * (default_socket_timeout, 60 s) would cut off a slow model reading a PDF.
     */
    private const int GENERATE_TIMEOUT_SECONDS = 300;

    /**
     * A total limit per request, not an idle one: the list is loaded while a settings page renders,
     * and a slow call must fail with a reason before PHP's 30 s max_execution_time kills the page.
     * The page cap keeps the sum of all requests under it too.
     */
    private const int LIST_MAX_DURATION_SECONDS = 10;

    private const int LIST_PAGE_SIZE = 1000;

    private const int LIST_MAX_PAGES = 2;

    /**
     * An allowlist, not a denylist: Google keeps adding image, music, speech and agent models with
     * `generateContent` under unpredictable names, so a new family stays hidden until it is added here.
     */
    private const string TEXT_MODEL_NAME = '/^gemini-(?:\d+(?:\.\d+)?-(?:flash|flash-lite|pro)(?:-preview)?'
        .'|(?:flash|flash-lite|pro)-latest)$/D';

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function generate(LlmRequest $request, LlmModel $model, ApiKey $apiKey): LlmResponse
    {
        $payload = $this->request(
            'POST',
            \sprintf('%s/%s:generateContent', self::MODELS_URL, rawurlencode($model->value)),
            ['json' => $this->body($request), 'timeout' => self::GENERATE_TIMEOUT_SECONDS],
            $apiKey,
        );
        $text = $this->text($payload, $apiKey);

        if (null !== $request->responseSchema && !json_validate($text)) {
            throw $this->failure('the response is not valid JSON', $apiKey);
        }

        return new LlmResponse($text);
    }

    public function listModels(ApiKey $apiKey): LlmModelList
    {
        $models = [];
        $pageToken = null;

        for ($page = 0; $page < self::LIST_MAX_PAGES; ++$page) {
            $query = ['pageSize' => self::LIST_PAGE_SIZE];

            if (null !== $pageToken) {
                $query['pageToken'] = $pageToken;
            }

            $payload = $this->request(
                'GET',
                self::MODELS_URL,
                ['query' => $query, 'max_duration' => self::LIST_MAX_DURATION_SECONDS],
                $apiKey,
            );
            $entries = $payload['models'] ?? [];

            if (!\is_array($entries)) {
                throw $this->failure('malformed model list', $apiKey);
            }

            foreach ($entries as $entry) {
                $model = \is_array($entry) ? $this->textModel($entry) : null;

                if (null !== $model) {
                    $models[] = $model;
                }
            }

            $pageToken = $payload['nextPageToken'] ?? null;

            if (!\is_string($pageToken) || '' === $pageToken) {
                return new LlmModelList(...$models);
            }
        }

        throw $this->failure(\sprintf('the model list has more than %d pages', self::LIST_MAX_PAGES), $apiKey);
    }

    /**
     * @param array<mixed> $entry
     */
    private function textModel(array $entry): ?LlmModel
    {
        $methods = $entry['supportedGenerationMethods'] ?? null;

        if (!\is_array($methods) || !\in_array('generateContent', $methods, true)) {
            return null;
        }

        $name = $entry['name'] ?? null;

        if (!\is_string($name)) {
            return null;
        }

        $name = str_starts_with($name, 'models/') ? substr($name, \strlen('models/')) : $name;

        if (1 !== preg_match(self::TEXT_MODEL_NAME, $name)) {
            return null;
        }

        return LlmModel::fromString($name);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function request(string $method, string $url, array $options, ApiKey $apiKey): array
    {
        try {
            $response = $this->httpClient->request($method, $url, [
                ...$options,
                'headers' => ['x-goog-api-key' => $apiKey->value()],
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            // Not chained as previous: HttpClient messages may quote a header value, and the trace holds the options.
            throw $this->failure('transport error: '.$exception->getMessage(), $apiKey);
        }

        if ($status < 200 || $status >= 300) {
            throw $this->failure($this->errorReason($status, $content), $apiKey);
        }

        $payload = $this->decode($content);

        if (null === $payload) {
            throw $this->failure('malformed JSON response', $apiKey);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(LlmRequest $request): array
    {
        $parts = [];

        if (null !== $request->attachment) {
            $parts[] = [
                'inlineData' => [
                    'mimeType' => $request->attachment->type->value,
                    'data' => base64_encode($request->attachment->contents),
                ],
            ];
        }

        $parts[] = ['text' => $request->prompt];
        $body = ['contents' => [['role' => 'user', 'parts' => $parts]]];

        if (null !== $request->systemInstruction) {
            $body['systemInstruction'] = ['parts' => [['text' => $request->systemInstruction]]];
        }

        if (null !== $request->responseSchema) {
            $body['generationConfig'] = [
                'responseMimeType' => 'application/json',
                // Decoded to objects, not arrays: an empty `{}` in the schema would otherwise be sent as `[]`.
                'responseJsonSchema' => json_decode($request->responseSchema->value, flags: \JSON_THROW_ON_ERROR),
            ];
        }

        return $body;
    }

    private function errorReason(int $status, string $content): string
    {
        $error = $this->decode($content)['error'] ?? null;
        $message = \is_array($error) ? ($error['message'] ?? null) : null;

        return \is_string($message) && '' !== $message
            ? \sprintf('HTTP %d: %s', $status, $message)
            : \sprintf('HTTP %d', $status);
    }

    /**
     * @return array<mixed>|null
     */
    private function decode(string $content): ?array
    {
        try {
            $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($payload) ? $payload : null;
    }

    /**
     * @param array<mixed> $payload
     *
     * @return non-empty-string
     */
    private function text(array $payload, ApiKey $apiKey): string
    {
        $promptFeedback = $payload['promptFeedback'] ?? null;
        $blockReason = \is_array($promptFeedback) ? ($promptFeedback['blockReason'] ?? null) : null;

        if (\is_string($blockReason)) {
            throw $this->failure('the prompt was blocked: '.$blockReason, $apiKey);
        }

        $candidates = $payload['candidates'] ?? null;
        $candidate = \is_array($candidates) ? ($candidates[0] ?? null) : null;

        if (!\is_array($candidate)) {
            throw $this->failure('the response has no candidates', $apiKey);
        }

        $finishReason = $candidate['finishReason'] ?? null;

        if ('STOP' !== $finishReason) {
            $finishReason = \is_string($finishReason) ? $finishReason : 'none';

            throw $this->failure('the generation did not finish normally: '.$finishReason, $apiKey);
        }

        $content = $candidate['content'] ?? null;
        $parts = \is_array($content) ? ($content['parts'] ?? null) : null;
        $text = '';

        foreach (\is_array($parts) ? $parts : [] as $part) {
            if (\is_array($part) && true !== ($part['thought'] ?? false) && \is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        $text = trim($text);

        if ('' === $text) {
            throw $this->failure('the response has no text', $apiKey);
        }

        return $text;
    }

    private function failure(string $reason, ApiKey $apiKey): LlmRequestFailed
    {
        return LlmRequestFailed::create(LlmProvider::Gemini, str_replace($apiKey->value(), '***', $reason));
    }
}
