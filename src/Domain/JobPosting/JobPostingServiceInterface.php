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
}
