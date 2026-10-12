<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Prompt\AnalysedCvProfileNotFound;
use App\Domain\Prompt\PromptGenerationInProgress;
use App\Domain\Prompt\PromptServiceInterface;
use App\Entity\Platform;
use App\Message\GeneratePrompt;
use App\Repository\PlatformRepositoryInterface;
use App\Repository\PromptRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class PromptController extends AbstractController
{
    public function __construct(
        private readonly PromptServiceInterface $promptService,
        private readonly MessageBusInterface $bus,
        private readonly PlatformRepositoryInterface $platforms,
        private readonly PromptRepositoryInterface $prompts,
    ) {
    }

    #[Route('/prompts', name: 'app_prompt_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('prompt/index.html.twig', ['platforms' => $this->platforms->findAllOrderedByName()]);
    }

    #[Route(
        '/prompts/{platformId}',
        name: 'app_prompt_show',
        requirements: ['platformId' => Requirement::POSITIVE_INT],
        methods: ['GET'],
    )]
    public function show(int $platformId): Response
    {
        return $this->renderPrompt($this->platform($platformId));
    }

    #[Route(
        '/prompts/{platformId}/generate',
        name: 'app_prompt_generate',
        requirements: ['platformId' => Requirement::POSITIVE_INT],
        methods: ['POST'],
    )]
    public function generate(Request $request, int $platformId): Response
    {
        if (!$this->isCsrfTokenValid('prompt_generate', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $platform = $this->platform($platformId);

        try {
            $prompt = $this->promptService->requestGeneration($platform);
            $promptId = $prompt->getId();
            \assert(null !== $promptId);
            $this->bus->dispatch(new GeneratePrompt($promptId));
        } catch (PromptGenerationInProgress) {
            // A repeated click while the draft is generating: showing the pending state is the answer
        } catch (AnalysedCvProfileNotFound) {
            return $this->renderPrompt($platform, 'prompt.show.no_analysed_cv', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->redirectToRoute('app_prompt_show', ['platformId' => $platformId], Response::HTTP_SEE_OTHER);
    }

    private function platform(int $id): Platform
    {
        return $this->platforms->findById($id) ?? throw $this->createNotFoundException();
    }

    private function renderPrompt(Platform $platform, ?string $error = null, int $status = Response::HTTP_OK): Response
    {
        return $this->render(
            'prompt/show.html.twig',
            ['platform' => $platform, 'prompt' => $this->prompts->findByPlatform($platform), 'error' => $error],
            new Response(status: $status),
        );
    }
}
