<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\JobPosting\CommentTooLong;
use App\Domain\JobPosting\JobPostingComment;
use App\Domain\JobPosting\JobPostingListFilter;
use App\Domain\JobPosting\JobPostingNotEvaluated;
use App\Domain\JobPosting\JobPostingServiceInterface;
use App\Domain\JobPosting\JobPostingStatus;
use App\Domain\JobPosting\JobPostingVerdict;
use App\Entity\JobPosting;
use App\Repository\JobPostingRepositoryInterface;
use App\Repository\PlatformRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class JobPostingController extends AbstractController
{
    public function __construct(
        private readonly JobPostingRepositoryInterface $postings,
        private readonly PlatformRepositoryInterface $platforms,
        private readonly JobPostingServiceInterface $postingService,
    ) {
    }

    #[Route('/postings', name: 'app_job_posting_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $platformId = $request->query->filter('platform', null, \FILTER_VALIDATE_INT, [
            'flags' => \FILTER_NULL_ON_FAILURE,
            'options' => ['min_range' => 1],
        ]);
        $filter = new JobPostingListFilter(
            \is_int($platformId) ? $platformId : null,
            JobPostingStatus::tryFrom($request->query->getString('status')),
            JobPostingVerdict::tryFrom($request->query->getString('verdict')),
            self::parseApplied($request->query->getString('applied')),
        );

        return $this->render('job_posting/index.html.twig', [
            'postings' => $this->postings->findNewestFirst($filter),
            'platforms' => $this->platforms->findAllOrderedByName(),
            'statuses' => JobPostingStatus::cases(),
            'verdicts' => JobPostingVerdict::cases(),
            'comment_max_length' => JobPostingComment::MAX_LENGTH,
            'filter' => $filter,
        ]);
    }

    #[Route(
        '/postings/{id}',
        name: 'app_job_posting_show',
        requirements: ['id' => Requirement::POSITIVE_INT],
        methods: ['GET'],
    )]
    public function show(int $id): Response
    {
        $posting = $this->findPosting($id);

        return $this->render('job_posting/show.html.twig', [
            'posting' => $posting,
            'history' => $this->postings->findStageLogsOldestFirst($posting),
            'verdicts' => JobPostingVerdict::cases(),
            'comment_max_length' => JobPostingComment::MAX_LENGTH,
        ]);
    }

    #[Route(
        '/postings/{id}/verdict',
        name: 'app_job_posting_verdict',
        requirements: ['id' => Requirement::POSITIVE_INT],
        methods: ['POST'],
    )]
    public function overrideVerdict(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('job_posting_verdict', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $verdict = JobPostingVerdict::tryFrom($request->request->getString('verdict'))
            ?? throw new BadRequestHttpException('Unknown verdict.');

        try {
            $this->postingService->overrideVerdict($this->findPosting($id), $verdict);
        } catch (JobPostingNotEvaluated $exception) {
            throw new ConflictHttpException($exception->getMessage(), $exception);
        }

        return $this->redirectToRoute('app_job_posting_show', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route(
        '/postings/{id}/comment',
        name: 'app_job_posting_comment',
        requirements: ['id' => Requirement::POSITIVE_INT],
        methods: ['POST'],
    )]
    public function updateComment(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('job_posting_comment', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $comment = JobPostingComment::fromInput($request->request->getString('comment'));
        } catch (CommentTooLong $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }

        $this->postingService->updateComment($this->findPosting($id), $comment);

        return $this->redirectToRoute('app_job_posting_show', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route(
        '/postings/{id}/applied',
        name: 'app_job_posting_applied',
        requirements: ['id' => Requirement::POSITIVE_INT],
        methods: ['POST'],
    )]
    public function updateApplied(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('job_posting_applied', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $applied = self::parseApplied($request->request->getString('applied'))
            ?? throw new BadRequestHttpException('The applied flag must be 1 or 0.');

        $this->postingService->updateApplied($this->findPosting($id), $applied);

        return $this->redirectToRoute('app_job_posting_show', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    private function findPosting(int $id): JobPosting
    {
        return $this->postings->findById($id) ?? throw $this->createNotFoundException();
    }

    private static function parseApplied(string $value): ?bool
    {
        return match ($value) {
            '1' => true,
            '0' => false,
            default => null,
        };
    }
}
