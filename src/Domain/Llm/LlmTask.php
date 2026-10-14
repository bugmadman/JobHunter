<?php

declare(strict_types=1);

namespace App\Domain\Llm;

enum LlmTask: string
{
    case CvAnalysis = 'cv_analysis';
    case PromptGeneration = 'prompt_generation';
    case Matching = 'matching';
    case CoverLetter = 'cover_letter';
}
