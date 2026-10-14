<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Llm\ApiKeyNotSet;
use App\Domain\Llm\LlmModelNotSelected;
use App\Domain\Llm\LlmServiceInterface;
use App\Domain\Llm\LlmTask;
use App\Infrastructure\Llm\LlmRequest;
use App\Infrastructure\Llm\LlmRequestFailed;
use App\Infrastructure\Security\ApiKeyDecryptionFailed;
use App\Infrastructure\Security\InvalidEncryptionKey;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:llm:check', description: 'Send a test request to the model selected for an LLM task')]
final readonly class CheckLlmCommand
{
    public function __construct(
        private LlmServiceInterface $llmService,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The LLM task: cv_analysis, prompt_generation, matching or cover_letter')]
        string $task,
    ): int {
        $llmTask = LlmTask::tryFrom($task);
        if (null === $llmTask) {
            $io->error(\sprintf(
                'Unknown LLM task "%s". Use one of: %s.',
                $task,
                implode(', ', array_column(LlmTask::cases(), 'value')),
            ));

            return Command::FAILURE;
        }

        try {
            $response = $this->llmService->generate(
                $llmTask,
                new LlmRequest('Reply with one short sentence confirming that you received this message.'),
            );
        } catch (
            LlmModelNotSelected|ApiKeyNotSet|ApiKeyDecryptionFailed|InvalidEncryptionKey|LlmRequestFailed $e
        ) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('The model replied: %s', $response->text));

        return Command::SUCCESS;
    }
}
