<?php

declare(strict_types=1);

namespace App\Domain\Prompt;

enum PromptSource: string
{
    case Generated = 'generated';
    case Edited = 'edited';
}
