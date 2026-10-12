<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Cv\CvServiceInterface;
use App\Domain\Cv\CvUpload;
use App\Domain\Cv\InvalidCvFile;
use App\Form\CvUploadData;
use App\Form\CvUploadType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CvController extends AbstractController
{
    public function __construct(
        private readonly CvServiceInterface $cvService,
        private readonly TranslatorInterface $translator,
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
                $this->cvService->upload(new CvUpload($file->getPathname(), $file->getClientOriginalName()));
                $this->addFlash('success', 'cv.upload.uploaded');

                return $this->redirectToRoute('app_cv_upload');
            } catch (InvalidCvFile) {
                $error = $this->translator->trans('cv.upload.invalid_file_name');
                $form->get('file')->addError(new FormError($error));
            }
        }

        return $this->render('cv/upload.html.twig', ['form' => $form]);
    }
}
