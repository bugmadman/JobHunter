<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Shared\Locale;
use App\Domain\User\UserServiceInterface;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class LocaleController extends AbstractController
{
    public function __construct(
        private readonly UserServiceInterface $users,
    ) {
    }

    #[Route('/locale', name: 'app_locale', methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        // Checked here, not with #[IsCsrfTokenValid]: its exception is an authentication failure,
        // which sends the logged-in owner to the login page instead of answering 403
        if (!$this->isCsrfTokenValid('locale', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $locale = Locale::tryFrom($request->request->getString('locale'))
            ?? throw new BadRequestHttpException('Unknown locale.');
        $this->users->changeLocale($user, $locale);

        $target = $request->request->getString('_target');

        // A path on this site only: "//host" and "/\host" are read by browsers as another host
        return 1 === preg_match('#^/(?![/\\\\])#', $target)
            ? $this->redirect($target)
            : $this->redirectToRoute('app_home');
    }
}
