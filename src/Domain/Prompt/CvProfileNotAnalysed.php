<?php

declare(strict_types=1);

namespace App\Domain\Prompt;

use App\Domain\Llm\LlmOperationStatus;

final class CvProfileNotAnalysed extends \DomainException
{
    public static function withStatus(LlmOperationStatus $status): self
    {
        return new self(\sprintf(
            'A prompt is generated only from an analysed CV profile; its analysis is "%s".',
            $status->value,
        ));
    }
}
