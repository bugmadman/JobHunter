<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Shared\Email;
use App\Domain\Shared\InvalidEmail;
use App\Domain\User\EmptyPassword;
use App\Domain\User\PlainPassword;
use App\Domain\User\UserAlreadyExists;
use App\Domain\User\UserServiceInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Ask;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:create', description: 'Create the user account')]
final readonly class CreateUserCommand
{
    public function __construct(
        private UserServiceInterface $userService,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The email to log in with'), Ask('Email')]
        string $email,
    ): int {
        $password = $io->askHidden('Password');

        try {
            $user = $this->userService->create(
                Email::fromString($email),
                PlainPassword::fromString(\is_string($password) ? $password : ''),
            );
        } catch (UserAlreadyExists|InvalidEmail|EmptyPassword $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('User %s created.', $user->getUserIdentifier()));

        return Command::SUCCESS;
    }
}
