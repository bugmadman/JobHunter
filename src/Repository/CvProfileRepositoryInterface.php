<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CvProfile;

interface CvProfileRepositoryInterface
{
    public function findById(int $id): ?CvProfile;

    /**
     * The CV, once its analysis is done (a pending or failed one is not usable).
     */
    public function findAnalysed(): ?CvProfile;

    /**
     * The CV, whatever its analysis status.
     */
    public function findCurrent(): ?CvProfile;

    public function save(CvProfile $cvProfile): void;

    /**
     * Removes the old profile and saves the new one in one flush, so either both happen or neither.
     */
    public function replace(CvProfile $old, CvProfile $new): void;
}
