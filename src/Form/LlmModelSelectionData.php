<?php

declare(strict_types=1);

namespace App\Form;

use App\Domain\Llm\LlmProvider;
use App\Domain\Llm\LlmTask;
use Symfony\Component\Validator\Constraints as Assert;

final class LlmModelSelectionData
{
    #[Assert\NotBlank]
    public ?LlmTask $task = null;

    #[Assert\NotBlank]
    public ?LlmProvider $provider = null;

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 100)]
    public ?string $model = null;
}
