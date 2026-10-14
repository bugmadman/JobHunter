<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JobPosting;

interface JobPostingRepositoryInterface
{
    public function findById(int $id): ?JobPosting;

    /**
     * @return list<JobPosting>
     */
    public function findAllNewestFirst(): array;

    public function save(JobPosting $posting): void;
}
