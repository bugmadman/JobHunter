<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

final class CvUploadData
{
    /**
     * The constraint's 10M is 10,000,000 bytes, just under PHP's upload_max_filesize of 10M (10 MiB):
     * PHP itself drops anything above its limit before validation runs.
     */
    #[Assert\NotBlank]
    #[Assert\File(maxSize: '10M', mimeTypes: ['application/pdf', 'application/x-pdf'])]
    public ?UploadedFile $file = null;
}
