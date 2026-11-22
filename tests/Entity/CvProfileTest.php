<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Domain\Cv\CvAnalysis;
use App\Domain\Cv\CvAnalysisAlreadyFinished;
use App\Domain\Cv\CvAnalysisNotFailed;
use App\Domain\Cv\CvFile;
use App\Domain\Cv\CvSkillAlreadyAdded;
use App\Domain\Cv\LanguageLevel;
use App\Domain\Cv\LanguageProficiency;
use App\Domain\Cv\SalaryExpectation;
use App\Domain\Cv\Seniority;
use App\Domain\Cv\SkillLevel;
use App\Domain\Llm\LlmFailure;
use App\Domain\Llm\LlmFailureCause;
use App\Domain\Llm\LlmOperationStatus;
use App\Domain\Skill\SkillName;
use App\Entity\CvProfile;
use App\Entity\Skill;
use PHPUnit\Framework\TestCase;

final class CvProfileTest extends TestCase
{
    public function testSkillIsAddedWithItsLevel(): void
    {
        $profile = self::pendingProfile();

        $profile->addCvSkill(SkillName::fromString('PHP'), SkillLevel::Expert);
        $profile->addCvSkill(SkillName::fromString('Node.js'), SkillLevel::Familiar);

        $skills = $profile->getCvSkills();
        self::assertCount(2, $skills);
        self::assertSame('php', $skills[0]->getName()->value);
        self::assertSame(SkillLevel::Expert, $skills[0]->getLevel());
        self::assertSame('node.js', $skills[1]->getName()->value);
        self::assertSame(SkillLevel::Familiar, $skills[1]->getLevel());
    }

    public function testSameSkillIsRefusedWhateverTheSpellingOrLevel(): void
    {
        $profile = self::pendingProfile();
        $profile->addCvSkill(SkillName::fromString('PHP'), SkillLevel::Expert);

        $this->expectException(CvSkillAlreadyAdded::class);

        $profile->addCvSkill(SkillName::fromString(' php '), SkillLevel::Basic);
    }

    public function testNewProfileIsPendingWithoutAnalysis(): void
    {
        $profile = new CvProfile(CvFile::create('3f9a1c.pdf', 'My CV.pdf'));

        self::assertSame(LlmOperationStatus::Pending, $profile->getAnalysisStatus());
        self::assertNull($profile->getAnalysis());
        self::assertNull($profile->getRawLlmResponse());
        self::assertNull($profile->getAnalysisFailure());
        self::assertSame('3f9a1c.pdf', $profile->getFile()->storedName);
        self::assertSame('My CV.pdf', $profile->getFile()->originalName);
    }

    public function testDoneAnalysisIsStored(): void
    {
        $profile = self::pendingProfile();
        $php = new Skill(SkillName::fromString('PHP'));
        $symfony = new Skill(SkillName::fromString('Symfony'));

        $profile->markAnalysisDone(
            new CvAnalysis(
                'Analysis',
                12,
                Seniority::Senior,
                SalaryExpectation::create(4000, 6000, 'USD'),
                [
                    LanguageProficiency::create('ru', LanguageLevel::Native),
                    LanguageProficiency::create('en', LanguageLevel::C1),
                ],
            ),
            [$php, $symfony, $php],
            '{"seniority":"senior"}',
        );

        self::assertSame(LlmOperationStatus::Done, $profile->getAnalysisStatus());
        self::assertSame('{"seniority":"senior"}', $profile->getRawLlmResponse());
        self::assertNull($profile->getAnalysisFailure());
        $analysis = $profile->getAnalysis();
        self::assertNotNull($analysis);
        self::assertSame('Analysis', $analysis->analysis);
        self::assertSame(12, $analysis->yearsExperience);
        self::assertSame(Seniority::Senior, $analysis->seniority);
        self::assertNotNull($analysis->salaryExpectation);
        self::assertSame(4000, $analysis->salaryExpectation->min);
        self::assertSame(6000, $analysis->salaryExpectation->max);
        self::assertSame('USD', $analysis->salaryExpectation->currency);
        self::assertEquals(
            [
                LanguageProficiency::create('ru', LanguageLevel::Native),
                LanguageProficiency::create('en', LanguageLevel::C1),
            ],
            $analysis->languages,
        );
        self::assertSame([$php, $symfony], $profile->getSkills());
    }

    public function testDoneAnalysisWithoutOptionalFields(): void
    {
        $profile = self::pendingProfile();

        $profile->markAnalysisDone(new CvAnalysis('Analysis', null, null, null), [], '{}');

        $analysis = $profile->getAnalysis();
        self::assertNotNull($analysis);
        self::assertNull($analysis->yearsExperience);
        self::assertNull($analysis->seniority);
        self::assertNull($analysis->salaryExpectation);
        self::assertSame([], $analysis->languages);
        self::assertSame([], $profile->getSkills());
    }

