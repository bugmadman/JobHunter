<?php

declare(strict_types=1);

namespace App\Domain\Cv;

final class InvalidSalaryExpectation extends \DomainException
{
    public static function noAmount(): self
    {
        return new self('A salary expectation needs a minimum or a maximum.');
    }

    public static function nonPositiveAmount(): self
    {
        return new self('A salary amount must be positive.');
    }

    public static function minAboveMax(int $min, int $max): self
    {
        return new self(\sprintf('The minimum salary %d is above the maximum %d.', $min, $max));
    }

    public static function invalidCurrency(string $currency): self
    {
        return new self(\sprintf('"%s" is not a three-letter currency code.', $currency));
    }
}
