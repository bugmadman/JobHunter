<?php

declare(strict_types=1);

namespace App\Tests\Domain\Cv;

use App\Domain\Cv\CvFile;
use App\Domain\Cv\CvService;
use App\Domain\Cv\CvUpload;
use App\Domain\Cv\InvalidCvFile;
use App\Domain\Llm\LlmOperationStatus;
use App\Entity\CvProfile;
use App\Infrastructure\Storage\CvStorageInterface;
use App\Repository\CvProfileRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class CvServiceTest extends TestCase
{
    public function testStoresTheFileUnderAGeneratedNameAndSavesAPendingProfile(): void
    {
        $upload = new CvUpload('/tmp/phpUpload', 'My CV.pdf');

        $storedFile = null;
        $storage = $this->createMock(CvStorageInterface::class);
        $storage->expects(self::once())
            ->method('store')
            ->with($upload, self::isInstanceOf(CvFile::class))
            ->willReturnCallback(static function (CvUpload $upload, CvFile $file) use (&$storedFile): void {
                $storedFile = $file;
            });

        $cvProfiles = $this->createMock(CvProfileRepositoryInterface::class);
        $cvProfiles->expects(self::once())->method('save')->with(self::isInstanceOf(CvProfile::class));

        $cvProfile = new CvService($cvProfiles, $storage)->upload($upload);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf\z/', $cvProfile->getFile()->storedName);
        self::assertSame('My CV.pdf', $cvProfile->getFile()->originalName);
        self::assertEquals($storedFile, $cvProfile->getFile());
        self::assertSame(LlmOperationStatus::Pending, $cvProfile->getAnalysisStatus());
    }

    public function testEveryUploadGetsItsOwnStoredName(): void
    {
        $service = new CvService(
            self::createStub(CvProfileRepositoryInterface::class),
            self::createStub(CvStorageInterface::class),
        );
        $upload = new CvUpload('/tmp/phpUpload', 'cv.pdf');

        self::assertNotSame(
            $service->upload($upload)->getFile()->storedName,
            $service->upload($upload)->getFile()->storedName,
        );
    }

    public function testRejectsAnUnusableOriginalNameBeforeStoringAnything(): void
    {
        $storage = $this->createMock(CvStorageInterface::class);
        $storage->expects(self::never())->method('store');

        $cvProfiles = $this->createMock(CvProfileRepositoryInterface::class);
        $cvProfiles->expects(self::never())->method('save');

        $this->expectException(InvalidCvFile::class);

        new CvService($cvProfiles, $storage)->upload(new CvUpload('/tmp/phpUpload', "\0"));
    }
}
