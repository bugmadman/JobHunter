<?php

declare(strict_types=1);

namespace App\Domain\Llm;

enum LlmProvider: string
{
    case Gemini = 'gemini';
}
