<?php

declare(strict_types=1);

namespace App\Domain\Cv;

enum Seniority: string
{
    case Junior = 'junior';
    case Middle = 'middle';
    case Senior = 'senior';
    case Lead = 'lead';
}
