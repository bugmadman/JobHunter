<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class PromptData
{
    #[Assert\NotBlank]
    public ?string $text = null;
}
