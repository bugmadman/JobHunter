<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\JobPosting\JobPostingListFilter;
use App\Entity\JobPosting;
use App\Entity\JobPostingStageLog;

interface JobPostingRepositoryInterface
{
    public function findById(int $id): ?JobPosting;

    /**
     * @return list<JobPosting>
     */
    public function findNewestFirst(JobPostingListFilter $filter): array;

    /**
     * @return list<JobPostingStageLog>
     */
    public function findStageLogsOldestFirst(JobPosting $posting): array;

    public function save(JobPosting $posting): void;
}
