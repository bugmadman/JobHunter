<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CoverLetter;
use App\Entity\JobPosting;

interface CoverLetterRepositoryInterface
{
    public function findById(int $id): ?CoverLetter;

    public function findLatestByJobPosting(JobPosting $posting): ?CoverLetter;

    public function save(CoverLetter $coverLetter): void;
}
