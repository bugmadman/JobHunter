<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\Cv\CvFile;
use App\Domain\Cv\CvUpload;
use Symfony\Component\Filesystem\Exception\IOException;

interface CvStorageInterface
{
    /**
     * Moves the uploaded file into the CV storage under the stored name.
     *
     * @throws IOException
     */
    public function store(CvUpload $upload, CvFile $file): void;

    /**
     * @throws CvFileUnreadable
     */
    public function read(CvFile $file): string;
}
