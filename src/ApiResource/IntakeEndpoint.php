<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;

final readonly class IntakeEndpoint
{
    public function __construct(
        #[ApiProperty(description: 'The HTTP method of the intake request.', example: 'POST')]
        public string $method,
        #[ApiProperty(description: 'The absolute URL to send each collected job posting to, one posting per request.')]
        public string $url,
        #[ApiProperty(description: 'The absolute URL of the OpenAPI document that describes the intake request body.')]
        public string $docsUrl,
    ) {
    }
}
