<?php

declare(strict_types=1);

namespace App\Domain\Cv;

enum SkillLevel: int
{
    case Familiar = 1;
    case Basic = 2;
    case Working = 3;
    case Strong = 4;
    case Expert = 5;
}
