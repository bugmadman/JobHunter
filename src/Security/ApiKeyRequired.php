<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A missing key (entry point) and a wrong one (failure handler) get the same answer,
 * so a client can't tell which check failed.
 */
final readonly class ApiKeyRequired implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->unauthorized();
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->unauthorized();
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(
            [
                'title' => $this->translator->trans('api.unauthorized'),
                'status' => Response::HTTP_UNAUTHORIZED,
            ],
            Response::HTTP_UNAUTHORIZED,
            [
                'Content-Type' => 'application/problem+json',
                'WWW-Authenticate' => 'Bearer',
            ],
        );
    }
}
