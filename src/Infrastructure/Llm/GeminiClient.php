<?php

declare(strict_types=1);

namespace App\Infrastructure\Llm;

use App\Domain\Llm\ApiKey;
use App\Domain\Llm\LlmModel;
use App\Domain\Llm\LlmProvider;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GeminiClient implements LlmClientInterface
{
    private const string URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /**
     * Gemini sends nothing until the whole answer is ready, so the default idle timeout
     * (default_socket_timeout, 60 s) would cut off a slow model reading a PDF.
     */
    private const int TIMEOUT_SECONDS = 300;

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function generate(LlmRequest $request, LlmModel $model, ApiKey $apiKey): LlmResponse
    {
        try {
            $response = $this->httpClient->request('POST', \sprintf(self::URL, rawurlencode($model->value)), [
                'headers' => ['x-goog-api-key' => $apiKey->value()],
                'json' => $this->body($request),
                'timeout' => self::TIMEOUT_SECONDS,
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

        $text = $this->text($payload, $apiKey);

        if (null !== $request->responseSchema && !json_validate($text)) {
            throw $this->failure('the response is not valid JSON', $apiKey);
        }

        return new LlmResponse($text);
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
