<?php

declare(strict_types=1);

namespace App\Domain\User;

final class EmptyPassword extends \DomainException
{
    public static function create(): self
    {
        return new self('The password must not be empty.');
    }
}
