<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Domain\Cv\CvAnalysis;
use App\Domain\Cv\CvFile;
use App\Domain\Llm\LlmOperationStatus;
use App\Domain\Platform\PlatformAccessType;
use App\Domain\Prompt\CvProfileNotAnalysed;
use App\Domain\Prompt\PromptGenerationAlreadyFinished;
use App\Domain\Prompt\PromptGenerationInProgress;
use App\Domain\Prompt\PromptSource;
use App\Domain\Prompt\PromptText;
use App\Entity\CvProfile;
use App\Entity\Platform;
use App\Entity\Prompt;
use PHPUnit\Framework\TestCase;

final class PromptTest extends TestCase
{
    public function testNewPromptIsPendingWithoutText(): void
    {
        $platform = self::platform();
        $cvProfile = self::analysedProfile();

        $prompt = new Prompt($platform, $cvProfile);

        self::assertSame($platform, $prompt->getPlatform());
        self::assertSame($cvProfile, $prompt->getCvProfile());
        self::assertSame(LlmOperationStatus::Pending, $prompt->getGenerationStatus());
        self::assertNull($prompt->getText());
        self::assertNull($prompt->getSource());
    }

    public function testPendingCvProfileIsRejected(): void
    {
        $this->expectException(CvProfileNotAnalysed::class);

        new Prompt(self::platform(), new CvProfile(CvFile::create('3f9a1c.pdf', 'My CV.pdf')));
    }

    public function testFailedCvProfileIsRejected(): void
    {
        $cvProfile = new CvProfile(CvFile::create('3f9a1c.pdf', 'My CV.pdf'));
        $cvProfile->markAnalysisFailed();

        $this->expectException(CvProfileNotAnalysed::class);

        new Prompt(self::platform(), $cvProfile);
    }

    public function testGeneratedDraftBecomesTheText(): void
    {
        $prompt = self::pendingPrompt();

        $prompt->markGenerated(PromptText::fromString('Match PHP jobs.'));

        self::assertSame(LlmOperationStatus::Done, $prompt->getGenerationStatus());
        self::assertSame('Match PHP jobs.', $prompt->getText());
        self::assertSame(PromptSource::Generated, $prompt->getSource());
    }

    public function testFailedFirstGenerationLeavesNoText(): void
    {
        $prompt = self::pendingPrompt();

        $prompt->markGenerationFailed();

        self::assertSame(LlmOperationStatus::Failed, $prompt->getGenerationStatus());
        self::assertNull($prompt->getText());
        self::assertNull($prompt->getSource());
    }

    public function testFinishedGenerationCannotBeFinishedAgain(): void
    {
        $prompt = self::pendingPrompt();
        $prompt->markGenerated(PromptText::fromString('First draft.'));

        try {
            $prompt->markGenerated(PromptText::fromString('Second draft.'));
            self::fail('A done generation was marked done again.');
        } catch (PromptGenerationAlreadyFinished) {
            self::assertSame('First draft.', $prompt->getText());
        }

        $this->expectException(PromptGenerationAlreadyFinished::class);
        $prompt->markGenerationFailed();
    }

    public function testFailedGenerationCannotBeFinishedAgain(): void
    {
        $prompt = self::pendingPrompt();
        $prompt->markGenerationFailed();

        try {
            $prompt->markGenerated(PromptText::fromString('Late draft.'));
            self::fail('A failed generation was marked done.');
        } catch (PromptGenerationAlreadyFinished) {
            self::assertSame(LlmOperationStatus::Failed, $prompt->getGenerationStatus());
            self::assertNull($prompt->getText());
        }

        $this->expectException(PromptGenerationAlreadyFinished::class);
        $prompt->markGenerationFailed();
    }

    public function testRegenerationKeepsTheTextUntilTheDraftIsReady(): void
    {
        $prompt = self::generatedPrompt('Old draft.');
        $newCvProfile = self::analysedProfile();

        $prompt->requestGeneration($newCvProfile);

        self::assertSame(LlmOperationStatus::Pending, $prompt->getGenerationStatus());
        self::assertSame($newCvProfile, $prompt->getCvProfile());
        self::assertSame('Old draft.', $prompt->getText());
        self::assertSame(PromptSource::Generated, $prompt->getSource());

        $prompt->markGenerated(PromptText::fromString('New draft.'));

        self::assertSame('New draft.', $prompt->getText());
    }

