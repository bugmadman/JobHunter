<?php

declare(strict_types=1);

namespace App\Domain\Cv;

use App\Domain\Llm\LlmOperationStatus;

final class CvAnalysisNotFailed extends \DomainException
{
    public static function withStatus(LlmOperationStatus $status): self
    {
        return new self(\sprintf('Only a failed CV analysis can be restarted, this one is "%s".', $status->value));
    }
}
