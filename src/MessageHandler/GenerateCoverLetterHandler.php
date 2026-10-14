<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\CoverLetter\CoverLetterGenerationAlreadyFinished;
use App\Domain\CoverLetter\CoverLetterGenerationFailed;
use App\Domain\CoverLetter\CoverLetterServiceInterface;
use App\Message\GenerateCoverLetter;
use App\Repository\CoverLetterRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final readonly class GenerateCoverLetterHandler
{
    public function __construct(
        private CoverLetterRepositoryInterface $coverLetters,
        private CoverLetterServiceInterface $coverLetterService,
    ) {
    }

    public function __invoke(GenerateCoverLetter $message): void
    {
        $id = $message->coverLetterId;
        $coverLetter = $this->coverLetters->findById($id);
        if (null === $coverLetter) {
            throw new UnrecoverableMessageHandlingException(\sprintf('The cover letter %d does not exist.', $id));
        }

        try {
            $this->coverLetterService->generate($coverLetter);
        } catch (CoverLetterGenerationAlreadyFinished) {
            return;
        } catch (CoverLetterGenerationFailed $exception) {
            throw new UnrecoverableMessageHandlingException($exception->getMessage(), 0, $exception);
        }
    }
}
