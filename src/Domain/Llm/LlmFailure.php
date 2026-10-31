<?php

declare(strict_types=1);

namespace App\Domain\Llm;

use App\Infrastructure\Llm\LlmRequestFailed;
use App\Infrastructure\Security\ApiKeyDecryptionFailed;
use App\Infrastructure\Security\InvalidEncryptionKey;

/**
 * Why an LLM operation failed. Only the provider's text is kept as given, to be shown verbatim: the other causes
 * are ours and get translated. The provider's text never holds the key, LlmRequestFailed has it masked.
 */
final readonly class LlmFailure
{
    private function __construct(
        public LlmFailureCause $cause,
        public ?string $providerMessage,
    ) {
    }

    public static function create(LlmFailureCause $cause, ?string $providerMessage = null): self
    {
        if ((LlmFailureCause::Provider === $cause) !== (null !== $providerMessage && '' !== $providerMessage)) {
            throw new \LogicException('A provider failure needs the provider message, and only it has one.');
        }

        return new self($cause, $providerMessage);
    }

    /**
     * Looks through the exception chain, so a failure wrapped by Messenger still finds the LLM exception inside.
     */
    public static function fromThrowable(\Throwable $throwable): self
    {
        for ($current = $throwable; null !== $current; $current = $current->getPrevious()) {
            $cause = match (true) {
                $current instanceof LlmRequestFailed => LlmFailureCause::Provider,
                $current instanceof LlmModelNotSelected => LlmFailureCause::ModelNotSelected,
                $current instanceof ApiKeyNotSet => LlmFailureCause::ApiKeyNotSet,
                $current instanceof ApiKeyDecryptionFailed,
                $current instanceof InvalidEncryptionKey => LlmFailureCause::ApiKeyUnreadable,
                default => null,
            };

            if (null !== $cause) {
                return self::create(
                    $cause,
                    LlmFailureCause::Provider === $cause ? $current->getMessage() : null,
                );
            }
        }

        return new self(LlmFailureCause::Unexpected, null);
    }
}
