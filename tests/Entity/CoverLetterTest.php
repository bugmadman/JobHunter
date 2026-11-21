<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Domain\CoverLetter\CoverLetterGenerationAlreadyFinished;
use App\Domain\CoverLetter\CoverLetterNotGenerated;
use App\Domain\CoverLetter\CoverLetterText;
use App\Domain\JobPosting\JobPostingDetails;
use App\Domain\Llm\LlmFailure;
use App\Domain\Llm\LlmFailureCause;
use App\Domain\Llm\LlmOperationStatus;
use App\Domain\Platform\PlatformAccessType;
use App\Entity\CoverLetter;
use App\Entity\JobPosting;
use App\Entity\Platform;
use PHPUnit\Framework\TestCase;

final class CoverLetterTest extends TestCase
{
    public function testNewLetterIsPendingWithoutText(): void
    {
        $posting = self::posting();
        $createdAt = new \DateTimeImmutable('2026-10-14 12:00');

        $coverLetter = new CoverLetter($posting, $createdAt);

        self::assertSame($posting, $coverLetter->getJobPosting());
        self::assertSame($createdAt, $coverLetter->getCreatedAt());
        self::assertSame(LlmOperationStatus::Pending, $coverLetter->getStatus());
        self::assertNull($coverLetter->getText());
        self::assertNull($coverLetter->getFailure());
    }

    public function testGeneratedLetterIsDoneWithTheText(): void
    {
        $coverLetter = self::coverLetter();

        $coverLetter->markGenerated(CoverLetterText::fromString('I fit.'));

        self::assertSame(LlmOperationStatus::Done, $coverLetter->getStatus());
        self::assertSame('I fit.', $coverLetter->getText());
        self::assertNull($coverLetter->getFailure());
    }

    public function testFailedLetterKeepsTheCauseAndHasNoText(): void
    {
        $coverLetter = self::coverLetter();
        $failure = LlmFailure::create(LlmFailureCause::Provider, 'The gemini request failed: HTTP 403');

        $coverLetter->markGenerationFailed($failure);

        self::assertSame(LlmOperationStatus::Failed, $coverLetter->getStatus());
        self::assertEquals($failure, $coverLetter->getFailure());
        self::assertNull($coverLetter->getText());
    }

    public function testAGeneratedLetterIsNotGeneratedAgain(): void
    {
        $coverLetter = self::coverLetter();
        $coverLetter->markGenerated(CoverLetterText::fromString('I fit.'));

        $this->expectException(CoverLetterGenerationAlreadyFinished::class);

        $coverLetter->markGenerated(CoverLetterText::fromString('Another.'));
    }

    public function testAGeneratedLetterCannotFail(): void
    {
        $coverLetter = self::coverLetter();
        $coverLetter->markGenerated(CoverLetterText::fromString('I fit.'));

        try {
            $coverLetter->markGenerationFailed(self::failure());
            self::fail('Expected a finished generation to stay as it is.');
        } catch (CoverLetterGenerationAlreadyFinished) {
        }

        self::assertSame(LlmOperationStatus::Done, $coverLetter->getStatus());
        self::assertNull($coverLetter->getFailure());
    }

    public function testAFailedLetterIsNotGeneratedLater(): void
    {
        $coverLetter = self::coverLetter();
        $coverLetter->markGenerationFailed(self::failure());

        $this->expectException(CoverLetterGenerationAlreadyFinished::class);

        $coverLetter->markGenerated(CoverLetterText::fromString('I fit.'));
    }

    public function testAFailedLetterKeepsItsFirstCause(): void
    {
        $coverLetter = self::coverLetter();
        $coverLetter->markGenerationFailed(LlmFailure::create(LlmFailureCause::EmptyResponse));

        try {
            $coverLetter->markGenerationFailed(self::failure());
            self::fail('A failed generation was marked failed again.');
        } catch (CoverLetterGenerationAlreadyFinished) {
        }

        self::assertEquals(LlmFailure::create(LlmFailureCause::EmptyResponse), $coverLetter->getFailure());
    }

    public function testEditingReplacesTheGeneratedText(): void
    {
        $coverLetter = self::coverLetter();
        $coverLetter->markGenerated(CoverLetterText::fromString('I fit.'));

        $coverLetter->edit(CoverLetterText::fromString('I fit well.'));

        self::assertSame(LlmOperationStatus::Done, $coverLetter->getStatus());
        self::assertSame('I fit well.', $coverLetter->getText());
    }

    public function testAPendingLetterCannotBeEdited(): void
    {
        $coverLetter = self::coverLetter();

        try {
            $coverLetter->edit(CoverLetterText::fromString('I fit.'));
            self::fail('Expected a pending letter to refuse an edit.');
        } catch (CoverLetterNotGenerated) {
        }

        self::assertNull($coverLetter->getText());
    }

    public function testAFailedLetterCannotBeEdited(): void
    {
        $coverLetter = self::coverLetter();
        $coverLetter->markGenerationFailed(self::failure());

        try {
            $coverLetter->edit(CoverLetterText::fromString('I fit.'));
            self::fail('Expected a failed letter to refuse an edit.');
        } catch (CoverLetterNotGenerated) {
        }

        self::assertNull($coverLetter->getText());
        self::assertEquals(self::failure(), $coverLetter->getFailure());
    }

    private static function failure(): LlmFailure
    {
        return LlmFailure::create(LlmFailureCause::Unexpected);
    }

    private static function coverLetter(): CoverLetter
    {
        return new CoverLetter(self::posting(), new \DateTimeImmutable('2026-10-14 12:00'));
    }

    private static function posting(): JobPosting
    {
        return new JobPosting(
            new Platform('Upwork', PlatformAccessType::Browser),
            JobPostingDetails::create('PHP Developer', 'Symfony.', 'https://www.upwork.com/jobs/1'),
            new \DateTimeImmutable('2026-10-14 09:00'),
        );
    }
}
