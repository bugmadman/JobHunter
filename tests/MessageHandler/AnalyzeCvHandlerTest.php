<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Domain\Cv\CvAnalysisAlreadyFinished;
use App\Domain\Cv\CvAnalysisFailed;
use App\Domain\Cv\CvFile;
use App\Domain\Cv\CvServiceInterface;
use App\Domain\Llm\LlmOperationStatus;
use App\Domain\Llm\LlmProvider;
use App\Entity\CvProfile;
use App\Infrastructure\Llm\LlmRequestFailed;
use App\Message\AnalyzeCv;
use App\MessageHandler\AnalyzeCvHandler;
use App\Repository\CvProfileRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class AnalyzeCvHandlerTest extends TestCase
{
    private const int ID = 7;

    public function testAnalyzesTheProfileLoadedById(): void
    {
        $profile = self::profile();

        $cvService = $this->createMock(CvServiceInterface::class);
        $cvService->expects(self::once())->method('analyze')->with($profile);

        self::handler(self::repositoryFinding($profile), $cvService)(new AnalyzeCv(self::ID));
    }

    public function testAMissingProfileIsNotRetried(): void
    {
        $cvService = $this->createMock(CvServiceInterface::class);
        $cvService->expects(self::never())->method('analyze');

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('The CV profile 7 does not exist.');

        self::handler(self::repositoryFinding(null), $cvService)(new AnalyzeCv(self::ID));
    }

    public function testAFailedAnalysisIsNotRetried(): void
    {
        $failure = CvAnalysisFailed::because(new \RuntimeException('invalid response'));

        $cvService = self::createStub(CvServiceInterface::class);
        $cvService->method('analyze')->willThrowException($failure);

        try {
            self::handler(self::repositoryFinding(self::profile()), $cvService)(new AnalyzeCv(self::ID));
            self::fail('Expected the failed analysis not to be retried.');
        } catch (UnrecoverableMessageHandlingException $exception) {
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public function testAFailedLlmRequestPropagatesForARetry(): void
    {
        $failure = LlmRequestFailed::create(LlmProvider::Gemini, 'HTTP 503');

        $cvService = self::createStub(CvServiceInterface::class);
        $cvService->method('analyze')->willThrowException($failure);

        try {
            self::handler(self::repositoryFinding(self::profile()), $cvService)(new AnalyzeCv(self::ID));
            self::fail('Expected the failed LLM request to propagate.');
        } catch (LlmRequestFailed $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function testAnAlreadyFinishedAnalysisIsAcknowledged(): void
    {
        $cvService = $this->createMock(CvServiceInterface::class);
        $cvService->expects(self::once())
            ->method('analyze')
            ->willThrowException(CvAnalysisAlreadyFinished::withStatus(LlmOperationStatus::Done));

        self::handler(self::repositoryFinding(self::profile()), $cvService)(new AnalyzeCv(self::ID));
    }

    private static function handler(
        CvProfileRepositoryInterface $cvProfiles,
        CvServiceInterface $cvService,
    ): AnalyzeCvHandler {
        return new AnalyzeCvHandler($cvProfiles, $cvService);
    }

    private function repositoryFinding(?CvProfile $profile): CvProfileRepositoryInterface
    {
        $cvProfiles = $this->createMock(CvProfileRepositoryInterface::class);
        $cvProfiles->expects(self::once())->method('findById')->with(self::ID)->willReturn($profile);

        return $cvProfiles;
    }

    private static function profile(): CvProfile
    {
        return new CvProfile(CvFile::create('3f9a1c.pdf', 'My CV.pdf'));
    }
}
