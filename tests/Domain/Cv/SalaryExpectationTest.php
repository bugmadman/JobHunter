<?php

declare(strict_types=1);

namespace App\Tests\Domain\Cv;

use App\Domain\Cv\InvalidSalaryExpectation;
use App\Domain\Cv\SalaryExpectation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SalaryExpectationTest extends TestCase
{
    public function testCurrencyIsNormalized(): void
    {
        $salary = SalaryExpectation::create(4000, 6000, ' usd ');

        self::assertSame(4000, $salary->min);
        self::assertSame(6000, $salary->max);
        self::assertSame('USD', $salary->currency);
    }

    public function testOneBoundIsEnough(): void
    {
        self::assertNull(SalaryExpectation::create(5000, null, 'EUR')->max);
        self::assertNull(SalaryExpectation::create(null, 5000, 'EUR')->min);
    }

    public function testEqualBoundsAreAccepted(): void
    {
        $salary = SalaryExpectation::create(5000, 5000, 'EUR');

        self::assertSame($salary->min, $salary->max);
    }

    #[DataProvider('invalidSalaries')]
    public function testInvalidSalaryIsRejected(?int $min, ?int $max, string $currency): void
    {
        $this->expectException(InvalidSalaryExpectation::class);

        SalaryExpectation::create($min, $max, $currency);
    }

    /**
     * @return iterable<string, array{?int, ?int, string}>
     */
    public static function invalidSalaries(): iterable
    {
        yield 'no amount' => [null, null, 'USD'];
        yield 'zero minimum' => [0, 5000, 'USD'];
        yield 'negative maximum' => [null, -1, 'USD'];
        yield 'minimum above maximum' => [6000, 5000, 'USD'];
        yield 'empty currency' => [5000, null, ''];
        yield 'currency symbol' => [5000, null, '$'];
        yield 'two letters' => [5000, null, 'US'];
        yield 'four letters' => [5000, null, 'USDT'];
    }
}
