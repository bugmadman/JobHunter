<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Domain\Cv\CvAnalysis;
use App\Domain\Cv\CvFile;
use App\Entity\CvProfile;
use App\Repository\CvProfileRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CvProfileRepositoryTest extends KernelTestCase
{
    public function testFindsTheNewestAnalysedProfileSkippingNewerUnfinishedOnes(): void
    {
        $this->repository()->save(self::analysed('older.pdf'));
        $latestAnalysed = self::analysed('latest.pdf');
        $this->repository()->save($latestAnalysed);
        $failed = new CvProfile(CvFile::create('failed.pdf', 'CV.pdf'));
        $failed->markAnalysisFailed();
        $this->repository()->save($failed);
        $this->repository()->save(new CvProfile(CvFile::create('pending.pdf', 'CV.pdf')));

        self::assertSame($latestAnalysed, $this->repository()->findLatestAnalysed());
    }

    public function testFindsNothingWithoutAnAnalysedProfile(): void
    {
        $this->repository()->save(new CvProfile(CvFile::create('pending.pdf', 'CV.pdf')));

        self::assertNull($this->repository()->findLatestAnalysed());
    }

    private static function analysed(string $storedName): CvProfile
    {
        $cvProfile = new CvProfile(CvFile::create($storedName, 'CV.pdf'));
        $cvProfile->markAnalysisDone(new CvAnalysis('Анализ', 'Analysis', null, null, null), [], '{}');

        return $cvProfile;
    }

    private function repository(): CvProfileRepository
    {
        $repository = self::getContainer()->get(CvProfileRepository::class);
        self::assertInstanceOf(CvProfileRepository::class, $repository);

        return $repository;
    }
}
