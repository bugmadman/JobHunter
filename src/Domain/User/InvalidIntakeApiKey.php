<?php

declare(strict_types=1);

namespace App\Domain\User;

final class InvalidIntakeApiKey extends \DomainException
{
    public static function create(): self
    {
        return new self('The intake API key must be 64 lowercase hexadecimal characters.');
    }
}
