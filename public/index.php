<?php

declare(strict_types=1);

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    // SymfonyRuntime always fills it from .env; the assert narrows mixed for PHPStan
    assert(is_string($context['APP_ENV']));

    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
