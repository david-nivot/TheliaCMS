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

namespace TheliaCMS\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sends the Content-Security-Policy on the pages the module renders, and on
 * those only.
 *
 * The pages of the shop (cart, payment, an account) are another module's or
 * the theme's: a payment frame or a widget of theirs would be blocked by a
 * policy written for content pages. What renders a page of the module marks
 * the request with `mark()`; the policy goes on that response, unless one is
 * already there: a header set by the theme or another module is kept.
 */
final readonly class ContentSecurityPolicyListener
{
    public const string ATTRIBUTE = '_cms_content_security_policy';

    public function __construct(
        private ContentSecurityPolicySettings $settings,
        private UrlGeneratorInterface $urls,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public static function mark(Request $request): void
    {
        $request->attributes->set(self::ATTRIBUTE, true);
    }

    /**
     * Before the default headers of the core (-1024), after everything that
     * renders.
     */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -1020)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || true !== $event->getRequest()->attributes->get(self::ATTRIBUTE)) {
            return;
        }

        $policy = ContentSecurityPolicy::for(
            $this->settings->mode(),
            $this->settings->hosts(),
            $this->urls->generate('cms.csp_report'),
        );

        if (null === $policy) {
            return;
        }

        $headers = $event->getResponse()->headers;

        if ($headers->has($policy->header)) {
            $this->logger?->notice('TheliaCMS: the page {path} already has a {header} header, set by the theme or another module; the policy of the module is not sent.', [
                'path' => $event->getRequest()->getPathInfo(),
                'header' => $policy->header,
            ]);

            return;
        }

        $headers->set($policy->header, $policy->value);
    }
}
