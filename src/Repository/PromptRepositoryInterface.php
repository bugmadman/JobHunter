<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Platform;
use App\Entity\Prompt;

interface PromptRepositoryInterface
{
    public function findById(int $id): ?Prompt;

    public function findByPlatform(Platform $platform): ?Prompt;

    public function save(Prompt $prompt): void;
}
