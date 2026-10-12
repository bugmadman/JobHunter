<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Domain\Prompt\PromptServiceInterface;
use App\Message\GeneratePrompt;
use App\Repository\PromptRepositoryInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Without it a prompt whose LLM request failed on every retry would stay pending, blocking both regeneration and
 * editing for good.
 *
 * Must run after Messenger's retry listener (priority 100), which decides willRetry(). The worker resets services
 * only after this event, so a DB error in the handler may have closed the entity manager, and the identity map may
 * hold the prompt as the handler left it: the manager is reset and the prompt loaded again.
 */
#[AsEventListener(priority: 50)]
final readonly class PromptGenerationRetriesExhaustedListener
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private PromptRepositoryInterface $prompts,
        private PromptServiceInterface $promptService,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof GeneratePrompt || $event->willRetry()) {
            return;
        }

        $this->doctrine->resetManager();

        $prompt = $this->prompts->findById($message->promptId);
        if (null !== $prompt) {
            $this->promptService->giveUpGeneration($prompt);
        }
    }
}