    public function testFailedRegenerationKeepsTheEditedText(): void
    {
        $prompt = self::generatedPrompt('Draft.');
        $prompt->edit(PromptText::fromString('Edited.'));
        $prompt->requestGeneration(self::analysedProfile());

        $prompt->markGenerationFailed();

        self::assertSame(LlmOperationStatus::Failed, $prompt->getGenerationStatus());
        self::assertSame('Edited.', $prompt->getText());
        self::assertSame(PromptSource::Edited, $prompt->getSource());
    }

    public function testRegenerationReplacesTheEditedText(): void
    {
        $prompt = self::generatedPrompt('Draft.');
        $prompt->edit(PromptText::fromString('Edited.'));
        $prompt->requestGeneration(self::analysedProfile());

        $prompt->markGenerated(PromptText::fromString('New draft.'));

        self::assertSame('New draft.', $prompt->getText());
        self::assertSame(PromptSource::Generated, $prompt->getSource());
    }

    public function testGenerationCanBeRetriedAfterAFailure(): void
    {
        $prompt = self::pendingPrompt();
        $prompt->markGenerationFailed();

        $prompt->requestGeneration(self::analysedProfile());

        self::assertSame(LlmOperationStatus::Pending, $prompt->getGenerationStatus());
    }

    public function testGenerationCannotBeRequestedWhileGenerating(): void
    {
        $prompt = self::pendingPrompt();
        $cvProfile = $prompt->getCvProfile();

        try {
            $prompt->requestGeneration(self::analysedProfile());
            self::fail('A second generation was requested while one is pending.');
        } catch (PromptGenerationInProgress) {
            self::assertSame($cvProfile, $prompt->getCvProfile());
        }
    }

    public function testRegenerationFromAnUnanalysedCvProfileIsRejected(): void
    {
        $prompt = self::generatedPrompt('Draft.');
        $cvProfile = $prompt->getCvProfile();

        try {
            $prompt->requestGeneration(new CvProfile(CvFile::create('7b2e4d.pdf', 'New CV.pdf')));
            self::fail('A generation was requested from a pending CV profile.');
        } catch (CvProfileNotAnalysed) {
            self::assertSame(LlmOperationStatus::Done, $prompt->getGenerationStatus());
            self::assertSame($cvProfile, $prompt->getCvProfile());
        }
    }

    public function testEditingAGeneratedPrompt(): void
    {
        $prompt = self::generatedPrompt('Draft.');

        $prompt->edit(PromptText::fromString('Edited.'));

        self::assertSame('Edited.', $prompt->getText());
        self::assertSame(PromptSource::Edited, $prompt->getSource());
        self::assertSame(LlmOperationStatus::Done, $prompt->getGenerationStatus());
    }

    public function testWritingAPromptByHandAfterAFailedGeneration(): void
    {
        $prompt = self::pendingPrompt();
        $prompt->markGenerationFailed();

        $prompt->edit(PromptText::fromString('Written by hand.'));

        self::assertSame('Written by hand.', $prompt->getText());
        self::assertSame(PromptSource::Edited, $prompt->getSource());
        self::assertSame(LlmOperationStatus::Failed, $prompt->getGenerationStatus());
    }

    public function testEditingIsRejectedWhileGenerating(): void
    {
        $prompt = self::generatedPrompt('Draft.');
        $prompt->requestGeneration(self::analysedProfile());

        try {
            $prompt->edit(PromptText::fromString('Edited.'));
            self::fail('A prompt was edited while generating.');
        } catch (PromptGenerationInProgress) {
            self::assertSame('Draft.', $prompt->getText());
            self::assertSame(PromptSource::Generated, $prompt->getSource());
        }
    }

    public function testPendingGenerationPassesTheCheck(): void
    {
        $this->expectNotToPerformAssertions();

        self::pendingPrompt()->assertGenerationPending();
    }

    public function testFinishedGenerationFailsTheCheck(): void
    {
        $this->expectException(PromptGenerationAlreadyFinished::class);

        self::generatedPrompt('Draft.')->assertGenerationPending();
    }

    private static function pendingPrompt(): Prompt
    {
        return new Prompt(self::platform(), self::analysedProfile());
    }

    private static function generatedPrompt(string $text): Prompt
    {
        $prompt = self::pendingPrompt();
        $prompt->markGenerated(PromptText::fromString($text));

        return $prompt;
    }

    private static function platform(): Platform
    {
        return new Platform('Upwork', PlatformAccessType::Browser);
    }

    private static function analysedProfile(): CvProfile
    {
        $cvProfile = new CvProfile(CvFile::create('3f9a1c.pdf', 'My CV.pdf'));
        $cvProfile->markAnalysisDone(new CvAnalysis('Анализ', 'Analysis', 10, null, null), [], '{}');

        return $cvProfile;
    }
}
