<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Cv\CvAlreadyUploaded;
use App\Domain\Cv\CvServiceInterface;
use App\Domain\Cv\CvUpload;
use App\Domain\Cv\InvalidCvFile;
use App\Domain\Llm\ApiKeyNotSet;
use App\Domain\Llm\LlmModelNotSelected;
use App\Entity\CvProfile;
use App\Form\CvUploadData;
use App\Form\CvUploadType;
use App\Message\AnalyzeCv;
use App\Repository\CvProfileRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
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

    #[Route('/cv', name: 'app_cv', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $cvProfile = $this->cvProfiles->findLatest();

        try {
            return $this->handleUpload($request, $cvProfile);
        } catch (CvAlreadyUploaded) {
            return $request->isMethod('POST')
                ? $this->redirectToRoute('app_cv')
                : $this->render('cv/index.html.twig', ['cvProfile' => $cvProfile]);
        } catch (LlmModelNotSelected|ApiKeyNotSet $e) {
            // A refused submit must not answer 200: Turbo drops a successful form response that doesn't redirect
            $status = $request->isMethod('POST') ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

            return $this->render(
                'cv/index.html.twig',
                ['cvProfile' => $cvProfile, 'settings_missing' => LlmSettingsHint::from($e)],
                new Response(status: $status),
            );
        }
    }

    /**
     * @throws CvAlreadyUploaded
     * @throws LlmModelNotSelected
     * @throws ApiKeyNotSet
     */
    private function handleUpload(Request $request, ?CvProfile $cvProfile): Response
    {
        $this->cvService->assertCanUpload();

        $data = new CvUploadData();
        $form = $this->createForm(CvUploadType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $data->file;
            \assert(null !== $file);

            try {
                $uploaded = $this->cvService->upload(
                    new CvUpload($file->getPathname(), $file->getClientOriginalName()),
                );
                $uploadedId = $uploaded->getId();
                \assert(null !== $uploadedId);
                $this->bus->dispatch(new AnalyzeCv($uploadedId));
                $this->addFlash('success', 'cv.upload.uploaded');

                return $this->redirectToRoute('app_cv');
            } catch (InvalidCvFile) {
                $error = $this->translator->trans('cv.upload.invalid_file_name');
                $form->get('file')->addError(new FormError($error));
            }
        }

        return $this->render('cv/index.html.twig', ['cvProfile' => $cvProfile, 'form' => $form]);
    }
}
