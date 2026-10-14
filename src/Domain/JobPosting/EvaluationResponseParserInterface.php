<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

use App\Infrastructure\Llm\JsonSchema;

interface EvaluationResponseParserInterface
{
    /**
     * The schema the LLM is asked to follow; parse() validates against the same one.
     */
    public function schema(): JsonSchema;

    /**
     * @throws InvalidEvaluationResponse
     */
    public function parse(string $rawResponse): JobPostingEvaluation;
}
