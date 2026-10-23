<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Llm;

use App\Domain\Llm\ApiKey;
use App\Domain\Llm\LlmModel;
use App\Domain\Llm\LlmModelList;
use App\Infrastructure\Llm\GeminiClient;
use App\Infrastructure\Llm\JsonSchema;
use App\Infrastructure\Llm\LlmAttachment;
use App\Infrastructure\Llm\LlmAttachmentType;
use App\Infrastructure\Llm\LlmRequest;
use App\Infrastructure\Llm\LlmRequestFailed;
use App\Infrastructure\Llm\LlmResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GeminiClientTest extends TestCase
{
    private const string API_KEY = 'AIza-secret-key';

    public function testSendsThePromptToTheModelWithTheKeyInAHeader(): void
    {
        $response = self::success('Hello');

        self::generate($response, new LlmRequest('Say hello'));

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',
            $response->getRequestUrl(),
        );
        self::assertContains('x-goog-api-key: '.self::API_KEY, self::requestOption($response, 'headers'));
        self::assertSame(300.0, $response->getRequestOptions()['timeout'] ?? null);
        self::assertSame(0, $response->getRequestOptions()['max_redirects'] ?? null);
        self::assertSame(
            ['contents' => [['role' => 'user', 'parts' => [['text' => 'Say hello']]]]],
            self::requestBody($response),
        );
    }

    public function testSendsTheSystemInstructionAndTheAttachmentBeforeThePrompt(): void
    {
        $response = self::success('Analysed');
        $attachment = LlmAttachment::fromContents(LlmAttachmentType::Pdf, "%PDF-1.7\n");

        self::generate($response, new LlmRequest('Analyse the CV', 'You are a recruiter', $attachment));

        self::assertSame(
            [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [
                        ['inlineData' => ['mimeType' => 'application/pdf', 'data' => base64_encode("%PDF-1.7\n")]],
                        ['text' => 'Analyse the CV'],
                    ],
                ]],
                'systemInstruction' => ['parts' => [['text' => 'You are a recruiter']]],
            ],
            self::requestBody($response),
        );
    }

    public function testEncodesTheModelInTheUrl(): void
    {
        $response = self::success('Hello');

        new GeminiClient(new MockHttpClient($response))->generate(
            new LlmRequest('Say hello'),
            LlmModel::fromString('../files?key=x'),
            ApiKey::fromString(self::API_KEY),
        );

        self::assertSame(
            'https://generativelanguage.googleapis.com/v1beta/models/..%2Ffiles%3Fkey%3Dx:generateContent',
            $response->getRequestUrl(),
        );
    }

    public function testReturnsTheTextOfAllPartsExceptThoughts(): void
    {
        $response = self::json([
            'candidates' => [[
                'content' => ['parts' => [
                    ['text' => 'Thinking...', 'thought' => true],
                    ['text' => 'Hello, '],
                    ['inlineData' => ['mimeType' => 'image/png', 'data' => '']],
                    ['text' => "world\n"],
                ]],
                'finishReason' => 'STOP',
            ]],
        ]);

        self::assertSame('Hello, world', self::generate($response, new LlmRequest('Say hello'))->text);
    }

    public function testRequestsJsonMatchingTheSchema(): void
    {
        $schema = '{"type":"object","properties":{"name":{"type":"string"},"tags":{}},"required":["name"]}';
        $response = self::success('{"name":"Ann"}');

        $result = self::generate($response, new LlmRequest('Extract', responseSchema: JsonSchema::fromString($schema)));

        $body = $response->getRequestOptions()['body'] ?? null;
        self::assertIsString($body);
        $decoded = json_decode($body, flags: \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $decoded);
        $generationConfig = $decoded->generationConfig ?? null;
        self::assertSame(
            '{"responseMimeType":"application/json","responseJsonSchema":'.$schema.'}',
            json_encode($generationConfig, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        );
        self::assertSame('{"name":"Ann"}', $result->text);
    }

    public function testFailsWhenTheTextIsNotTheRequestedJson(): void
    {
        $this->expectException(LlmRequestFailed::class);
        $this->expectExceptionMessage('The gemini request failed: the response is not valid JSON');

        self::generate(
            self::success('Sure! {"name":"Ann"}'),
            new LlmRequest('Extract', responseSchema: JsonSchema::fromString('{"type":"object"}')),
        );
    }

    /**
     * @return iterable<string, array{MockResponse, string}>
     */
    public static function failures(): iterable
    {
        yield 'transport error' => [
            new MockResponse('', ['error' => 'Could not resolve host']),
            'transport error: Could not resolve host',
        ];
        yield 'error status with the Gemini message' => [
            self::json(
                ['error' => ['code' => 400, 'message' => 'API key not valid.', 'status' => 'INVALID_ARGUMENT']],
                400,
            ),
            'HTTP 400: API key not valid.',
        ];
        yield 'error status without a JSON body' => [new MockResponse('Bad Gateway', ['http_code' => 502]), 'HTTP 502'];
        yield 'error status with a JSON body without a message' => [self::json(['error' => []], 500), 'HTTP 500'];
        yield 'redirect' => [
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://example.com']]),
            'HTTP 302',
        ];
        yield 'malformed JSON' => [new MockResponse('{"candidates":'), 'malformed JSON response'];
        yield 'not a JSON object' => [new MockResponse('"Hello"'), 'malformed JSON response'];
        yield 'blocked prompt' => [
            self::json(['promptFeedback' => ['blockReason' => 'SAFETY']]),
            'the prompt was blocked: SAFETY',
        ];
        yield 'no candidates' => [self::json([]), 'the response has no candidates'];
        yield 'candidate stopped for safety' => [
            self::json(['candidates' => [['finishReason' => 'SAFETY']]]),
            'the generation did not finish normally: SAFETY',
        ];
        yield 'candidate hit the token limit' => [
            self::json(['candidates' => [[
                'content' => ['parts' => [['text' => 'Hel']]],
                'finishReason' => 'MAX_TOKENS',
            ]]]),
            'the generation did not finish normally: MAX_TOKENS',
        ];
        yield 'candidate without a finish reason' => [
            self::json(['candidates' => [['content' => ['parts' => [['text' => 'Hello']]]]]]),
            'the generation did not finish normally: none',
        ];
        yield 'candidate without content' => [
            self::json(['candidates' => [['finishReason' => 'STOP']]]),
            'the response has no text',
        ];
        yield 'candidate with only thoughts' => [
            self::json(['candidates' => [[
                'content' => ['parts' => [['text' => 'Thinking...', 'thought' => true]]],
                'finishReason' => 'STOP',
            ]]]),
            'the response has no text',
        ];
        yield 'candidate with blank text' => [
            self::json(['candidates' => [['content' => ['parts' => [['text' => " \n"]]], 'finishReason' => 'STOP']]]),
            'the response has no text',
        ];
    }

    #[DataProvider('failures')]
    public function testFailsWithAReason(MockResponse $response, string $reason): void
    {
        $this->expectException(LlmRequestFailed::class);
        $this->expectExceptionMessage('The gemini request failed: '.$reason);

        self::generate($response, new LlmRequest('Say hello'));
    }

    public function testFailsOnAPromptThatCannotBeEncoded(): void
    {
        $httpClient = new MockHttpClient(self::success('Hello'));

        try {
            new GeminiClient($httpClient)->generate(
                new LlmRequest("Say \xB1 hello"),
                LlmModel::fromString('gemini-2.5-flash'),
                ApiKey::fromString(self::API_KEY),
            );
            self::fail('The request must fail.');
        } catch (LlmRequestFailed $exception) {
            self::assertStringContainsString(
                'transport error: Invalid value for "json" option',
                $exception->getMessage(),
            );
            self::assertNull($exception->getPrevious());
        }

        self::assertSame(0, $httpClient->getRequestsCount());
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function failuresEchoingTheKey(): iterable
    {
        yield 'transport error' => [
            new MockResponse('', ['error' => 'Invalid header "x-goog-api-key: '.self::API_KEY.'"']),
        ];
        yield 'error status' => [
            self::json(['error' => ['message' => 'Invalid header "x-goog-api-key: '.self::API_KEY.'"']], 400),
        ];
    }

    #[DataProvider('failuresEchoingTheKey')]
    public function testNeverPutsTheKeyIntoTheFailure(MockResponse $response): void
    {
        try {
            self::generate($response, new LlmRequest('Say hello'));
            self::fail('The request must fail.');
        } catch (LlmRequestFailed $exception) {
            self::assertStringNotContainsString(self::API_KEY, $exception->getMessage());
            self::assertStringContainsString('x-goog-api-key: ***', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testListsTheModelsWithTheKeyInAHeader(): void
    {
        $response = self::json(['models' => [self::model('models/gemini-2.5-flash')]]);

        $models = self::listModels(new MockHttpClient($response));

        self::assertSame(['gemini-2.5-flash'], self::names($models));
        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame(
            'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000',
            $response->getRequestUrl(),
        );
        self::assertContains('x-goog-api-key: '.self::API_KEY, self::requestOption($response, 'headers'));
        self::assertSame(10.0, $response->getRequestOptions()['max_duration'] ?? null);
        self::assertSame(0, $response->getRequestOptions()['max_redirects'] ?? null);
    }

    public function testListsOnlyTheModelsGeneratingText(): void
    {
        $response = self::json(['models' => [
            self::model('models/gemini-2.5-flash', ['generateContent', 'countTokens', 'createCachedContent']),
            self::model('models/gemini-embedding-001', ['embedContent', 'countTextTokens']),
            self::model('models/imagen-4.0-generate-001', ['predict']),
            self::model('models/gemini-2.5-flash-native-audio-latest', ['countTokens', 'bidiGenerateContent']),
            self::model('models/gemini-2.5-flash-preview-tts'),
            self::model('models/gemini-2.5-flash-image'),
            self::model('models/gemini-2.5-flash-image-preview'),
            self::model('models/gemini-2.0-flash-preview-image-generation'),
            self::model('models/gemini-2.5-pro'),
            self::model('models/gemma-3-27b-it'),
            self::model('models/gemini-imagery-pro'),
            self::model('gemini-2.0-flash'),
            self::model('models/'),
            ['name' => 'models/gemini-no-methods'],
            ['supportedGenerationMethods' => ['generateContent']],
            'models/gemini-not-an-object',
        ]]);

        self::assertSame(
            ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemma-3-27b-it', 'gemini-imagery-pro', 'gemini-2.0-flash'],
            self::names(self::listModels(new MockHttpClient($response))),
        );
    }

    public function testListsNoModelsWhenTheKeyHasNone(): void
    {
        self::assertCount(0, self::listModels(new MockHttpClient(self::json([]))));
    }

    public function testListsTheModelsOfAllPages(): void
    {
        $first = self::json([
            'models' => [self::model('models/gemini-2.5-flash')],
            'nextPageToken' => 'page 2',
        ]);
        $second = self::json(['models' => [self::model('models/gemini-2.5-pro')], 'nextPageToken' => '']);
        $httpClient = new MockHttpClient([$first, $second]);

        $models = self::listModels($httpClient);

        self::assertSame(['gemini-2.5-flash', 'gemini-2.5-pro'], self::names($models));
        self::assertSame(2, $httpClient->getRequestsCount());
        self::assertSame(
            'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000&pageToken=page%202',
            $second->getRequestUrl(),
        );
    }

    public function testFailsWhenThePagesNeverEnd(): void
    {
        $httpClient = new MockHttpClient(
            static fn (): MockResponse => self::json(['models' => [], 'nextPageToken' => 'next']),
        );

        try {
            self::listModels($httpClient);
            self::fail('The request must fail.');
        } catch (LlmRequestFailed $exception) {
            self::assertSame(
                'The gemini request failed: the model list has more than 2 pages',
                $exception->getMessage(),
            );
        }

        self::assertSame(2, $httpClient->getRequestsCount());
    }

    /**
     * @return iterable<string, array{MockResponse, string}>
     */
    public static function listFailures(): iterable
    {
        yield 'transport error' => [
            new MockResponse('', ['error' => 'Could not resolve host']),
            'transport error: Could not resolve host',
        ];
        yield 'invalid key' => [
            self::json(
                ['error' => ['code' => 400, 'message' => 'API key not valid.', 'status' => 'INVALID_ARGUMENT']],
                400,
            ),
            'HTTP 400: API key not valid.',
        ];
        yield 'quota exceeded' => [
            self::json(['error' => ['code' => 429, 'message' => 'Quota exceeded.']], 429),
            'HTTP 429: Quota exceeded.',
        ];
        yield 'malformed JSON' => [new MockResponse('{"models":'), 'malformed JSON response'];
        yield 'models not a list' => [self::json(['models' => 'gemini-2.5-flash']), 'malformed model list'];
    }

    #[DataProvider('listFailures')]
    public function testFailsToListTheModelsWithAReason(MockResponse $response, string $reason): void
    {
        $this->expectException(LlmRequestFailed::class);
        $this->expectExceptionMessage('The gemini request failed: '.$reason);

        self::listModels(new MockHttpClient($response));
    }

    #[DataProvider('failuresEchoingTheKey')]
    public function testNeverPutsTheKeyIntoTheModelListFailure(MockResponse $response): void
    {
        try {
            self::listModels(new MockHttpClient($response));
            self::fail('The request must fail.');
        } catch (LlmRequestFailed $exception) {
            self::assertStringNotContainsString(self::API_KEY, $exception->getMessage());
            self::assertStringContainsString('x-goog-api-key: ***', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    private static function listModels(MockHttpClient $httpClient): LlmModelList
    {
        return new GeminiClient($httpClient)->listModels(ApiKey::fromString(self::API_KEY));
    }

    /**
     * @return list<string>
     */
    private static function names(LlmModelList $models): array
    {
        return array_map(static fn (LlmModel $model): string => $model->value, iterator_to_array($models, false));
    }

    /**
     * @param list<string> $methods
     *
     * @return array<string, mixed>
     */
    private static function model(string $name, array $methods = ['generateContent', 'countTokens']): array
    {
        return ['name' => $name, 'displayName' => $name, 'supportedGenerationMethods' => $methods];
    }

    private static function generate(MockResponse $response, LlmRequest $request): LlmResponse
    {
        return new GeminiClient(new MockHttpClient($response))->generate(
            $request,
            LlmModel::fromString('gemini-2.5-flash'),
            ApiKey::fromString(self::API_KEY),
        );
    }

    private static function success(string $text): MockResponse
    {
        return self::json([
            'candidates' => [[
                'content' => ['parts' => [['text' => $text]], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
        ]);
    }

    /**
     * @param array<mixed> $payload
     */
    private static function json(array $payload, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }

    /**
     * @return list<mixed>
     */
    private static function requestOption(MockResponse $response, string $option): array
    {
        $value = $response->getRequestOptions()[$option] ?? null;
        self::assertIsArray($value);

        return array_values($value);
    }

    private static function requestBody(MockResponse $response): mixed
    {
        $body = $response->getRequestOptions()['body'] ?? null;
        self::assertIsString($body);

        return json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
    }
}
