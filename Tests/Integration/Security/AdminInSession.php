<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TheliaCMS\Tests\Integration\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Thelia\Model\Admin;

/**
 * Puts an administrator in the session of every request the test client sends,
 * the way a login would, without going through the login form.
 *
 * Runs after the session is started and before the guards of the back office
 * read it.
 */
final class AdminInSession implements EventSubscriberInterface
{
    private ?Admin $admin = null;

    // Signed out after requests made signed in: the administrator they left
    // in the session is taken out of it, as signing out does.
    private bool $signedOut = false;

    public function logIn(Admin $admin): void
    {
        $admin->eraseCredentials();
        $this->admin = $admin;
        $this->signedOut = false;
    }

    public function logOut(): void
    {
        $this->admin = null;
        $this->signedOut = true;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($this->signedOut && $event->getRequest()->hasSession(true)) {
            $event->getRequest()->getSession()->remove('thelia.admin_user');

            return;
        }

        if (null === $this->admin || !$event->getRequest()->hasSession(true)) {
            return;
        }

        $session = $event->getRequest()->getSession();

        if (!$session->isStarted()) {
            $session->start();
        }

        $session->set('thelia.admin_user', $this->admin);
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 200]];
    }
}
