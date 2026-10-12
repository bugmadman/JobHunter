<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Cv\CvAnalysisAlreadyFinished;
use App\Domain\Cv\CvAnalysisFailed;
use App\Domain\Cv\CvServiceInterface;
use App\Message\AnalyzeCv;
use App\Repository\CvProfileRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final readonly class AnalyzeCvHandler
{
    public function __construct(
        private CvProfileRepositoryInterface $cvProfiles,
        private CvServiceInterface $cvService,
    ) {
    }

    public function __invoke(AnalyzeCv $message): void
    {
        $id = $message->cvProfileId;
        $cvProfile = $this->cvProfiles->findById($id);
        if (null === $cvProfile) {
            throw new UnrecoverableMessageHandlingException(\sprintf('The CV profile %d does not exist.', $id));
        }

        try {
            $this->cvService->analyze($cvProfile);
        } catch (CvAnalysisAlreadyFinished) {
            return;
        } catch (CvAnalysisFailed $exception) {
            throw new UnrecoverableMessageHandlingException($exception->getMessage(), 0, $exception);
        }
    }
}
