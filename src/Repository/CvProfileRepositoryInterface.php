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

    /**
     * The newest profile, whatever its analysis status.
     */
    public function findLatest(): ?CvProfile;

    public function save(CvProfile $cvProfile): void;

    /**
     * Removes the old profile and saves the new one in one flush, so either both happen or neither.
     */
    public function replace(CvProfile $old, CvProfile $new): void;
}
