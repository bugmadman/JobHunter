<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\CoverLetter\CoverLetterGenerationInProgress;
use App\Domain\CoverLetter\CoverLetterNotGenerated;
use App\Domain\CoverLetter\CoverLetterServiceInterface;
use App\Domain\CoverLetter\CoverLetterText;
use App\Domain\CoverLetter\EmptyCoverLetterText;
use App\Entity\JobPosting;
use App\Message\GenerateCoverLetter;
use App\Repository\CoverLetterRepositoryInterface;
use App\Repository\JobPostingRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class CoverLetterController extends AbstractController
{
    public function __construct(
        private readonly CoverLetterServiceInterface $coverLetterService,
        private readonly MessageBusInterface $bus,
        private readonly JobPostingRepositoryInterface $postings,
        private readonly CoverLetterRepositoryInterface $coverLetters,
    ) {
    }

    #[Route(
        '/postings/{id}/cover-letter',
        name: 'app_cover_letter_show',
        requirements: ['id' => Requirement::POSITIVE_INT],
        methods: ['GET'],
    )]
    public function show(int $id): Response
    {
        $posting = $this->posting($id);

        return $this->render('cover_letter/show.html.twig', [
            'posting' => $posting,
            'cover_letter' => $this->coverLetters->findLatestByJobPosting($posting),
        ]);
    }

    #[Route(
        '/postings/{id}/cover-letter',
        name: 'app_cover_letter_generate',
        requirements: ['id' => Requirement::POSITIVE_INT],
        methods: ['POST'],
    )]
    public function generate(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('cover_letter_generate', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $coverLetter = $this->coverLetterService->requestGeneration($this->posting($id));
            $coverLetterId = $coverLetter->getId();
            \assert(null !== $coverLetterId);
            $this->bus->dispatch(new GenerateCoverLetter($coverLetterId));
        } catch (CoverLetterGenerationInProgress) {
            // A repeated click while the letter is generating: showing the pending state is the answer
        }

        return $this->redirectToRoute('app_cover_letter_show', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route(
        '/cover-letters/{id}',
        name: 'app_cover_letter_edit',
        requirements: ['id' => Requirement::POSITIVE_INT],
        methods: ['POST'],
    )]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('cover_letter_edit', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $coverLetter = $this->coverLetters->findById($id) ?? throw $this->createNotFoundException();

        try {
            $text = CoverLetterText::fromString($request->request->getString('text'));
        } catch (EmptyCoverLetterText $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }

        try {
            $this->coverLetterService->edit($coverLetter, $text);
        } catch (CoverLetterNotGenerated $exception) {
            throw new ConflictHttpException($exception->getMessage(), $exception);
        }

        return $this->redirectToRoute(
            'app_cover_letter_show',
            ['id' => $coverLetter->getJobPosting()->getId()],
            Response::HTTP_SEE_OTHER,
        );
    }

    private function posting(int $id): JobPosting
    {
        return $this->postings->findById($id) ?? throw $this->createNotFoundException();
    }
}
