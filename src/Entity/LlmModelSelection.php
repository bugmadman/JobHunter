<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Llm\LlmModel;
use App\Domain\Llm\LlmProvider;
use App\Domain\Llm\LlmTask;
use App\Repository\LlmModelSelectionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LlmModelSelectionRepository::class)]
final class LlmModelSelection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $model;

    public function __construct(
        #[ORM\Column(length: 32, unique: true, enumType: LlmTask::class)]
        private LlmTask $task,
        #[ORM\Column(length: 32, enumType: LlmProvider::class)]
        private LlmProvider $provider,
        LlmModel $model,
    ) {
        $this->model = $model->value;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTask(): LlmTask
    {
        return $this->task;
    }

    public function getProvider(): LlmProvider
    {
        return $this->provider;
    }

    public function getModel(): LlmModel
    {
        return LlmModel::fromString($this->model);
    }

    public function changeModel(LlmProvider $provider, LlmModel $model): void
    {
        $this->provider = $provider;
        $this->model = $model->value;
    }
}
