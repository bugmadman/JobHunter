<?php

declare(strict_types=1);

namespace App\Domain\Llm;

enum LlmOperationStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';
}
