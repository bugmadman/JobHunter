<?php

declare(strict_types=1);

namespace App\Infrastructure\Llm;

use App\Domain\Llm\ApiKey;
use App\Domain\Llm\LlmModel;
use App\Domain\Llm\LlmModelList;
use App\Domain\Llm\LlmProvider;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
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

    /** Any other status (429, 5xx, an unexpected one) may pass on a retry. */
    private const array PERMANENT_STATUSES = [400, 401, 403, 404];

    /**
     * Content policy verdicts on this very input. Any other reason may pass on a retry: OTHER is opaque,
     * and MAX_TOKENS depends on how much of the default output limit one run's thinking used up.
     */
    private const array PERMANENT_FINISH_REASONS = ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII'];

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
            throw $this->transientFailure('the response is not valid JSON', $apiKey);
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
                throw $this->transientFailure('malformed model list', $apiKey);
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

        $reason = \sprintf('the model list has more than %d pages', self::LIST_MAX_PAGES);

        throw $this->permanentFailure($reason, $apiKey);
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
        } catch (InvalidArgumentException $exception) {
            // Not chained as previous: HttpClient messages may quote a header value, and the trace holds the options.
            throw $this->permanentFailure('transport error: '.$exception->getMessage(), $apiKey);
        } catch (TransportExceptionInterface $exception) {
            throw $this->transientFailure('transport error: '.$exception->getMessage(), $apiKey);
        }

        if ($status < 200 || $status >= 300) {
            $reason = $this->errorReason($status, $content);

            if (\in_array($status, self::PERMANENT_STATUSES, true)) {
                throw $this->permanentFailure($reason, $apiKey);
            }

            throw $this->transientFailure($reason, $apiKey);
        }

        $payload = $this->decode($content);

        if (null === $payload) {
            throw $this->transientFailure('malformed JSON response', $apiKey);
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
            throw $this->permanentFailure('the prompt was blocked: '.$blockReason, $apiKey);
        }

        $candidates = $payload['candidates'] ?? null;
        $candidate = \is_array($candidates) ? ($candidates[0] ?? null) : null;

        if (!\is_array($candidate)) {
            throw $this->transientFailure('the response has no candidates', $apiKey);
        }

        $finishReason = $candidate['finishReason'] ?? null;

        if (!\is_string($finishReason)) {
            throw $this->transientFailure('the generation did not finish normally: none', $apiKey);
        }

        if ('STOP' !== $finishReason) {
            $reason = 'the generation did not finish normally: '.$finishReason;

            if (\in_array($finishReason, self::PERMANENT_FINISH_REASONS, true)) {
                throw $this->permanentFailure($reason, $apiKey);
            }

            throw $this->transientFailure($reason, $apiKey);
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
            // Not permanent: thinking models now and then end with STOP and no text, and a retry usually gets one.
            throw $this->transientFailure('the response has no text', $apiKey);
        }

        return $text;
    }

    private function permanentFailure(string $reason, ApiKey $apiKey): LlmRequestFailed
    {
        return LlmRequestFailed::permanent(LlmProvider::Gemini, $this->mask($reason, $apiKey));
    }

    private function transientFailure(string $reason, ApiKey $apiKey): LlmRequestFailed
    {
        return LlmRequestFailed::transient(LlmProvider::Gemini, $this->mask($reason, $apiKey));
    }

    private function mask(string $reason, ApiKey $apiKey): string
    {
        return str_replace($apiKey->value(), '***', $reason);
    }
}
