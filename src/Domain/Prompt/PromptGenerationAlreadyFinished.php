<?php

declare(strict_types=1);

namespace App\Domain\Prompt;

use App\Domain\Llm\LlmOperationStatus;

final class PromptGenerationAlreadyFinished extends \DomainException
{
    public static function withStatus(LlmOperationStatus $status): self
    {
        return new self(\sprintf('The prompt generation is already finished with the status "%s".', $status->value));
    }
}
