<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Runs before the firewall (priority 8), so a request with a wrong or missing key is throttled too
 * and never reaches the key lookup in the DB.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
final readonly class IntakeApiRateLimitListener
{
    private const string API_FIREWALL = 'api';

    public function __construct(
        private Security $security,
        private RateLimiterFactoryInterface $intakeApiLimiter,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        // The error page of an API request is rendered in a sub-request of the same path: it must not count again
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (self::API_FIREWALL !== $this->security->getFirewallConfig($request)?->getName()) {
            return;
        }

        $limit = $this->intakeApiLimiter->create((string) $request->getClientIp())->consume();
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = (int) ceil((float) $limit->getRetryAfter()->format('U.u') - microtime(true));

        $event->setResponse(new JsonResponse(
            [
                'title' => $this->translator->trans('api.too_many_requests'),
                'status' => Response::HTTP_TOO_MANY_REQUESTS,
            ],
            Response::HTTP_TOO_MANY_REQUESTS,
            [
                'Content-Type' => 'application/problem+json',
                'Retry-After' => (string) max(1, $retryAfter),
            ],
        ));
    }
}
