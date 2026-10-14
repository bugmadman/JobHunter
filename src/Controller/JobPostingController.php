<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\JobPosting\JobPostingListFilter;
use App\Domain\JobPosting\JobPostingStatus;
use App\Domain\JobPosting\JobPostingVerdict;
use App\Repository\JobPostingRepositoryInterface;
use App\Repository\PlatformRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class JobPostingController extends AbstractController
{
    public function __construct(
        private readonly JobPostingRepositoryInterface $postings,
        private readonly PlatformRepositoryInterface $platforms,
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
        );

        return $this->render('job_posting/index.html.twig', [
            'postings' => $this->postings->findNewestFirst($filter),
            'platforms' => $this->platforms->findAllOrderedByName(),
            'statuses' => JobPostingStatus::cases(),
            'verdicts' => JobPostingVerdict::cases(),
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
        $posting = $this->postings->findById($id) ?? throw $this->createNotFoundException();

        return $this->render('job_posting/show.html.twig', [
            'posting' => $posting,
            'history' => $this->postings->findStageLogsOldestFirst($posting),
        ]);
    }
}
