<?php

declare(strict_types=1);

namespace App\Domain\Shared;

final class Text
{
    /**
     * trim() misses Unicode spaces and invisible characters, so a lone NBSP would pass as text.
     */
    public static function trim(string $text): string
    {
        return (string) preg_replace('/^[\pZ\p{Cc}\p{Cf}]+|[\pZ\p{Cc}\p{Cf}]+$/u', '', $text);
    }
}
