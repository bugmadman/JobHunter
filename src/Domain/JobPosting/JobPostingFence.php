<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

/**
 * Puts a job posting, which is untrusted, between tags an LLM instruction can point to.
 */
final class JobPostingFence
{
    public const string TAG = 'job_posting';

    /**
     * @return non-empty-string
     */
    public static function wrap(JobPostingDetails $details): string
    {
        $lines = ['<'.self::TAG.'>', 'Title: '.self::defuse($details->title)];

        if (null !== $details->company) {
            $lines[] = 'Company: '.self::defuse($details->company);
        }

        if (null !== $details->location) {
            $lines[] = 'Location: '.self::defuse($details->location);
        }

        $lines[] = 'Description:';
        $lines[] = self::defuse($details->description);
        $lines[] = '</'.self::TAG.'>';

        return implode("\n", $lines);
    }

    /**
     * A posting could close the fence itself and put its own instructions outside it.
     */
    private static function defuse(string $text): string
    {
        return (string) preg_replace('~<(?=\s*/?\s*'.self::TAG.')~i', '&lt;', $text);
    }
}
