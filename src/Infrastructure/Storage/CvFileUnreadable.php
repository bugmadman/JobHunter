<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

final class CvFileUnreadable extends \RuntimeException
{
    public static function create(string $storedName, \Throwable $previous): self
    {
        return new self(\sprintf('The stored CV file "%s" cannot be read.', $storedName), 0, $previous);
    }
}
