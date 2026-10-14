<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\JobPosting\JobPostingAlreadyFiltered;
use App\Domain\JobPosting\JobPostingServiceInterface;
use App\Message\ProcessJobPosting;
use App\Repository\JobPostingRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final readonly class ProcessJobPostingHandler
{
    public function __construct(
        private JobPostingRepositoryInterface $postings,
        private JobPostingServiceInterface $postingService,
    ) {
    }

    public function __invoke(ProcessJobPosting $message): void
    {
        $id = $message->jobPostingId;
        $posting = $this->postings->findById($id);
        if (null === $posting) {
            throw new UnrecoverableMessageHandlingException(\sprintf('The job posting %d does not exist.', $id));
        }

        try {
            $this->postingService->filter($posting);
        } catch (JobPostingAlreadyFiltered) {
            return;
        }
    }
}
