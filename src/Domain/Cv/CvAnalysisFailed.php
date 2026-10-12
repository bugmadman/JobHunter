<?php

declare(strict_types=1);

namespace App\Domain\Cv;

/**
 * A failure that a retry won't fix: the profile is already marked failed when this is thrown.
 */
final class CvAnalysisFailed extends \RuntimeException
{
    public static function because(\Throwable $previous): self
    {
        return new self('The CV analysis failed: '.$previous->getMessage(), 0, $previous);
    }
}
