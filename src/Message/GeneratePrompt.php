<?php

declare(strict_types=1);

namespace App\Message;

final readonly class GeneratePrompt
{
    public function __construct(
        public int $promptId,
    ) {
    }
}
