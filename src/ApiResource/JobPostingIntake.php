<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use App\Domain\JobPosting\JobPostingDetails;
use App\Domain\Shared\Text;
use App\Entity\Platform;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The request body of the job posting intake. JobPostingDetails stays the source of truth: these constraints repeat
 * its rules only to name every invalid field at once.
 *
 * The platform constraints keep the lookup and its error message bounded: PostgreSQL cuts a string at a NUL byte,
 * so "LinkedIn\0junk" would find LinkedIn.
 */
final class JobPostingIntake
{
    #[ApiProperty(description: 'The platform\'s name exactly as listed in the app, e.g. "LinkedIn".')]
    #[Assert\NotBlank(normalizer: [Text::class, 'trim'])]
    #[Assert\Length(max: Platform::MAX_NAME_LENGTH)]
    #[Assert\Regex('/\x00/', match: false)]
    public string $platform;

    #[Assert\NotBlank(normalizer: [Text::class, 'trim'])]
    #[Assert\Length(max: JobPostingDetails::MAX_LINE_LENGTH, normalizer: [Text::class, 'trim'])]
    public string $title;

    #[Assert\NotBlank(normalizer: [Text::class, 'trim'])]
    public string $description;

    #[ApiProperty(description: 'An absolute http(s) URL, with non-ASCII characters percent-encoded.')]
    #[Assert\NotBlank(normalizer: [Text::class, 'trim'])]
    #[Assert\Length(
        max: JobPostingDetails::MAX_URL_LENGTH,
        countUnit: Assert\Length::COUNT_BYTES,
        normalizer: [Text::class, 'trim'],
    )]
    #[Assert\Url(protocols: ['http', 'https'], requireTld: false, normalizer: [Text::class, 'trim'])]
    public string $url;

    #[Assert\Length(max: JobPostingDetails::MAX_LINE_LENGTH, normalizer: [Text::class, 'trim'])]
    public ?string $company = null;

    #[Assert\Length(max: JobPostingDetails::MAX_LINE_LENGTH, normalizer: [Text::class, 'trim'])]
    public ?string $location = null;
}
