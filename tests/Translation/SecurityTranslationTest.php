<?php

declare(strict_types=1);

namespace App\Tests\Translation;

use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SecurityTranslationTest extends KernelTestCase
{
    #[TestWith([2, '2 минуты'])]
    #[TestWith([5, '5 минут'])]
    #[TestWith([15, '15 минут'])]
    public function testThrottlingMessageIsPluralizedInRussian(int $minutes, string $retryIn): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $message = $translator->trans(
            'Too many failed login attempts, please try again in %minutes% minutes.',
            ['%minutes%' => $minutes],
            'security',
            'ru',
        );

        self::assertSame(
            \sprintf('Слишком много неудачных попыток входа, повторите попытку через %s.', $retryIn),
            $message,
        );
    }
}
