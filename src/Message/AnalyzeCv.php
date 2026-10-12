<?php

declare(strict_types=1);

namespace App\Message;

final readonly class AnalyzeCv
{
    public function __construct(
        public int $cvProfileId,
    ) {
    }
}
