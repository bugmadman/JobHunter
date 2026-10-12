<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Prompt\PromptGenerationAlreadyFinished;
use App\Domain\Prompt\PromptGenerationFailed;
use App\Domain\Prompt\PromptServiceInterface;
use App\Message\GeneratePrompt;
use App\Repository\PromptRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final readonly class GeneratePromptHandler
{
    public function __construct(
        private PromptRepositoryInterface $prompts,
        private PromptServiceInterface $promptService,
    ) {
    }

    public function __invoke(GeneratePrompt $message): void
    {
        $id = $message->promptId;
        $prompt = $this->prompts->findById($id);
        if (null === $prompt) {
            throw new UnrecoverableMessageHandlingException(\sprintf('The prompt %d does not exist.', $id));
        }

        try {
            $this->promptService->generate($prompt);
        } catch (PromptGenerationAlreadyFinished) {
            return;
        } catch (PromptGenerationFailed $exception) {
            throw new UnrecoverableMessageHandlingException($exception->getMessage(), 0, $exception);
        }
    }
}
