<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JobPosting;

interface JobPostingRepositoryInterface
{
    public function findById(int $id): ?JobPosting;

    public function save(JobPosting $posting): void;
}
