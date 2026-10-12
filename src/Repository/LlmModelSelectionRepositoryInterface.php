<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Llm\LlmTask;
use App\Entity\LlmModelSelection;

interface LlmModelSelectionRepositoryInterface
{
    public function findByTask(LlmTask $task): ?LlmModelSelection;

    /**
     * @return list<LlmTask>
     */
    public function findConfiguredTasks(): array;

    public function save(LlmModelSelection $selection): void;

    public function remove(LlmModelSelection $selection): void;
}
