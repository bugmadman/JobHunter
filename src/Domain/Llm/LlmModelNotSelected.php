<?php

declare(strict_types=1);

namespace App\Domain\Llm;

final class LlmModelNotSelected extends \RuntimeException
{
    public static function forTask(LlmTask $task): self
    {
        return new self(\sprintf('No model is selected for the "%s" LLM task.', $task->value));
    }
}
