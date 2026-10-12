<?php

declare(strict_types=1);

namespace App\Infrastructure\Llm;

enum LlmAttachmentType: string
{
    case Pdf = 'application/pdf';
}
