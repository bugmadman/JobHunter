<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\Cv\CvFile;
use App\Domain\Cv\CvUpload;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final readonly class CvStorage implements CvStorageInterface
{
    public function __construct(
        #[Autowire(param: 'app.cv_storage_dir')]
        private string $directory,
        private Filesystem $filesystem,
    ) {
    }

    public function store(CvUpload $upload, CvFile $file): void
    {
        $this->filesystem->mkdir($this->directory, 0o700);
        $this->filesystem->rename($upload->path, $this->directory.'/'.$file->storedName);
    }
}