    public function testFailedAnalysisKeepsTheCauseAndTheRawResponse(): void
    {
        $profile = self::pendingProfile();

        $profile->markAnalysisFailed(LlmFailure::create(LlmFailureCause::InvalidResponse), '{"broken":');

        self::assertSame(LlmOperationStatus::Failed, $profile->getAnalysisStatus());
        self::assertEquals(LlmFailure::create(LlmFailureCause::InvalidResponse), $profile->getAnalysisFailure());
        self::assertSame('{"broken":', $profile->getRawLlmResponse());
        self::assertNull($profile->getAnalysis());
    }

    public function testFailedAnalysisKeepsTheProviderMessageWithoutResponse(): void
    {
        $profile = self::pendingProfile();
        $failure = LlmFailure::create(LlmFailureCause::Provider, 'The gemini request failed: HTTP 403');

        $profile->markAnalysisFailed($failure);

        self::assertSame(LlmOperationStatus::Failed, $profile->getAnalysisStatus());
        self::assertEquals($failure, $profile->getAnalysisFailure());
        self::assertNull($profile->getRawLlmResponse());
    }

    public function testDoneAnalysisCannotBeFinishedAgain(): void
    {
        $profile = self::pendingProfile();
        $profile->markAnalysisDone(new CvAnalysis('Analysis', 5, null, null), [], '{"first":true}');

        try {
            $profile->markAnalysisFailed(self::failure(), '{"second":true}');
            self::fail('A done analysis was marked failed.');
        } catch (CvAnalysisAlreadyFinished) {
            self::assertSame(LlmOperationStatus::Done, $profile->getAnalysisStatus());
            self::assertSame('{"first":true}', $profile->getRawLlmResponse());
            self::assertNull($profile->getAnalysisFailure());
        }

        $this->expectException(CvAnalysisAlreadyFinished::class);
        $profile->markAnalysisDone(new CvAnalysis('Other', 7, null, null), [], '{}');
    }

    public function testFailedAnalysisCannotBeFinishedAgain(): void
    {
        $profile = self::pendingProfile();
        $profile->markAnalysisFailed(LlmFailure::create(LlmFailureCause::InvalidResponse), '{"first":true}');

        try {
            $profile->markAnalysisDone(new CvAnalysis('Analysis', 5, null, null), [], '{}');
            self::fail('A failed analysis was marked done.');
        } catch (CvAnalysisAlreadyFinished) {
            self::assertSame(LlmOperationStatus::Failed, $profile->getAnalysisStatus());
            self::assertNull($profile->getAnalysis());
            self::assertSame('{"first":true}', $profile->getRawLlmResponse());
        }

        $this->expectException(CvAnalysisAlreadyFinished::class);
        $profile->markAnalysisFailed(self::failure());
    }

    public function testRestartingAFailedAnalysisMakesItPendingWithoutTheFailure(): void
    {
        $profile = self::pendingProfile();
        $profile->markAnalysisFailed(LlmFailure::create(LlmFailureCause::Provider, 'HTTP 403'), '{"broken":');

        $profile->restartAnalysis();

        self::assertSame(LlmOperationStatus::Pending, $profile->getAnalysisStatus());
        self::assertNull($profile->getAnalysisFailure());
        self::assertNull($profile->getRawLlmResponse());
        self::assertSame('3f9a1c.pdf', $profile->getFile()->storedName);

        $profile->markAnalysisFailed(LlmFailure::create(LlmFailureCause::InvalidResponse));
        self::assertEquals(LlmFailure::create(LlmFailureCause::InvalidResponse), $profile->getAnalysisFailure());
        self::assertNull($profile->getRawLlmResponse());
    }

    public function testAPendingAnalysisCannotBeRestarted(): void
    {
        $profile = self::pendingProfile();

        $this->expectException(CvAnalysisNotFailed::class);
        $profile->restartAnalysis();
    }

    public function testADoneAnalysisCannotBeRestarted(): void
    {
        $profile = self::pendingProfile();
        $profile->markAnalysisDone(new CvAnalysis('Analysis', 5, null, null), [], '{"first":true}');

        try {
            $profile->restartAnalysis();
            self::fail('A done analysis was restarted.');
        } catch (CvAnalysisNotFailed) {
            self::assertSame(LlmOperationStatus::Done, $profile->getAnalysisStatus());
            self::assertNotNull($profile->getAnalysis());
            self::assertSame('{"first":true}', $profile->getRawLlmResponse());
        }
    }

    private static function failure(): LlmFailure
    {
        return LlmFailure::create(LlmFailureCause::Unexpected);
    }

    private static function pendingProfile(): CvProfile
    {
        return new CvProfile(CvFile::create('3f9a1c.pdf', 'My CV.pdf'));
    }
}
