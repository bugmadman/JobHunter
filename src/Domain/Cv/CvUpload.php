<?php

declare(strict_types=1);

namespace App\Domain\Cv;

final readonly class CvUpload
{
    /**
     * @param string $path         the temporary file PHP received the upload into
     * @param string $originalName the file name the browser sent, as is
     */
    public function __construct(
        public string $path,
        public string $originalName,
    ) {
    }
}
