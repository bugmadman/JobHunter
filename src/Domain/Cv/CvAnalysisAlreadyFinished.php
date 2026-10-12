<?php

declare(strict_types=1);

namespace App\Domain\Cv;

use App\Domain\Llm\LlmOperationStatus;

final class CvAnalysisAlreadyFinished extends \DomainException
{
    public static function withStatus(LlmOperationStatus $status): self
    {
        return new self(\sprintf('The CV analysis is already finished with the status "%s".', $status->value));
    }
}
