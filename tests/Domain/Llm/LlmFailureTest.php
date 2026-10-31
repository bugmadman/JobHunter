<?php

declare(strict_types=1);

namespace App\Tests\Domain\Llm;

use App\Domain\Llm\ApiKeyNotSet;
use App\Domain\Llm\LlmClientNotFound;
use App\Domain\Llm\LlmFailure;
use App\Domain\Llm\LlmFailureCause;
use App\Domain\Llm\LlmModelNotSelected;
use App\Domain\Llm\LlmProvider;
use App\Domain\Llm\LlmTask;
use App\Infrastructure\Llm\LlmRequestFailed;
use App\Infrastructure\Security\ApiKeyDecryptionFailed;
use App\Infrastructure\Security\InvalidEncryptionKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class LlmFailureTest extends TestCase
{
    public function testAProviderFailureKeepsTheProviderMessage(): void
    {
        $failure = LlmFailure::create(LlmFailureCause::Provider, 'The gemini request failed: HTTP 403');

        self::assertSame(LlmFailureCause::Provider, $failure->cause);
        self::assertSame('The gemini request failed: HTTP 403', $failure->providerMessage);
    }

    public function testOurOwnCauseHasNoProviderMessage(): void
    {
        $failure = LlmFailure::create(LlmFailureCause::InvalidResponse);

        self::assertSame(LlmFailureCause::InvalidResponse, $failure->cause);
        self::assertNull($failure->providerMessage);
    }

    #[DataProvider('mismatchedMessages')]
    public function testTheProviderMessageBelongsOnlyToAProviderFailure(LlmFailureCause $cause, ?string $message): void
    {
        $this->expectException(\LogicException::class);

        LlmFailure::create($cause, $message);
    }

    /**
     * @return iterable<string, array{LlmFailureCause, ?string}>
     */
    public static function mismatchedMessages(): iterable
    {
        yield 'provider without a message' => [LlmFailureCause::Provider, null];
        yield 'provider with an empty message' => [LlmFailureCause::Provider, ''];
        yield 'our cause with a message' => [LlmFailureCause::InvalidResponse, 'HTTP 403'];
    }

    #[DataProvider('causes')]
    public function testTellsTheCauseFromTheException(\Throwable $exception, LlmFailureCause $cause): void
    {
        $failure = LlmFailure::fromThrowable($exception);

        self::assertSame($cause, $failure->cause);
        self::assertNull($failure->providerMessage);
    }

    /**
     * @return iterable<string, array{\Throwable, LlmFailureCause}>
     */
    public static function causes(): iterable
    {
        yield 'no model' => [LlmModelNotSelected::forTask(LlmTask::CvAnalysis), LlmFailureCause::ModelNotSelected];
        yield 'no api key' => [ApiKeyNotSet::forProvider(LlmProvider::Gemini), LlmFailureCause::ApiKeyNotSet];
        yield 'undecryptable api key' => [ApiKeyDecryptionFailed::create(), LlmFailureCause::ApiKeyUnreadable];
        yield 'no encryption key' => [InvalidEncryptionKey::notSet(), LlmFailureCause::ApiKeyUnreadable];
        yield 'no client' => [LlmClientNotFound::forProvider(LlmProvider::Gemini), LlmFailureCause::Unexpected];
        yield 'anything else' => [new \RuntimeException('Connection refused'), LlmFailureCause::Unexpected];
    }

    public function testKeepsTheProviderTextAsGiven(): void
    {
        $exception = LlmRequestFailed::permanent(LlmProvider::Gemini, 'HTTP 403: outstanding balance');

        $failure = LlmFailure::fromThrowable($exception);

        self::assertSame(LlmFailureCause::Provider, $failure->cause);
        self::assertSame($exception->getMessage(), $failure->providerMessage);
    }

    public function testFindsTheProviderFailureWrappedByMessenger(): void
    {
        $exception = LlmRequestFailed::permanent(LlmProvider::Gemini, 'HTTP 403: outstanding balance');
        $wrapped = new HandlerFailedException(
            new Envelope(new \stdClass()),
            [new UnrecoverableMessageHandlingException($exception->getMessage(), 0, $exception)],
        );

        self::assertEquals(LlmFailure::fromThrowable($exception), LlmFailure::fromThrowable($wrapped));
    }
}
