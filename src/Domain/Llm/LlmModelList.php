<?php

declare(strict_types=1);

namespace App\Domain\Llm;

/**
 * @implements \IteratorAggregate<int, LlmModel>
 */
final readonly class LlmModelList implements \IteratorAggregate, \Countable
{
    /**
     * @var list<LlmModel>
     */
    private array $models;

    public function __construct(LlmModel ...$models)
    {
        $this->models = array_values($models);
    }

    public function contains(LlmModel $model): bool
    {
        return array_any($this->models, static fn (LlmModel $listed): bool => $listed->value === $model->value);
    }

    /**
     * @return \Generator<int, LlmModel>
     */
    public function getIterator(): \Generator
    {
        yield from $this->models;
    }

    public function count(): int
    {
        return \count($this->models);
    }
}
