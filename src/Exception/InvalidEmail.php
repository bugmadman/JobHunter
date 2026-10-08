<?php

declare(strict_types=1);

namespace App\Exception;

final class InvalidEmail extends \DomainException
{
    public static function fromValue(string $value): self
    {
        return new self(\sprintf('"%s" is not a valid email address.', $value));
    }
}
