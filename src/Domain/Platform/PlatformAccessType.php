<?php

declare(strict_types=1);

namespace App\Domain\Platform;

enum PlatformAccessType: string
{
    case Api = 'api';
    case Rss = 'rss';
    case Browser = 'browser';
}
