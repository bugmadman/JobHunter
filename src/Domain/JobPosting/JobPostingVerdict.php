<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

enum JobPostingVerdict: string
{
    case Like = 'like';
    case Dislike = 'dislike';
}
