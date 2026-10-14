<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

use App\Domain\Shared\Text;

/**
 * The owner's own note on a job posting.
 */
final readonly class JobPostingComment
{
    public const int MAX_LENGTH = 5000;

    /**
     * @param non-empty-string $value
     */
    private function __construct(
        public string $value,
    ) {
    }

    /**
     * Blank input means no comment, so clearing the field removes the comment.
     *
     * @throws CommentTooLong
     */
    public static function fromInput(string $text): ?self
    {
        $text = Text::trim($text);

        if ('' === $text) {
            return null;
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw CommentTooLong::create(mb_strlen($text));
        }

        return new self($text);
    }
}
