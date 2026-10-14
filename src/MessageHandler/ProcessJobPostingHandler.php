<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProcessJobPosting;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessJobPostingHandler
{
    public function __invoke(ProcessJobPosting $message): void
    {
    }
}
