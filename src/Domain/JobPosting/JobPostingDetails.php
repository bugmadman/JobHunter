<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

use App\Domain\Shared\Text;

/**
 * What the pipeline needs from a posting on any platform. Platform-specific data (salary, client rating) is not here.
 */
final readonly class JobPostingDetails
{
    public const int MAX_LINE_LENGTH = 255;
    public const int MAX_URL_LENGTH = 2048;

    /**
     * @param non-empty-string      $title
     * @param non-empty-string      $description
     * @param non-empty-string      $url
     * @param non-empty-string|null $company
     * @param non-empty-string|null $location
     */
    private function __construct(
        public string $title,
        public string $description,
        public string $url,
        public ?string $company,
        public ?string $location,
    ) {
    }

    /**
     * A blank company or location becomes null: Upwork names no company, and a location is often missing.
     *
     * @throws InvalidJobPostingDetails
     */
    public static function create(
        string $title,
        string $description,
        string $url,
        ?string $company = null,
        ?string $location = null,
    ): self {
        return new self(
            self::requiredLine('title', $title),
            self::description($description),
            self::url($url),
            self::optionalLine('company', $company),
            self::optionalLine('location', $location),
        );
    }

    /**
     * @return non-empty-string
     */
    private static function requiredLine(string $field, string $value): string
    {
        return self::optionalLine($field, $value) ?? throw InvalidJobPostingDetails::emptyField($field);
    }

    /**
     * @return non-empty-string|null
     */
    private static function optionalLine(string $field, ?string $value): ?string
    {
        $value = self::clean($field, $value ?? '');

        if ('' === $value) {
            return null;
        }

        if (mb_strlen($value) > self::MAX_LINE_LENGTH) {
            throw InvalidJobPostingDetails::tooLong($field, self::MAX_LINE_LENGTH);
        }

        return $value;
    }

    /**
     * Scraped text: Text::trim() would turn invalid UTF-8 into an empty string, and PostgreSQL rejects a NUL byte.
     */
    private static function clean(string $field, string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw InvalidJobPostingDetails::invalidText($field);
        }

        return Text::trim($value);
    }

    /**
     * @return non-empty-string
     */
    private static function description(string $description): string
    {
        $description = self::clean('description', $description);

        if ('' === $description) {
            throw InvalidJobPostingDetails::emptyField('description');
        }

        return $description;
    }

    /**
     * @return non-empty-string
     */
    private static function url(string $url): string
    {
        $url = self::clean('url', $url);

        if ('' === $url) {
            throw InvalidJobPostingDetails::emptyField('url');
        }

        if (\strlen($url) > self::MAX_URL_LENGTH) {
            throw InvalidJobPostingDetails::tooLong('url', self::MAX_URL_LENGTH);
        }

        $scheme = parse_url($url, \PHP_URL_SCHEME);

        if (
            false === filter_var($url, \FILTER_VALIDATE_URL)
            || !\is_string($scheme)
            || !\in_array(strtolower($scheme), ['http', 'https'], true)
            || null !== parse_url($url, \PHP_URL_USER)
        ) {
            throw InvalidJobPostingDetails::invalidUrl($url);
        }

        return $url;
    }
}
