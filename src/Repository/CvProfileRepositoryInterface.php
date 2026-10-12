<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CvProfile;

interface CvProfileRepositoryInterface
{
    public function findById(int $id): ?CvProfile;

    /**
     * The newest profile whose analysis is done; a newer pending or failed upload is skipped.
     */
    public function findLatestAnalysed(): ?CvProfile;

    public function save(CvProfile $cvProfile): void;
}
