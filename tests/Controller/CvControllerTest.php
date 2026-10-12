<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Cv\CvAnalysis;
use App\Domain\Cv\CvFile;
use App\Domain\Cv\CvServiceInterface;
use App\Domain\Cv\CvUpload;
use App\Domain\Cv\InvalidCvFile;
use App\Domain\Cv\LanguageLevel;
use App\Domain\Cv\LanguageProficiency;
use App\Domain\Cv\SalaryExpectation;
use App\Domain\Cv\Seniority;
use App\Domain\Skill\SkillName;
use App\Entity\CvProfile;
use App\Entity\Skill;
use App\Message\AnalyzeCv;
use App\Repository\CvProfileRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class CvControllerTest extends WebTestCase
{
    use LogsInAsOwner;

    private const string URL = '/cv';
    private const array SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];
    private const array CROSS_SITE = ['HTTP_SEC_FETCH_SITE' => 'cross-site'];

    private Filesystem $filesystem;
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->uploadDir = $this->filesystem->tempnam(sys_get_temp_dir(), 'cv-upload-test-');
        $this->filesystem->remove($this->uploadDir);
        $this->filesystem->mkdir($this->uploadDir);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->uploadDir);
        parent::tearDown();
    }

    public function testShowsAPdfUploadForm(): void
    {
        $client = $this->loggedInClient();
        $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'CV');
        self::assertSelectorExists('form[name="cv_upload"][enctype="multipart/form-data"]');
        self::assertSelectorExists('input[type="file"][name="cv_upload[file]"][accept="application/pdf,.pdf"]');
    }

    public function testUploadsThePdfThroughTheServiceAndQueuesItsAnalysis(): void
    {
        $profile = new CvProfile(CvFile::create('3f9a1c.pdf', 'My CV.pdf'));
        new \ReflectionProperty(CvProfile::class, 'id')->setValue($profile, 42);

        $client = $this->loggedInClient();
        $this->stubCvProfiles($client)->method('findById')->willReturnMap([[42, $profile]]);
        $this->mockCvService($client)
            ->expects(self::once())
            ->method('upload')
            ->with(self::callback(
                static fn (CvUpload $upload): bool => 'My CV.pdf' === $upload->originalName
                    && is_file($upload->path),
            ))
            ->willReturn($profile);

        $this->submit($client, $this->pdf('My CV.pdf'));

        self::assertResponseRedirects('/cv/42');
        self::assertEquals([new AnalyzeCv(42)], $this->queuedMessages($client));
        $client->followRedirect();
        self::assertSelectorTextContains('#flash-messages .alert-success', 'The CV is uploaded.');
    }

    public function testRejectsAFileThatIsNotAPdf(): void
    {
        $client = $this->loggedInClient();
        $this->mockCvService($client)->expects(self::never())->method('upload');

        $path = $this->uploadDir.'/cv.pdf';
        $this->filesystem->dumpFile($path, 'plain text pretending to be a PDF');
        $this->submit($client, $path);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback', 'The mime type of the file is invalid');
        self::assertSame([], $this->queuedMessages($client));
    }

    public function testRejectsAFileOverTheSizeLimit(): void
    {
        $client = $this->loggedInClient();
        $this->mockCvService($client)->expects(self::never())->method('upload');

        $path = $this->pdf('cv.pdf');
        $handle = fopen($path, 'r+');
        self::assertIsResource($handle);
        ftruncate($handle, 10_000_001);
        fclose($handle);
        $this->submit($client, $path);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback', 'The file is too large');
    }

    public function testRejectsASubmitWithoutAFile(): void
    {
        $client = $this->loggedInClient();
        $this->mockCvService($client)->expects(self::never())->method('upload');

        $this->submit($client, null);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback', 'This value should not be blank.');
    }

    public function testRejectsACrossSiteSubmit(): void
    {
        $client = $this->loggedInClient();
        $this->mockCvService($client)->expects(self::never())->method('upload');

        $this->submit($client, $this->pdf('cv.pdf'), self::CROSS_SITE);

        self::assertResponseStatusCodeSame(422);
    }

    public function testShowsAnErrorForAFileNameTheServiceRejects(): void
    {
        $client = $this->loggedInClient();
        $this->mockCvService($client)
            ->expects(self::once())
            ->method('upload')
            ->willThrowException(InvalidCvFile::invalidOriginalName());

        $this->submit($client, $this->pdf('cv.pdf'));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback', 'This file name can\'t be used.');
        self::assertSame([], $this->queuedMessages($client));
    }

    public function testShowsAPendingAnalysisInAFrameThatReloadsItself(): void
    {
        $client = $this->loggedInClient();
        $this->stubCvProfiles($client)->method('findById')->willReturnMap([[7, $this->profile(7)]]);

        $client->request('GET', '/cv/7');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'My CV.pdf');
        self::assertSelectorExists(
            'turbo-frame#cv-analysis [data-controller="frame-reload"][data-frame-reload-url-value="/cv/7"]',
        );
        self::assertSelectorTextContains('turbo-frame#cv-analysis', 'The CV is being analysed.');
    }

    public function testShowsAFailedAnalysisWithoutReloading(): void
    {
        $profile = $this->profile(7);
        $profile->markAnalysisFailed();
        $client = $this->loggedInClient();
        $this->stubCvProfiles($client)->method('findById')->willReturn($profile);

        $client->request('GET', '/cv/7');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#cv-analysis .alert-danger', 'The analysis failed.');
        self::assertSelectorNotExists('[data-controller="frame-reload"]');
    }

    public function testShowsADoneAnalysis(): void
    {
        $profile = $this->profile(7);
        $profile->markAnalysisDone(
            new CvAnalysis(
                "Backend developer.\nLoves PHP.",
                'Backend developer.',
                12,
                Seniority::Senior,
                SalaryExpectation::create(5000, null, 'EUR'),
                [LanguageProficiency::create('en', LanguageLevel::C1)],
            ),
            [new Skill(SkillName::fromString('PHP'))],
            '{}',
        );
        $client = $this->loggedInClient();
        $this->stubCvProfiles($client)->method('findById')->willReturn($profile);

        $client->request('GET', '/cv/7');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-controller="frame-reload"]');
        $frame = $client->getCrawler()->filter('turbo-frame#cv-analysis');
        $text = $frame->text();
        self::assertStringContainsString('12', $text);
        self::assertStringContainsString('Senior', $text);
        self::assertStringContainsString('5000 – … EUR per month', $text);
        self::assertStringContainsString('EN (C1)', $text);
        self::assertSame('php', $frame->filter('.badge')->text());
        self::assertStringContainsString('Backend developer.<br>', $frame->html());
    }

    public function testShowReturns404ForAnUnknownProfile(): void
    {
        $client = $this->loggedInClient();
        $this->stubCvProfiles($client)->method('findById')->willReturn(null);

        $client->request('GET', '/cv/999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRedirectsAnonymousToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', self::URL);

        self::assertResponseRedirects('/login');
    }

    private function loggedInClient(): KernelBrowser
    {
        $client = self::createClient();
        // Keeps the mocked service across the requests of one test
        $client->disableReboot();
        $this->logInAsOwner($client);

        return $client;
    }

    private function pdf(string $name): string
    {
        $path = $this->uploadDir.'/'.$name;
        $this->filesystem->dumpFile($path, "%PDF-1.4\n%%EOF\n");

        return $path;
    }

    /**
     * @param array<string, string> $server
     */
    private function submit(KernelBrowser $client, ?string $path, array $server = self::SAME_ORIGIN): void
    {
        $crawler = $client->request('GET', self::URL);
        $form = $crawler->filter('form[name="cv_upload"]')->form();
        if (null !== $path) {
            $field = $form['cv_upload[file]'];
            self::assertInstanceOf(FileFormField::class, $field);
            $field->upload($path);
        }

        $client->submit($form, [], $server);
    }

    /**
     * @return list<object>
     */
    private function queuedMessages(KernelBrowser $client): array
    {
        $transport = $client->getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_map(
            static fn (Envelope $envelope): object => $envelope->getMessage(),
            [...$transport->getSent()],
        ));
    }

    private function profile(int $id): CvProfile
    {
        $profile = new CvProfile(CvFile::create('3f9a1c.pdf', 'My CV.pdf'));
        new \ReflectionProperty(CvProfile::class, 'id')->setValue($profile, $id);

        return $profile;
    }

    private function stubCvProfiles(KernelBrowser $client): CvProfileRepositoryInterface&Stub
    {
        $cvProfiles = self::createStub(CvProfileRepositoryInterface::class);
        $client->getContainer()->set(CvProfileRepositoryInterface::class, $cvProfiles);

        return $cvProfiles;
    }

    private function mockCvService(KernelBrowser $client): CvServiceInterface&MockObject
    {
        $cvService = $this->createMock(CvServiceInterface::class);
        $client->getContainer()->set(CvServiceInterface::class, $cvService);

        return $cvService;
    }
}
