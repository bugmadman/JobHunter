<?php

declare(strict_types=1);

namespace App\Domain\ScanInstruction;

final class EmptyScanInstructionText extends \DomainException
{
    public static function create(): self
    {
        return new self('The scan instruction text must not be empty.');
    }
}
