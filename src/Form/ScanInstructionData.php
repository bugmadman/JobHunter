<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Platform;
use Symfony\Component\Validator\Constraints as Assert;

final class ScanInstructionData
{
    #[Assert\NotBlank]
    public ?Platform $platform = null;

    #[Assert\NotBlank]
    public ?string $text = null;
}
