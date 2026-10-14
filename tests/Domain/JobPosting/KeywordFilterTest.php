<?php

declare(strict_types=1);

namespace App\Tests\Domain\JobPosting;

use App\Domain\JobPosting\JobPostingDetails;
use App\Domain\JobPosting\KeywordFilter;
use App\Domain\Skill\SkillName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeywordFilterTest extends TestCase
{
    /**
     * @param list<string> $skills
     * @param list<string> $expected
     */
    #[DataProvider('cases')]
    public function testFindsSkillsAsWholeTerms(
        string $title,
        string $description,
        array $skills,
        array $expected,
    ): void {
        $details = JobPostingDetails::create($title, $description, 'https://example.com/jobs/1');
        $skillNames = array_map(SkillName::fromString(...), $skills);

        $matching = new KeywordFilter()->matchingSkills($details, $skillNames);

        self::assertSame($expected, array_map(static fn (SkillName $skill): string => $skill->value, $matching));
    }

    /**
     * @return iterable<string, array{string, string, list<string>, list<string>}>
     */
    public static function cases(): iterable
    {
        yield 'no skills' => ['PHP Developer', 'Symfony.', [], []];
        yield 'no match' => ['Python Developer', 'Django, Celery.', ['php', 'symfony'], []];
        yield 'title only' => ['PHP Developer', 'Backend role.', ['php'], ['php']];
        yield 'description only' => ['Backend Developer', 'We use Symfony.', ['symfony'], ['symfony']];
        yield 'case-insensitive' => ['Backend', 'SYMFONY and PostgreSQL', ['symfony', 'postgresql'], [
            'symfony',
            'postgresql',
        ]];
        yield 'non-latin case-insensitive' => ['Разработчик', 'Опыт с ПОСТГРЕС', ['постгрес'], ['постгрес']];
        yield 'input order, not text order' => ['Symfony and PHP', 'Docker.', ['docker', 'php', 'symfony'], [
            'docker',
            'php',
            'symfony',
        ]];
        yield 'each skill once' => ['PHP, PHP, PHP', 'php', ['php', 'PHP', 'go'], ['php']];
        yield 'prefix of a word' => ['Backend', 'Good golang skills.', ['go'], []];
        yield 'suffix of a word' => ['Backend', 'Cargo and lingo.', ['go'], []];
        yield 'java inside javascript' => ['Frontend', 'JavaScript only.', ['java'], []];
        yield 'digit after the skill' => ['Backend', 'PHP8 and php7.4.', ['php'], []];
        yield 'digit before the skill' => ['Backend', 'Needs 3php.', ['php'], []];
        yield 'punctuation around' => ['Backend', 'Stack: (php), go; rust. "vue"!', ['php', 'go', 'rust', 'vue'], [
            'php',
            'go',
            'rust',
            'vue',
        ]];
        yield 'at the edges of the text' => ['Go', 'Rust', ['go', 'rust'], ['go', 'rust']];
        yield 'version after a space' => ['Backend', 'PHP 8.4 and Symfony 7.', ['php', 'symfony'], ['php', 'symfony']];
        yield 'hyphenated' => ['Backend', 'Full-stack with PHP-FPM.', ['php', 'stack'], ['php', 'stack']];
        yield 'c++ and c#' => ['Developer', 'C++, C# and C.', ['c++', 'c#', 'c'], ['c++', 'c#', 'c']];
        yield 'c++ not inside a word' => ['Developer', 'Objective-C++x is not a thing.', ['c++'], []];
        yield 'one letter inside c++' => ['Developer', 'We use C++ only.', ['c'], ['c']];
        yield 'leading dot after a letter' => ['Developer', 'ASP.NET developer.', ['.net'], []];
        yield 'leading dot' => ['Developer', 'Experience with .NET Core.', ['.net'], ['.net']];
        yield 'dotted name' => ['Developer', 'Node.js, Vue.js.', ['node.js', 'vue.js'], ['node.js', 'vue.js']];
        yield 'dot is literal' => ['Developer', 'Nodexjs only.', ['node.js'], []];
        yield 'slash in the name' => ['DevOps', 'CI/CD pipelines.', ['ci/cd'], ['ci/cd']];
        yield 'regex characters in the name' => ['Developer', 'Know a(b)*c?', ['a(b)*c'], ['a(b)*c']];
        yield 'multi-word' => ['Backend', 'Domain-Driven Design and event sourcing.', ['event sourcing'], [
            'event sourcing',
        ]];
        yield 'multi-word across a newline' => ['Backend', "Event\nSourcing.", ['event sourcing'], ['event sourcing']];
        yield 'multi-word across an nbsp' => ['Backend', "Event\u{00A0}sourcing.", ['event sourcing'], [
            'event sourcing',
        ]];
        yield 'multi-word across a whitespace run' => ['Backend', "Event \t\n sourcing.", ['event sourcing'], [
            'event sourcing',
        ]];
        yield 'multi-word needs the whitespace' => ['Backend', 'Eventsourcing.', ['event sourcing'], []];
        yield 'multi-word not across title and description' => ['Senior Event', 'Sourcing team.', [
            'event sourcing',
        ], []];
        yield 'combining mark after the skill' => ['Backend', "Cafe\u{0301} owners.", ['cafe'], []];
    }
}
