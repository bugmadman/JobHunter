<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Domain\JobPosting\JobPostingDetails;
use App\Domain\JobPosting\JobPostingServiceInterface;
use App\Domain\Llm\LlmProvider;
use App\Domain\Platform\PlatformAccessType;
use App\Entity\JobPosting;
use App\Entity\Platform;
use App\Infrastructure\Llm\LlmRequestFailed;
use App\Message\ProcessJobPosting;
use App\MessageHandler\ProcessJobPostingHandler;
use App\Repository\JobPostingRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class ProcessJobPostingHandlerTest extends TestCase
{
    private const int ID = 7;

    public function testProcessesThePostingLoadedById(): void
    {
        $posting = self::posting();

        $postingService = $this->createMock(JobPostingServiceInterface::class);
        $postingService->expects(self::once())->method('process')->with($posting);

        self::handler($this->repositoryFinding($posting), $postingService)(new ProcessJobPosting(self::ID));
    }

    public function testAMissingPostingIsNotRetried(): void
    {
        $postingService = $this->createMock(JobPostingServiceInterface::class);
        $postingService->expects(self::never())->method('process');

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('The job posting 7 does not exist.');

        self::handler($this->repositoryFinding(null), $postingService)(new ProcessJobPosting(self::ID));
    }

    public function testAFailedLlmRequestPropagatesForARetry(): void
    {
        $failure = LlmRequestFailed::create(LlmProvider::Gemini, 'HTTP 503');
        $postingService = self::createStub(JobPostingServiceInterface::class);
        $postingService->method('process')->willThrowException($failure);

        $this->expectExceptionObject($failure);

        self::handler($this->repositoryFinding(self::posting()), $postingService)(new ProcessJobPosting(self::ID));
    }

    private static function handler(
        JobPostingRepositoryInterface $postings,
        JobPostingServiceInterface $postingService,
    ): ProcessJobPostingHandler {
        return new ProcessJobPostingHandler($postings, $postingService);
    }

    private function repositoryFinding(?JobPosting $posting): JobPostingRepositoryInterface
    {
        $postings = $this->createMock(JobPostingRepositoryInterface::class);
        $postings->expects(self::once())->method('findById')->with(self::ID)->willReturn($posting);

        return $postings;
    }

    private static function posting(): JobPosting
    {
        return new JobPosting(
            new Platform('LinkedIn', PlatformAccessType::Browser),
            JobPostingDetails::create('PHP Developer', 'Symfony.', 'https://www.linkedin.com/jobs/view/1/'),
            new \DateTimeImmutable('2026-10-12 09:30:00'),
        );
    }
}
