<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

use App\Entity\JobPosting;
use App\Entity\Platform;
use App\Repository\JobPostingRepositoryInterface;
use Psr\Clock\ClockInterface;

final readonly class JobPostingService implements JobPostingServiceInterface
{
    public function __construct(
        private JobPostingRepositoryInterface $postings,
        private ClockInterface $clock,
    ) {
    }

    public function create(Platform $platform, JobPostingDetails $details): JobPosting
    {
        $posting = new JobPosting($platform, $details, $this->clock->now());
        $this->postings->save($posting);

        return $posting;
    }
}
