<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\User\PlainPassword;

interface PasswordHasherInterface
{
    public function hash(PlainPassword $password): string;
}
