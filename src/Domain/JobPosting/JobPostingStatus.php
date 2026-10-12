<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

enum JobPostingStatus: string
{
    case Raw = 'raw';
    case Rejected = 'rejected';
    case Filtered = 'filtered';
    case Evaluated = 'evaluated';
    case Failed = 'failed';
}
