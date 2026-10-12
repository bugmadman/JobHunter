<?php

declare(strict_types=1);

namespace App\Domain\Cv;

final readonly class ParsedCvAnalysis
{
    /**
     * @param list<non-empty-string> $skillNames
     */
    public function __construct(
        public CvAnalysis $analysis,
        public array $skillNames,
    ) {
    }
}
