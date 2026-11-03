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

namespace TheliaCMS\Hook;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Template\Parser\ParserResolver;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\TheliaCMS;
use Twig\Environment;

/**
 * A link from each screen of the CMS to the section of the documentation that
 * explains it, above the content of the screen.
 *
 * One hook rather than a button in every page header: the screens draw their
 * headers several ways, and a screen added later gets its link from the table
 * below, or the link to the top of the documentation.
 */
class CmsDocumentationLinkHook extends BaseHook
{
    /**
     * The part of the documentation explaining each screen, by the start of
     * its route name; the longest start that matches wins. The ids are shared
     * by every language of the documentation: a `<section id>` or an
     * `<h3 id>`, both reached by the same fragment.
     */
    private const array SECTIONS = [
        'admin.cms.pages.content.' => 'content',
        'admin.cms.pages.' => 'pages',
        'admin.cms.pages.trash' => 'trash',
        'admin.cms.catalogue.' => 'catalogue',
        'admin.cms.tags.' => 'tags',
        'admin.cms.settings.page_types.' => 'page-templates',
        'admin.cms.settings.' => 'settings',
        'admin.cms.components.' => 'components',
        'admin.cms.skills.' => 'skills',
        'admin.cms.menus.' => 'menus',
        'admin.cms.forms.' => 'forms',
        'admin.cms.media.' => 'media',
    ];

    public function __construct(
        private readonly SecurityContext $securityContext,
        private readonly UrlGeneratorInterface $urls,
        // Read on every rendering: BaseHook keeps the first request it is
        // given, and a worker serving several would link them all to its section.
        private readonly RequestStack $requests,
        private readonly Environment $twig,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    public static function getSubscribedHooks(): array
    {
        return [
            'main.before-content' => [
                ['type' => 'back', 'method' => 'onMainBeforeContent'],
            ],
        ];
    }

    public function onMainBeforeContent(HookRenderEvent $event): void
    {
        $route = (string) $this->requests->getMainRequest()?->attributes->get('_route');

        if (!str_starts_with($route, 'admin.cms.') || 'admin.cms.documentation' === $route) {
            return;
        }

        // The documentation takes the right to see the pages: no link to a 403.
        if (!$this->securityContext->isGranted(['ADMIN'], [CmsResources::PAGE], [], [AccessManager::VIEW])) {
            return;
        }

        $section = self::sectionOf($route);

        $event->add($this->twig->render('@TheliaCMSModule/backOffice/default-twig/documentation/_link.html.twig', [
            'url' => $this->urls->generate('admin.cms.documentation').(null === $section ? '' : '#'.$section),
            'label' => $this->trans('Help on this screen', [], TheliaCMS::DOMAIN_NAME),
        ]));
    }

    public static function sectionOf(string $route): ?string
    {
        $found = null;
        $length = 0;

        foreach (self::SECTIONS as $start => $section) {
            if (str_starts_with($route, $start) && \strlen($start) > $length) {
                $found = $section;
                $length = \strlen($start);
            }
        }

        return $found;
    }
}
