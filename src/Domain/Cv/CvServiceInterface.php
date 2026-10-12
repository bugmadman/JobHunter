<?php

declare(strict_types=1);

namespace App\Domain\Cv;

use App\Entity\CvProfile;

interface CvServiceInterface
{
    /**
     * Every upload is a new profile: the latest one is the current CV.
     *
     * @throws InvalidCvFile
     */
    public function upload(CvUpload $upload): CvProfile;
}
