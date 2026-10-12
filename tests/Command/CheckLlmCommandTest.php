<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Domain\Llm\LlmModelNotSelected;
use App\Domain\Llm\LlmProvider;
use App\Domain\Llm\LlmServiceInterface;
use App\Domain\Llm\LlmTask;
use App\Infrastructure\Llm\LlmRequest;
use App\Infrastructure\Llm\LlmRequestFailed;
use App\Infrastructure\Llm\LlmResponse;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckLlmCommandTest extends KernelTestCase
{
    public function testShowsTheReplyOfTheModel(): void
    {
        $llmService = $this->createMock(LlmServiceInterface::class);
        $llmService->expects(self::once())
            ->method('generate')
            ->with(LlmTask::CvAnalysis, self::isInstanceOf(LlmRequest::class))
            ->willReturn(new LlmResponse('Message received.'));

        $tester = $this->commandTester($llmService);

        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'cv_analysis']));
        self::assertStringContainsString('The model replied: Message received.', $tester->getDisplay());
    }

    public function testRejectsAnUnknownTaskWithoutCallingTheService(): void
    {
        $llmService = $this->createMock(LlmServiceInterface::class);
        $llmService->expects(self::never())->method('generate');

        $tester = $this->commandTester($llmService);

        self::assertSame(Command::FAILURE, $tester->execute(['task' => 'cooking']));
        self::assertStringContainsString('Unknown LLM task "cooking"', $tester->getDisplay());
    }

    public function testShowsTheErrorWhenTheTaskIsNotConfigured(): void
    {
        $llmService = self::createStub(LlmServiceInterface::class);
        $llmService->method('generate')->willThrowException(LlmModelNotSelected::forTask(LlmTask::Matching));

        $tester = $this->commandTester($llmService);

        self::assertSame(Command::FAILURE, $tester->execute(['task' => 'matching']));
        self::assertStringContainsString('No model is selected', $tester->getDisplay());
    }

    public function testShowsTheErrorWhenTheRequestFails(): void
    {
        $llmService = self::createStub(LlmServiceInterface::class);
        $llmService->method('generate')
            ->willThrowException(LlmRequestFailed::create(LlmProvider::Gemini, 'HTTP 403'));

        $tester = $this->commandTester($llmService);

        self::assertSame(Command::FAILURE, $tester->execute(['task' => 'matching']));
        self::assertStringContainsString('HTTP 403', $tester->getDisplay());
    }

    private function commandTester(LlmServiceInterface $llmService): CommandTester
    {
        $kernel = self::bootKernel();
        self::getContainer()->set(LlmServiceInterface::class, $llmService);

        return new CommandTester(new Application($kernel)->find('app:llm:check'));
    }
}
