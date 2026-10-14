<?php

declare(strict_types=1);

namespace App\Domain\CoverLetter;

use App\Domain\Llm\LlmOperationStatus;

final class CoverLetterGenerationAlreadyFinished extends \DomainException
{
    public static function withStatus(LlmOperationStatus $status): self
    {
        return new self(\sprintf(
            'The cover letter generation is already finished with the status "%s".',
            $status->value,
        ));
    }
}
