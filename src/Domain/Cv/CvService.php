<?php

declare(strict_types=1);

namespace App\Domain\Cv;

use App\Entity\CvProfile;
use App\Infrastructure\Storage\CvStorageInterface;
use App\Repository\CvProfileRepositoryInterface;

final readonly class CvService implements CvServiceInterface
{
    public function __construct(
        private CvProfileRepositoryInterface $cvProfiles,
        private CvStorageInterface $storage,
    ) {
    }

    public function upload(CvUpload $upload): CvProfile
    {
        $file = CvFile::create(bin2hex(random_bytes(16)).'.pdf', $upload->originalName);
        $this->storage->store($upload, $file);

        $cvProfile = new CvProfile($file);
        $this->cvProfiles->save($cvProfile);

        return $cvProfile;
    }
}
