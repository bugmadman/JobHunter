<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JobPosting;

interface JobPostingRepositoryInterface
{
    public function save(JobPosting $posting): void;
}
