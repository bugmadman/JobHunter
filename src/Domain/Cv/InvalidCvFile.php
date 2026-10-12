<?php

declare(strict_types=1);

namespace App\Domain\Cv;

final class InvalidCvFile extends \DomainException
{
    public static function invalidStoredName(string $storedName): self
    {
        return new self(\sprintf('"%s" is not a valid stored CV file name.', $storedName));
    }

    public static function invalidOriginalName(): self
    {
        return new self(\sprintf(
            'The original CV file name must be 1 to %d characters long.',
            CvFile::MAX_NAME_LENGTH,
        ));
    }
}
