<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\User\UserServiceInterface;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[AdminRoute('/api-key', name: 'api_key')]
final class ApiKeyController extends AbstractController
{
    public function __construct(
        private readonly UserServiceInterface $users,
    ) {
    }

    #[AdminRoute('/', name: 'index', options: ['methods' => ['GET']])]
    public function index(#[CurrentUser] User $user): Response
    {
        return $this->render('admin/api_key.html.twig', ['has_key' => $user->hasApiKey(), 'new_key' => null]);
    }

    #[AdminRoute('/regenerate', name: 'regenerate', options: ['methods' => ['POST']])]
    public function regenerate(Request $request, #[CurrentUser] User $user): Response
    {
        // Checked here, not with #[IsCsrfTokenValid]: its exception is an authentication failure,
        // which sends the logged-in owner to the login page instead of answering 403
        if (!$this->isCsrfTokenValid('api_key_regenerate', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $apiKey = $this->users->regenerateApiKey($user);

        // Rendered, not redirected with a flash: the key would otherwise sit in the session storage
        $response = $this->render('admin/api_key.html.twig', ['has_key' => true, 'new_key' => $apiKey->value()]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
