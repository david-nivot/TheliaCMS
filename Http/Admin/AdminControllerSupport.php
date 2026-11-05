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

namespace TheliaCMS\Http\Admin;

use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\SecurityContext;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaCMS\TheliaCMS;

/**
 * What every back-office screen of the module does the same way: translate a
 * message in the module's domain, leave a flash message, check a permission,
 * send the editor back to a screen.
 *
 * Injected into the controllers rather than inherited, so a controller keeps
 * its own constructor and states what it depends on.
 */
final readonly class AdminControllerSupport
{
    public function __construct(
        private TranslatorInterface $translator,
        private SecurityContext $securityContext,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function translate(string $message, array $parameters = []): string
    {
        return $this->translator->trans($message, $parameters, TheliaCMS::DOMAIN_NAME);
    }

    /**
     * A request without a session (a test client, the console) loses the
     * message instead of failing the action that produced it.
     */
    public function flash(Request $request, string $type, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }

    /**
     * Every error of the form, one flash message each, as the form already
     * translated it; a form that was not sent at all says so.
     */
    public function flashFormErrors(Request $request, FormInterface $form): void
    {
        foreach ($form->getErrors(true) as $error) {
            if ($error instanceof FormError) {
                $this->flash($request, 'danger', $error->getMessage());
            }
        }

        if (!$form->isSubmitted()) {
            $this->flash($request, 'danger', $this->translate('Nothing was sent.'));
        }
    }

    public function isGranted(string $resource, string $access): bool
    {
        return $this->securityContext->isGranted(['ADMIN'], [$resource], [], [$access]);
    }

    /**
     * @param string $refusal the English message, translated in the module's domain
     */
    public function denyUnless(string $resource, string $access, string $refusal): void
    {
        if (!$this->isGranted($resource, $access)) {
            throw new AccessDeniedHttpException($this->translate($refusal));
        }
    }

    /**
     * @param array<string, mixed> $parameters
     * @param string|null          $fragment   the anchor of the screen to open, without its "#"
     */
    public function redirect(string $route, array $parameters = [], ?string $fragment = null): RedirectResponse
    {
        $url = $this->urls->generate($route, $parameters);

        return new RedirectResponse(null === $fragment ? $url : $url.'#'.$fragment);
    }

    /**
     * Back to a screen in the language the editor was working in.
     *
     * @param int                  $languageId the id of the edited language, as EditLanguage resolved it
     * @param array<string, mixed> $parameters
     */
    public function redirectInLanguage(string $route, int $languageId, array $parameters = [], ?string $fragment = null): RedirectResponse
    {
        return $this->redirect($route, [...$parameters, EditLanguage::PARAMETER => $languageId], $fragment);
    }
}
