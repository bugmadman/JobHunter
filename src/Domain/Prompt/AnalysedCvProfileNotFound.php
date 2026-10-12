<?php

declare(strict_types=1);

namespace App\Domain\Prompt;

final class AnalysedCvProfileNotFound extends \DomainException
{
    public static function create(): self
    {
        return new self('There is no CV profile with a finished analysis to generate a prompt from.');
    }
}
