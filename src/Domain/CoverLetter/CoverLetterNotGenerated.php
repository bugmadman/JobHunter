<?php

declare(strict_types=1);

namespace App\Domain\CoverLetter;

use App\Domain\Llm\LlmOperationStatus;

final class CoverLetterNotGenerated extends \DomainException
{
    public static function withStatus(LlmOperationStatus $status): self
    {
        return new self(\sprintf(
            'Only a generated cover letter can be edited, this one has the status "%s".',
            $status->value,
        ));
    }
}
