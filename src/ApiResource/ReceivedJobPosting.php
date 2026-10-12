<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Domain\JobPosting\JobPostingStatus;
use App\Entity\JobPosting;
use App\State\JobPostingIntakeProcessor;

#[ApiResource(
    shortName: 'JobPosting',
    operations: [
        new Post(
            uriTemplate: '/job-postings',
            formats: ['json' => ['application/json']],
            openapi: new Operation(
                summary: 'Sends a job posting into the processing pipeline.',
                description: 'One posting per request. The posting is saved with the status "raw".',
            ),
            input: JobPostingIntake::class,
            collectDenormalizationErrors: true,
            processor: JobPostingIntakeProcessor::class,
        ),
    ],
)]
final readonly class ReceivedJobPosting
{
    private function __construct(
        #[ApiProperty(identifier: true)]
        public int $id,
        public JobPostingStatus $status,
        public string $platform,
    ) {
    }

    public static function fromJobPosting(JobPosting $posting): self
    {
        return new self(
            $posting->getId() ?? throw new \LogicException('A saved job posting has an id.'),
            $posting->getStatus(),
            $posting->getPlatform()->getName(),
        );
    }
}
