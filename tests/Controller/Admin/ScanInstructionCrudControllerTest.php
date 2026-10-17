<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Domain\ScanInstruction\ScanInstructionServiceInterface;
use App\Domain\ScanInstruction\ScanInstructionText;
use App\Entity\Platform;
use App\Entity\ScanInstruction;
use App\Repository\PlatformRepositoryInterface;
use App\Repository\ScanInstructionRepositoryInterface;
use App\Tests\Controller\LogsInAsOwner;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ScanInstructionCrudControllerTest extends WebTestCase
{
    use LogsInAsOwner;

    private const string INDEX_URL = '/admin/scan-instruction';
    private const string NEW_URL = '/admin/scan-instruction/new';
    private const array SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];

    public function testIndexShowsThePlatformAndAnExcerpt(): void
    {
        $client = $this->loggedInClient();
        $this->storeInstruction($client, 'Upwork');

        $client->request('GET', self::INDEX_URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('td[data-column="platform.name"]', 'Upwork');
        self::assertSelectorTextContains('td[data-column="text"]', 'Search "Symfony".');
    }

    public function testIndexOffersNoAddingOnceEveryPlatformHasAnInstruction(): void
    {
        $client = $this->loggedInClient();
        $client->request('GET', self::INDEX_URL);
        self::assertSelectorExists(\sprintf('a[href$="%s"]', self::NEW_URL));

        foreach (['Upwork', 'LinkedIn', 'Glassdoor', 'Indeed'] as $name) {
            $this->storeInstruction($client, $name);
        }
        $client->request('GET', self::INDEX_URL);

        self::assertSelectorNotExists(\sprintf('a[href$="%s"]', self::NEW_URL));
    }

    public function testTheNewFormOffersOnlyPlatformsWithoutAnInstruction(): void
    {
        $client = $this->loggedInClient();
        $this->storeInstruction($client, 'Upwork');

        $client->request('GET', self::NEW_URL);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Glassdoor', 'Indeed', 'LinkedIn'],
            $client->getCrawler()->filter('select[name="scan_instruction[platform]"] option')->each(
                static fn ($option): string => $option->text(),
            ),
        );
    }

    public function testCreatesTheInstructionThroughTheService(): void
    {
        $client = $this->loggedInClient();
        $upwork = $this->platform($client, 'Upwork');
        $this->mockService($client)
            ->expects(self::once())
            ->method('save')
            ->with(
                self::callback(static fn (Platform $platform): bool => $platform->getId() === $upwork->getId()),
                self::callback(static fn (ScanInstructionText $text): bool => 'Search.' === $text->value),
            );

        $client->request('GET', self::NEW_URL);
        $this->submit($client, [
            'scan_instruction[platform]' => (string) $upwork->getId(),
            'scan_instruction[text]' => "  Search.\n",
        ]);

        self::assertResponseRedirects(self::INDEX_URL);
        $client->followRedirect();
        self::assertSelectorTextContains('#flash-messages', 'The scan instruction is saved.');
    }

    #[TestWith([''])]
    #[TestWith(["  \u{00A0}\n"])]
    public function testRejectsABlankText(string $text): void
    {
        $client = $this->loggedInClient();
        $upwork = $this->platform($client, 'Upwork');
        $this->mockService($client)->expects(self::never())->method('save');

        $client->request('GET', self::NEW_URL);
        $this->submit($client, [
            'scan_instruction[platform]' => (string) $upwork->getId(),
            'scan_instruction[text]' => $text,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.invalid-feedback');
    }

    public function testRejectsAPlatformThatGotAnInstructionWhileTheFormWasOpen(): void
    {
        $client = $this->loggedInClient();
        $upwork = $this->platform($client, 'Upwork');
        $client->request('GET', self::NEW_URL);
        $this->storeInstruction($client, 'Upwork');

        $this->submit($client, [
            'scan_instruction[platform]' => (string) $upwork->getId(),
            'scan_instruction[text]' => 'Second.',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback', 'The selected choice is invalid.');
    }

    public function testEditsTheInstructionThroughTheService(): void
    {
        $client = $this->loggedInClient();
        $id = $this->storeInstruction($client, 'Upwork')->getId();
        $this->mockService($client)
            ->expects(self::once())
            ->method('save')
            ->with(
                self::callback(static fn (Platform $platform): bool => 'Upwork' === $platform->getName()),
                self::callback(static fn (ScanInstructionText $text): bool => 'Edited.' === $text->value),
            );

        $client->request('GET', self::INDEX_URL.'/'.$id.'/edit');
        self::assertSelectorTextContains('h1', 'Scan instruction for Upwork');
        self::assertSelectorNotExists('select[name="scan_instruction[platform]"]');
        self::assertSelectorTextContains('textarea[name="scan_instruction[text]"]', 'Search "Symfony".');
        $this->submit($client, ['scan_instruction[text]' => 'Edited.']);

        self::assertResponseRedirects(self::INDEX_URL);
    }

    public function testDeletesTheInstructionThroughTheService(): void
    {
        $client = $this->loggedInClient();
        $id = $this->storeInstruction($client, 'Upwork')->getId();
        $this->mockService($client)->expects(self::once())->method('delete');

        $crawler = $client->request('GET', self::INDEX_URL);
        $token = $crawler->filter('#action-confirmation-form input[name="token"]')->attr('value');
        $client->request('POST', self::INDEX_URL.'/'.$id.'/delete', ['token' => $token]);

        self::assertResponseRedirects(self::INDEX_URL);
    }

    public function testRedirectsAnonymousToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', self::INDEX_URL);

        self::assertResponseRedirects('/login');
    }

    private function loggedInClient(): KernelBrowser
    {
        $client = self::createClient();
        // Keeps the mocked service and the stored rows across the requests of one test
        $client->disableReboot();
        $this->logInAsOwner($client);

        return $client;
    }

    /**
     * @param array<string, string> $fields
     */
    private function submit(KernelBrowser $client, array $fields): void
    {
        $form = $client->getCrawler()->filter('form[name="scan_instruction"]')->form();
        $form->disableValidation();
        $client->submit($form, $fields, self::SAME_ORIGIN);
    }

    private function storeInstruction(KernelBrowser $client, string $platformName): ScanInstruction
    {
        $scanInstruction = new ScanInstruction(
            $this->platform($client, $platformName),
            ScanInstructionText::fromString('Search "Symfony".'),
            new \DateTimeImmutable(),
        );
        $scanInstructions = $client->getContainer()->get(ScanInstructionRepositoryInterface::class);
        self::assertInstanceOf(ScanInstructionRepositoryInterface::class, $scanInstructions);
        $scanInstructions->save($scanInstruction);

        return $scanInstruction;
    }

    private function platform(KernelBrowser $client, string $name): Platform
    {
        $platforms = $client->getContainer()->get(PlatformRepositoryInterface::class);
        self::assertInstanceOf(PlatformRepositoryInterface::class, $platforms);
        $platform = $platforms->findByName($name);
        self::assertNotNull($platform);

        return $platform;
    }

    private function mockService(KernelBrowser $client): ScanInstructionServiceInterface&MockObject
    {
        $service = $this->createMock(ScanInstructionServiceInterface::class);
        $client->getContainer()->set(ScanInstructionServiceInterface::class, $service);

        return $service;
    }
}
