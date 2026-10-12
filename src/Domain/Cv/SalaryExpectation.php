<?php

declare(strict_types=1);

namespace App\Domain\Cv;

final readonly class SalaryExpectation
{
    public const int MAX_AMOUNT = 2_000_000_000;

    /**
     * @param positive-int|null $min
     * @param positive-int|null $max
     * @param non-empty-string  $currency
     */
    private function __construct(
        public ?int $min,
        public ?int $max,
        public string $currency,
    ) {
    }

    public static function create(?int $min, ?int $max, string $currency): self
    {
        if (null === $min && null === $max) {
            throw InvalidSalaryExpectation::noAmount();
        }

        if ((null !== $min && $min <= 0) || (null !== $max && $max <= 0)) {
            throw InvalidSalaryExpectation::nonPositiveAmount();
        }

        if ((null !== $min && $min > self::MAX_AMOUNT) || (null !== $max && $max > self::MAX_AMOUNT)) {
            throw InvalidSalaryExpectation::amountTooLarge();
        }

        if (null !== $min && null !== $max && $min > $max) {
            throw InvalidSalaryExpectation::minAboveMax($min, $max);
        }

        $normalized = strtoupper(trim($currency));
        if (1 !== preg_match('/^[A-Z]{3}$/', $normalized)) {
            throw InvalidSalaryExpectation::invalidCurrency($currency);
        }

        return new self($min, $max, $normalized);
    }
}
