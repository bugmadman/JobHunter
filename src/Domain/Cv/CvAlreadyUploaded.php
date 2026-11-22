<?php

declare(strict_types=1);

namespace App\Domain\Cv;

use App\Domain\Llm\LlmOperationStatus;

final class CvAlreadyUploaded extends \DomainException
{
    public static function withStatus(LlmOperationStatus $status): self
    {
        return new self(\sprintf('A CV is already uploaded, its analysis is "%s".', $status->value));
    }
}
