<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Llm\LlmTask;
use App\Entity\LlmModelSelection;

interface LlmModelSelectionRepositoryInterface
{
    public function findByTask(LlmTask $task): ?LlmModelSelection;

    public function save(LlmModelSelection $selection): void;
}
