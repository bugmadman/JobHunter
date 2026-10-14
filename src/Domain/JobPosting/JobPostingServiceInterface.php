<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

use App\Entity\JobPosting;
use App\Entity\Platform;

interface JobPostingServiceInterface
{
    /**
     * Saves a new posting from the platform as raw, received now.
     */
    public function create(Platform $platform, JobPostingDetails $details): JobPosting;

    /**
     * Runs a raw posting through the keyword filter against the skills of the latest analysed CV: filtered when it
     * names at least one of them, rejected when it names none, failed for good when there are no CV skills to match.
     *
     * @throws JobPostingAlreadyFiltered when the posting is no longer raw (a redelivered message)
     */
    public function filter(JobPosting $posting): void;
}
