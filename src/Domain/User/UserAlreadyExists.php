<?php

declare(strict_types=1);

namespace App\Domain\User;

final class UserAlreadyExists extends \DomainException
{
    public static function create(): self
    {
        return new self('The user already exists: this is a single-user service.');
    }
}
