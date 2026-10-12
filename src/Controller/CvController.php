<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Cv\CvServiceInterface;
use App\Domain\Cv\CvUpload;
use App\Domain\Cv\InvalidCvFile;
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
use Symfony\Component\Routing\Requirement\Requirement;
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

    #[Route('/cv/{id}', name: 'app_cv_show', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function show(int $id): Response
    {
        $cvProfile = $this->cvProfiles->findById($id) ?? throw $this->createNotFoundException();

        return $this->render('cv/show.html.twig', ['cvProfile' => $cvProfile]);
    }
}
