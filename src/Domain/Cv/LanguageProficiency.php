<?php

declare(strict_types=1);

namespace App\Domain\Cv;

final readonly class LanguageProficiency
{
    /**
     * @param non-empty-string $language
     */
    private function __construct(
        public string $language,
        public LanguageLevel $level,
    ) {
    }

    /**
     * The language is an ISO 639-1 code, so the same language from different CVs and postings compares equal.
     */
    public static function create(string $language, LanguageLevel $level): self
    {
        $normalized = strtolower(trim($language));
        if (1 !== preg_match('/^[a-z]{2}$/', $normalized)) {
            throw InvalidLanguageProficiency::invalidLanguage($language);
        }

        return new self($normalized, $level);
    }
}
