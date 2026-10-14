<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

use App\Domain\Skill\SkillName;

final class KeywordFilter implements KeywordFilterInterface
{
    /**
     * \b would treat "+", "#" and "." as boundaries and break "c++", "c#" and ".net"; a mark counts as a letter,
     * so "cafe" does not match a decomposed "café".
     */
    private const string WORD_CHAR = '[\p{L}\p{M}\p{N}]';

    public function matchingSkills(JobPostingDetails $details, array $skills): array
    {
        $title = mb_strtolower($details->title);
        $description = mb_strtolower($details->description);
        $matching = [];

        foreach ($skills as $skill) {
            if (isset($matching[$skill->value])) {
                continue;
            }

            $pattern = self::pattern($skill);

            if (1 === preg_match($pattern, $title) || 1 === preg_match($pattern, $description)) {
                $matching[$skill->value] = $skill;
            }
        }

        return array_values($matching);
    }

    private static function pattern(SkillName $skill): string
    {
        $words = array_map(
            static fn (string $word): string => preg_quote($word, '/'),
            explode(' ', $skill->value),
        );

        return '/(?<!'.self::WORD_CHAR.')'.implode(SkillName::WHITESPACE, $words).'(?!'.self::WORD_CHAR.')/u';
    }
}
