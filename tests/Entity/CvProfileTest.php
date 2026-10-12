<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Domain\Cv\CvAnalysis;
use App\Domain\Cv\CvAnalysisAlreadyFinished;
use App\Domain\Cv\CvFile;
use App\Domain\Cv\LanguageLevel;
use App\Domain\Cv\LanguageProficiency;
use App\Domain\Cv\SalaryExpectation;
use App\Domain\Cv\Seniority;
use App\Domain\Llm\LlmOperationStatus;
use App\Entity\CvProfile;
use App\Entity\Skill;
use PHPUnit\Framework\TestCase;

final class CvProfileTest extends TestCase
{
    public function testNewProfileIsPendingWithoutAnalysis(): void
    {
        $profile = new CvProfile(CvFile::create('3f9a1c.pdf', 'My CV.pdf'));

        self::assertSame(LlmOperationStatus::Pending, $profile->getAnalysisStatus());
        self::assertNull($profile->getAnalysis());
        self::assertNull($profile->getRawLlmResponse());
        self::assertSame('3f9a1c.pdf', $profile->getFile()->storedName);
        self::assertSame('My CV.pdf', $profile->getFile()->originalName);
    }

    public function testDoneAnalysisIsStored(): void
    {
        $profile = self::pendingProfile();
        $php = new Skill('PHP');
        $symfony = new Skill('Symfony');

        $profile->markAnalysisDone(
            new CvAnalysis(
                'Анализ',
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
        $analysis = $profile->getAnalysis();
        self::assertNotNull($analysis);
        self::assertSame('Анализ', $analysis->originalLanguageText);
        self::assertSame('Analysis', $analysis->englishText);
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

        $profile->markAnalysisDone(new CvAnalysis('Анализ', 'Analysis', null, null, null), [], '{}');

        $analysis = $profile->getAnalysis();
        self::assertNotNull($analysis);
        self::assertNull($analysis->yearsExperience);
        self::assertNull($analysis->seniority);
        self::assertNull($analysis->salaryExpectation);
        self::assertSame([], $analysis->languages);
        self::assertSame([], $profile->getSkills());
    }

    public function testFailedAnalysisKeepsTheRawResponse(): void
    {
        $profile = self::pendingProfile();

        $profile->markAnalysisFailed('{"broken":');

        self::assertSame(LlmOperationStatus::Failed, $profile->getAnalysisStatus());
        self::assertSame('{"broken":', $profile->getRawLlmResponse());
        self::assertNull($profile->getAnalysis());
    }

    public function testFailedAnalysisWithoutResponse(): void
    {
        $profile = self::pendingProfile();

        $profile->markAnalysisFailed();

        self::assertSame(LlmOperationStatus::Failed, $profile->getAnalysisStatus());
        self::assertNull($profile->getRawLlmResponse());
    }

    public function testDoneAnalysisCannotBeFinishedAgain(): void
    {
        $profile = self::pendingProfile();
        $profile->markAnalysisDone(new CvAnalysis('Анализ', 'Analysis', 5, null, null), [], '{"first":true}');

        try {
            $profile->markAnalysisFailed('{"second":true}');
            self::fail('A done analysis was marked failed.');
        } catch (CvAnalysisAlreadyFinished) {
            self::assertSame(LlmOperationStatus::Done, $profile->getAnalysisStatus());
            self::assertSame('{"first":true}', $profile->getRawLlmResponse());
        }

        $this->expectException(CvAnalysisAlreadyFinished::class);
        $profile->markAnalysisDone(new CvAnalysis('Другой', 'Other', 7, null, null), [], '{}');
    }

    public function testFailedAnalysisCannotBeFinishedAgain(): void
    {
        $profile = self::pendingProfile();
        $profile->markAnalysisFailed('{"first":true}');

        try {
            $profile->markAnalysisDone(new CvAnalysis('Анализ', 'Analysis', 5, null, null), [], '{}');
            self::fail('A failed analysis was marked done.');
        } catch (CvAnalysisAlreadyFinished) {
            self::assertSame(LlmOperationStatus::Failed, $profile->getAnalysisStatus());
            self::assertNull($profile->getAnalysis());
            self::assertSame('{"first":true}', $profile->getRawLlmResponse());
        }

        $this->expectException(CvAnalysisAlreadyFinished::class);
        $profile->markAnalysisFailed();
    }

    private static function pendingProfile(): CvProfile
    {
        return new CvProfile(CvFile::create('3f9a1c.pdf', 'My CV.pdf'));
    }
}
