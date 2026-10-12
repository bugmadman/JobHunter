<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CvProfile;

interface CvProfileRepositoryInterface
{
    public function findById(int $id): ?CvProfile;

    public function save(CvProfile $cvProfile): void;
}
