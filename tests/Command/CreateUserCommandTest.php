<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Domain\Shared\Email;
use App\Domain\User\PlainPassword;
use App\Domain\User\UserAlreadyExists;
use App\Domain\User\UserServiceInterface;
use App\Entity\User;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateUserCommandTest extends KernelTestCase
{
    public function testCreatesTheUser(): void
    {
        $userService = $this->createMock(UserServiceInterface::class);
        $userService->expects(self::once())
            ->method('create')
            ->with(
                self::callback(static fn (Email $email): bool => 'owner@example.com' === $email->value),
                self::callback(static fn (PlainPassword $password): bool => 'secret' === $password->value()),
            )
            ->willReturn(new User(Email::fromString('owner@example.com'), 'hash'));

        $tester = $this->commandTester($userService, 'secret');

        self::assertSame(Command::SUCCESS, $tester->execute(['email' => 'Owner@Example.com']));
        self::assertStringContainsString('User owner@example.com created.', $tester->getDisplay());
    }

    public function testShowsTheErrorWhenTheUserExists(): void
    {
        $userService = self::createStub(UserServiceInterface::class);
        $userService->method('create')->willThrowException(UserAlreadyExists::create());

        $tester = $this->commandTester($userService, 'secret');

        self::assertSame(Command::FAILURE, $tester->execute(['email' => 'owner@example.com']));
        self::assertStringContainsString('The user already exists', $tester->getDisplay());
    }

    #[TestWith(['not-an-email', 'secret', 'is not a valid email address'])]
    #[TestWith(['owner@example.com', '', 'The password must not be empty'])]
    public function testRejectsInvalidInputWithoutCallingTheService(
        string $email,
        string $password,
        string $error,
    ): void {
        $userService = $this->createMock(UserServiceInterface::class);
        $userService->expects(self::never())->method('create');

        $tester = $this->commandTester($userService, $password);

        self::assertSame(Command::FAILURE, $tester->execute(['email' => $email]));
        self::assertStringContainsString($error, $tester->getDisplay());
    }

    private function commandTester(UserServiceInterface $userService, string $password): CommandTester
    {
        $kernel = self::bootKernel();
        self::getContainer()->set(UserServiceInterface::class, $userService);

        $tester = new CommandTester(new Application($kernel)->find('app:user:create'));
        $tester->setInputs([$password]);

        return $tester;
    }
}
