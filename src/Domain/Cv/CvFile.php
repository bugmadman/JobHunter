<?php

declare(strict_types=1);

namespace App\Domain\Cv;

final readonly class CvFile
{
    public const int MAX_NAME_LENGTH = 255;

    /**
     * @param non-empty-string $storedName
     * @param non-empty-string $originalName
     */
    private function __construct(
        public string $storedName,
        public string $originalName,
    ) {
    }

    /**
     * The stored name is a bare file name inside the CV storage directory, never a path: whatever ends up in the
     * column is later joined to that directory, so a slash or a leading dot would let it point elsewhere.
     */
    public static function create(string $storedName, string $originalName): self
    {
        if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}\z/', $storedName)) {
            throw InvalidCvFile::invalidStoredName($storedName);
        }

        $originalName = trim($originalName);
        if ('' === $originalName || mb_strlen($originalName) > self::MAX_NAME_LENGTH) {
            throw InvalidCvFile::invalidOriginalName();
        }

        return new self($storedName, $originalName);
    }
}
