<?php

declare(strict_types=1);

namespace App\Domain\Cv;

final class InvalidLanguageProficiency extends \DomainException
{
    public static function invalidLanguage(string $language): self
    {
        return new self(\sprintf('"%s" is not a two-letter ISO 639-1 language code.', $language));
    }
}
