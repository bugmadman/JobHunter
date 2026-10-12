<?php

declare(strict_types=1);

namespace App\Tests\Domain\JobPosting;

use App\Domain\JobPosting\InvalidJobPostingDetails;
use App\Domain\JobPosting\JobPostingDetails;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class JobPostingDetailsTest extends TestCase
{
    private const string URL = 'https://www.linkedin.com/jobs/view/123/';

    public function testTrimsEveryField(): void
    {
        $details = JobPostingDetails::create(
            " Senior PHP Developer\u{00A0}",
            "\n  Symfony, PostgreSQL.\n\nRemote. \n",
            ' '.self::URL."\n",
            ' Acme ',
            "\tBerlin ",
        );

        self::assertSame('Senior PHP Developer', $details->title);
        self::assertSame("Symfony, PostgreSQL.\n\nRemote.", $details->description);
        self::assertSame(self::URL, $details->url);
        self::assertSame('Acme', $details->company);
        self::assertSame('Berlin', $details->location);
    }

    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith([" \u{200B}"])]
    public function testBlankCompanyAndLocationBecomeNull(?string $blank): void
    {
        $details = JobPostingDetails::create('PHP Developer', 'Symfony.', self::URL, $blank, $blank);

        self::assertNull($details->company);
        self::assertNull($details->location);
    }

    public function testAcceptsLinesAtTheMaximumLength(): void
    {
        $line = str_repeat('я', JobPostingDetails::MAX_LINE_LENGTH);
        $url = 'https://example.com/'.str_repeat('a', JobPostingDetails::MAX_URL_LENGTH - 20);

        $details = JobPostingDetails::create($line, 'Symfony.', $url, $line, $line);

        self::assertSame($line, $details->title);
        self::assertSame($url, $details->url);
    }

    /**
     * @param array{string, string, string, ?string, ?string} $fields
     */
    #[TestWith([['', 'Symfony.', self::URL, null, null], 'title'])]
    #[TestWith([["\u{00A0}", 'Symfony.', self::URL, null, null], 'title'])]
    #[TestWith([['PHP Developer', " \n", self::URL, null, null], 'description'])]
    #[TestWith([['PHP Developer', 'Symfony.', ' ', null, null], 'url'])]
    public function testRejectsAnEmptyRequiredField(array $fields, string $field): void
    {
        $this->expectException(InvalidJobPostingDetails::class);
        $this->expectExceptionMessage(\sprintf('The job posting %s must not be empty.', $field));

        JobPostingDetails::create(...$fields);
    }

    public function testRejectsATooLongTitle(): void
    {
        $this->expectException(InvalidJobPostingDetails::class);
        $this->expectExceptionMessage('The job posting title must not exceed 255 characters.');

        JobPostingDetails::create(str_repeat('a', JobPostingDetails::MAX_LINE_LENGTH + 1), 'Symfony.', self::URL);
    }

    public function testRejectsATooLongCompany(): void
    {
        $this->expectException(InvalidJobPostingDetails::class);
        $this->expectExceptionMessage('The job posting company must not exceed 255 characters.');

        JobPostingDetails::create(
            'PHP Developer',
            'Symfony.',
            self::URL,
            str_repeat('a', JobPostingDetails::MAX_LINE_LENGTH + 1),
        );
    }

    public function testRejectsATooLongLocation(): void
    {
        $this->expectException(InvalidJobPostingDetails::class);
        $this->expectExceptionMessage('The job posting location must not exceed 255 characters.');

        JobPostingDetails::create(
            'PHP Developer',
            'Symfony.',
            self::URL,
            null,
            str_repeat('a', JobPostingDetails::MAX_LINE_LENGTH + 1),
        );
    }

    public function testRejectsATooLongUrl(): void
    {
        $this->expectException(InvalidJobPostingDetails::class);
        $this->expectExceptionMessage('The job posting url must not exceed 2048 characters.');

        JobPostingDetails::create(
            'PHP Developer',
            'Symfony.',
            'https://example.com/'.str_repeat('a', JobPostingDetails::MAX_URL_LENGTH - 19),
        );
    }

    #[TestWith(['www.linkedin.com/jobs/view/123/'])]
    #[TestWith(['/jobs/view/123/'])]
    #[TestWith(['ftp://example.com/job'])]
    #[TestWith(['javascript://example.com/%0Aalert(1)'])]
    #[TestWith(['https://exa mple.com/job'])]
    #[TestWith(['https://www.linkedin.com@evil.example/job'])]
    #[TestWith(['https://user:pw@example.com/job'])]
    public function testRejectsANonHttpUrl(string $url): void
    {
        $this->expectException(InvalidJobPostingDetails::class);
        $this->expectExceptionMessage('is not an absolute http(s) URL.');

        JobPostingDetails::create('PHP Developer', 'Symfony.', $url);
    }

    /**
     * @param array{string, string, string, ?string, ?string} $fields
     */
    #[TestWith([["PHP\xff Developer", 'Symfony.', self::URL, null, null], 'title'])]
    #[TestWith([['PHP Developer', "Sym\0fony.", self::URL, null, null], 'description'])]
    #[TestWith([['PHP Developer', 'Symfony.', self::URL, "Acme\xff", null], 'company'])]
    #[TestWith([['PHP Developer', 'Symfony.', self::URL, null, "Ber\0lin"], 'location'])]
    public function testRejectsInvalidUtf8AndNulBytes(array $fields, string $field): void
    {
        $this->expectException(InvalidJobPostingDetails::class);
        $this->expectExceptionMessage(\sprintf('The job posting %s must be valid UTF-8 without NUL bytes.', $field));

        JobPostingDetails::create(...$fields);
    }

    public function testAcceptsAnUppercaseHttpScheme(): void
    {
        self::assertSame(
            'HTTP://example.com/job',
            JobPostingDetails::create('PHP Developer', 'Symfony.', 'HTTP://example.com/job')->url,
        );
    }
}
