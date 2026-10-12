<?php

declare(strict_types=1);

namespace App\Tests\Domain\Cv;

use App\Domain\Cv\CvFile;
use App\Domain\Cv\InvalidCvFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CvFileTest extends TestCase
{
    public function testOriginalNameIsTrimmed(): void
    {
        $file = CvFile::create('3f9a1c.pdf', "  My CV 2026.pdf\n");

        self::assertSame('3f9a1c.pdf', $file->storedName);
        self::assertSame('My CV 2026.pdf', $file->originalName);
    }

    #[DataProvider('originalNamesToNormalise')]
    public function testOriginalNameIsNormalised(string $originalName, string $expected): void
    {
        self::assertSame($expected, CvFile::create('cv.pdf', $originalName)->originalName);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function originalNamesToNormalise(): iterable
    {
        yield 'NUL byte' => ["cv\0.pdf", 'cv .pdf'];
        yield 'newline inside' => ["My\nCV.pdf", 'My CV.pdf'];
        yield 'run of control characters' => ["My\r\n\tCV.pdf", 'My CV.pdf'];
        yield 'right-to-left override' => ["cv\u{202E}fdp.exe", 'cv fdp.exe'];
        yield 'zero-width space' => ["My\u{200B}CV.pdf", 'My CV.pdf'];
        yield 'line separator' => ["My\u{2028}CV.pdf", 'My CV.pdf'];
        yield 'surrounding no-break spaces' => ["\u{00A0}My CV.pdf\u{3000}", 'My CV.pdf'];
        yield 'invalid UTF-8' => ["cv\xFF\xFE.pdf", 'cv??.pdf'];
        yield 'non-Latin letters kept' => ['Резюме 2026.pdf', 'Резюме 2026.pdf'];
    }

    public function testOriginalNameOfMaxLengthIsAccepted(): void
    {
        $name = str_repeat('ж', CvFile::MAX_NAME_LENGTH);

        self::assertSame($name, CvFile::create('cv.pdf', $name)->originalName);
    }

    #[DataProvider('invalidStoredNames')]
    public function testStoredNameThatIsNotABareFileNameIsRejected(string $storedName): void
    {
        $this->expectException(InvalidCvFile::class);

        CvFile::create($storedName, 'cv.pdf');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidStoredNames(): iterable
    {
        yield 'empty' => [''];
        yield 'parent directory' => ['..'];
        yield 'hidden file' => ['.htaccess'];
        yield 'subdirectory' => ['cv/3f9a1c.pdf'];
        yield 'traversal' => ['../../etc/passwd'];
        yield 'absolute path' => ['/var/cv.pdf'];
        yield 'backslash' => ['cv\\3f9a1c.pdf'];
        yield 'space' => ['my cv.pdf'];
        yield 'too long' => [str_repeat('a', 256)];
        yield 'trailing newline' => ["cv.pdf\n"];
    }

    #[DataProvider('invalidOriginalNames')]
    public function testInvalidOriginalNameIsRejected(string $originalName): void
    {
        $this->expectException(InvalidCvFile::class);

        CvFile::create('cv.pdf', $originalName);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidOriginalNames(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ["  \t"];
        yield 'only no-break spaces' => ["\u{00A0}\u{3000}"];
        yield 'only control characters' => ["\0\x01\u{202E}"];
        yield 'too long' => [str_repeat('ж', CvFile::MAX_NAME_LENGTH + 1)];
    }
}
