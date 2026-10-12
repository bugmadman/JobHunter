<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Runs after the firewall (priority 8), so the user is known; that is after Symfony's locale listeners too,
 * so the locale goes through the switcher, which updates the translator and every other locale-aware service.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final readonly class UserLocaleListener
{
    public function __construct(
        private Security $security,
        private LocaleSwitcher $localeSwitcher,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $locale = $user->getLocale()->value;
        $event->getRequest()->setLocale($locale);
        $this->localeSwitcher->setLocale($locale);
    }
}
