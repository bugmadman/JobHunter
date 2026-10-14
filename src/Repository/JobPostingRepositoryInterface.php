<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\JobPosting\JobPostingListFilter;
use App\Entity\JobPosting;

interface JobPostingRepositoryInterface
{
    public function findById(int $id): ?JobPosting;

    /**
     * @return list<JobPosting>
     */
    public function findNewestFirst(JobPostingListFilter $filter): array;

    public function save(JobPosting $posting): void;
}
