<?php

declare(strict_types=1);

namespace App\Domain\Cv;

use App\Infrastructure\Llm\JsonSchema;

interface CvAnalysisResponseParserInterface
{
    /**
     * The schema the LLM is asked to follow; parse() validates against the same one.
     */
    public function schema(): JsonSchema;

    /**
     * @throws InvalidCvAnalysisResponse
     */
    public function parse(string $rawResponse): ParsedCvAnalysis;
}
