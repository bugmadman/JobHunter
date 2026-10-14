<?php

declare(strict_types=1);

namespace App\Domain\CoverLetter;

/**
 * A failure that a retry won't fix: the generation is already marked failed when this is thrown.
 */
final class CoverLetterGenerationFailed extends \RuntimeException
{
    public static function because(\Throwable $previous): self
    {
        return new self('The cover letter generation failed: '.$previous->getMessage(), 0, $previous);
    }

    public static function withoutAnalysedCvProfile(): self
    {
        return new self('The cover letter generation failed: there is no CV profile with a finished analysis.');
    }
}
