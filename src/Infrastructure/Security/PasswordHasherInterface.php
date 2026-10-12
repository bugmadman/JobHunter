<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\User\PlainPassword;

interface PasswordHasherInterface
{
    public function hash(PlainPassword $password): string;

    /**
     * A raw string, not a PlainPassword: a submitted password may be whitespace-only, which PlainPassword rejects,
     * and skipping its verification would make an unknown email answer faster than a wrong password.
     */
    public function verify(#[\SensitiveParameter] string $hash, #[\SensitiveParameter] string $password): bool;

    public function needsRehash(#[\SensitiveParameter] string $hash): bool;
}
