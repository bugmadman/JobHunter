<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Storage;

use App\Domain\Cv\CvFile;
use App\Domain\Cv\CvUpload;
use App\Infrastructure\Storage\CvFileUnreadable;
use App\Infrastructure\Storage\CvStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final class CvStorageTest extends TestCase
{
    private Filesystem $filesystem;
    private string $root;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = $this->filesystem->tempnam(sys_get_temp_dir(), 'cv-storage-test-');
        $this->filesystem->remove($this->root);
        $this->filesystem->mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testMovesTheUploadIntoTheStorageDirectoryCreatingIt(): void
    {
        $source = $this->root.'/phpUpload';
        $this->filesystem->dumpFile($source, '%PDF-1.4');
        $directory = $this->root.'/storage/cv';

        new CvStorage($directory, $this->filesystem)->store(
            new CvUpload($source, 'My CV.pdf'),
            CvFile::create('3f9a1c.pdf', 'My CV.pdf'),
        );

        self::assertFileDoesNotExist($source);
        self::assertStringEqualsFile($directory.'/3f9a1c.pdf', '%PDF-1.4');
    }

    public function testNeverOverwritesAStoredFile(): void
    {
        $directory = $this->root.'/storage';
        $this->filesystem->dumpFile($directory.'/3f9a1c.pdf', 'stored');
        $source = $this->root.'/phpUpload';
        $this->filesystem->dumpFile($source, 'new');

        try {
            new CvStorage($directory, $this->filesystem)->store(
                new CvUpload($source, 'cv.pdf'),
                CvFile::create('3f9a1c.pdf', 'cv.pdf'),
            );
            self::fail('Expected an IOException.');
        } catch (IOException) {
            self::assertStringEqualsFile($directory.'/3f9a1c.pdf', 'stored');
        }
    }

    public function testReadsAStoredFile(): void
    {
        $this->filesystem->dumpFile($this->root.'/3f9a1c.pdf', '%PDF-1.4');

        self::assertSame(
            '%PDF-1.4',
            new CvStorage($this->root, $this->filesystem)->read(CvFile::create('3f9a1c.pdf', 'cv.pdf')),
        );
    }

    public function testMissingFileCannotBeRead(): void
    {
        $this->expectException(CvFileUnreadable::class);

        new CvStorage($this->root, $this->filesystem)->read(CvFile::create('3f9a1c.pdf', 'cv.pdf'));
    }
}
