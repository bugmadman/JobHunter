<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\JobPostingRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class JobPostingController extends AbstractController
{
    public function __construct(
        private readonly JobPostingRepositoryInterface $postings,
    ) {
    }

    #[Route('/postings', name: 'app_job_posting_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('job_posting/index.html.twig', ['postings' => $this->postings->findAllNewestFirst()]);
    }
}
