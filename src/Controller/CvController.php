<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Cv\CvServiceInterface;
use App\Domain\Cv\CvUpload;
use App\Domain\Cv\InvalidCvFile;
use App\Domain\Llm\ApiKeyNotSet;
use App\Domain\Llm\LlmModelNotSelected;
use App\Form\CvUploadData;
use App\Form\CvUploadType;
use App\Form\LlmModelSelectionType;
use App\Form\LlmProviderKeyType;
use App\Message\AnalyzeCv;
use App\Repository\CvProfileRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CvController extends AbstractController
{
    public function __construct(
        private readonly CvServiceInterface $cvService,
        private readonly TranslatorInterface $translator,
        private readonly MessageBusInterface $bus,
        private readonly CvProfileRepositoryInterface $cvProfiles,
    ) {
    }

    #[Route('/cv', name: 'app_cv_upload', methods: ['GET', 'POST'])]
    public function upload(Request $request): Response
    {
        try {
            return $this->handleUpload($request);
        } catch (LlmModelNotSelected $e) {
            return $this->renderLlmSettingsMissing($request, new TranslatableMessage(
                'llm.settings_missing.model_not_selected',
                ['%task%' => new TranslatableMessage(LlmModelSelectionType::taskLabel($e->task))],
            ), $this->generateUrl('admin_llm_model_selection_new'));
        } catch (ApiKeyNotSet $e) {
            return $this->renderLlmSettingsMissing($request, new TranslatableMessage(
                'llm.settings_missing.api_key_not_set',
                ['%provider%' => new TranslatableMessage(LlmProviderKeyType::providerLabel($e->provider))],
            ), $this->generateUrl('admin_llm_provider_key_new'));
        }
    }

    #[Route('/cv/{id}', name: 'app_cv_show', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function show(int $id): Response
    {
        $cvProfile = $this->cvProfiles->findById($id) ?? throw $this->createNotFoundException();

        return $this->render('cv/show.html.twig', ['cvProfile' => $cvProfile]);
    }

    /**
     * @throws LlmModelNotSelected
     * @throws ApiKeyNotSet
     */
    private function handleUpload(Request $request): Response
    {
        $this->cvService->assertCanUpload();

        $data = new CvUploadData();
        $form = $this->createForm(CvUploadType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $data->file;
            \assert(null !== $file);

            try {
                $cvProfile = $this->cvService->upload(
                    new CvUpload($file->getPathname(), $file->getClientOriginalName()),
                );
                $cvProfileId = $cvProfile->getId();
                \assert(null !== $cvProfileId);
                $this->bus->dispatch(new AnalyzeCv($cvProfileId));
                $this->addFlash('success', 'cv.upload.uploaded');

                return $this->redirectToRoute('app_cv_show', ['id' => $cvProfileId]);
            } catch (InvalidCvFile) {
                $error = $this->translator->trans('cv.upload.invalid_file_name');
                $form->get('file')->addError(new FormError($error));
            }
        }

        return $this->render('cv/upload.html.twig', ['form' => $form]);
    }

    private function renderLlmSettingsMissing(
        Request $request,
        TranslatableMessage $hint,
        string $settingsUrl,
    ): Response {
        // A refused submit must not answer 200: Turbo drops a successful form response that doesn't redirect
        $status = $request->isMethod('POST') ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->render('cv/upload.html.twig', [
            'settings_hint' => $hint,
            'settings_url' => $settingsUrl,
        ], new Response(status: $status));
    }
}
