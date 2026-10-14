<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Domain\CoverLetter\CoverLetterGenerationAlreadyFinished;
use App\Domain\CoverLetter\CoverLetterGenerationFailed;
use App\Domain\CoverLetter\CoverLetterServiceInterface;
use App\Domain\JobPosting\JobPostingDetails;
use App\Domain\Llm\LlmOperationStatus;
use App\Domain\Llm\LlmProvider;
use App\Domain\Platform\PlatformAccessType;
use App\Entity\CoverLetter;
use App\Entity\JobPosting;
use App\Entity\Platform;
use App\Infrastructure\Llm\LlmRequestFailed;
use App\Message\GenerateCoverLetter;
use App\MessageHandler\GenerateCoverLetterHandler;
use App\Repository\CoverLetterRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class GenerateCoverLetterHandlerTest extends TestCase
{
    private const int ID = 7;

    public function testGeneratesTheLetterLoadedById(): void
    {
        $coverLetter = self::coverLetter();

        $coverLetterService = $this->createMock(CoverLetterServiceInterface::class);
        $coverLetterService->expects(self::once())->method('generate')->with($coverLetter);

        self::handler(self::repositoryFinding($coverLetter), $coverLetterService)(new GenerateCoverLetter(self::ID));
    }

    public function testAMissingLetterIsNotRetried(): void
    {
        $coverLetterService = $this->createMock(CoverLetterServiceInterface::class);
        $coverLetterService->expects(self::never())->method('generate');

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('The cover letter 7 does not exist.');

        self::handler(self::repositoryFinding(null), $coverLetterService)(new GenerateCoverLetter(self::ID));
    }

    public function testAFailedGenerationIsNotRetried(): void
    {
        $failure = CoverLetterGenerationFailed::because(new \RuntimeException('no model selected'));

        $coverLetterService = self::createStub(CoverLetterServiceInterface::class);
        $coverLetterService->method('generate')->willThrowException($failure);

        try {
            self::handler(self::repositoryFinding(self::coverLetter()), $coverLetterService)(
                new GenerateCoverLetter(self::ID),
            );
            self::fail('Expected the failed generation not to be retried.');
        } catch (UnrecoverableMessageHandlingException $exception) {
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public function testAFailedLlmRequestPropagatesForARetry(): void
    {
        $failure = LlmRequestFailed::create(LlmProvider::Gemini, 'HTTP 503');

        $coverLetterService = self::createStub(CoverLetterServiceInterface::class);
        $coverLetterService->method('generate')->willThrowException($failure);

        try {
            self::handler(self::repositoryFinding(self::coverLetter()), $coverLetterService)(
                new GenerateCoverLetter(self::ID),
            );
            self::fail('Expected the failed LLM request to propagate.');
        } catch (LlmRequestFailed $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function testAnAlreadyFinishedGenerationIsAcknowledged(): void
    {
        $coverLetterService = $this->createMock(CoverLetterServiceInterface::class);
        $coverLetterService->expects(self::once())
            ->method('generate')
            ->willThrowException(CoverLetterGenerationAlreadyFinished::withStatus(LlmOperationStatus::Done));

        self::handler(self::repositoryFinding(self::coverLetter()), $coverLetterService)(
            new GenerateCoverLetter(self::ID),
        );
    }

    private static function handler(
        CoverLetterRepositoryInterface $coverLetters,
        CoverLetterServiceInterface $coverLetterService,
    ): GenerateCoverLetterHandler {
        return new GenerateCoverLetterHandler($coverLetters, $coverLetterService);
    }

    private function repositoryFinding(?CoverLetter $coverLetter): CoverLetterRepositoryInterface
    {
        $coverLetters = $this->createMock(CoverLetterRepositoryInterface::class);
        $coverLetters->expects(self::once())->method('findById')->with(self::ID)->willReturn($coverLetter);

        return $coverLetters;
    }

    private static function coverLetter(): CoverLetter
    {
        return new CoverLetter(
            new JobPosting(
                new Platform('Upwork', PlatformAccessType::Browser),
                JobPostingDetails::create('PHP Developer', 'Symfony.', 'https://www.upwork.com/jobs/1'),
                new \DateTimeImmutable('2026-10-14 09:00'),
            ),
            new \DateTimeImmutable('2026-10-14 12:00'),
        );
    }
}
