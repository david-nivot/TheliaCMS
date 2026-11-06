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
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use TheliaCMS\Dashboard\ShowcaseStats;
use TheliaCMS\Page\SampleTextPageFinder;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\TheliaCMS;
use Twig\Environment;

/**
 * A dashboard block for a site with no shop.
 *
 * The core dashboard counts orders and turnover, so on a showcase site it is a
 * screen of zeros. This adds what there is to count instead, and only in
 * showcase mode: on a shop, the shop numbers are the ones that matter.
 */
class CmsDashboardHook extends BaseHook
{
    public function __construct(
        private readonly SecurityContext $securityContext,
        private readonly UrlGeneratorInterface $urls,
        private readonly Environment $twig,
        private readonly CmsSettings $settings,
        private readonly ShowcaseStats $stats,
        private readonly SampleTextPageFinder $sampleTextPages,
        // Read on every rendering: BaseHook keeps the first request it is given.
        private readonly RequestStack $requests,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    public static function getSubscribedHooks(): array
    {
        return [
            // `home.top`, not `home.block`: the blocks go below the shop
            // charts, and on a site with no shop those charts are a screen of
            // zeros the reader has to scroll past first.
            'home.top' => [
                ['type' => 'back', 'method' => 'onHomeBlock'],
            ],
        ];
    }

    public function onHomeBlock(HookRenderEvent $event): void
    {
        if (!$this->settings->isShowcase()) {
            return;
        }

        if (!$this->securityContext->isGranted(['ADMIN'], [CmsResources::PAGE], [], [AccessManager::VIEW])) {
            return;
        }

        // The locale of the interface, not the one on the admin account: the
        // language switcher of the back office changes the first and leaves the
        // second alone, and page titles beside French labels read as a bug.
        $locale = $this->localeOfTheInterface();
        $stats = $this->stats->collect($locale);

        $event->add($this->twig->render('@TheliaCMSModule/backOffice/default-twig/dashboard/block.html.twig', [
            'stats' => $stats,
            'locale' => $locale,
            // A site can have gone online with the pages the installer seeded
            // still saying "replace this text". Publishing them is refused now,
            // but a site set up before that is still showing them.
            'sample_text_pages' => $this->sampleTextPages->publishedPagesStillHoldingSampleText($locale),
            'title' => $this->trans('The site', [], TheliaCMS::DOMAIN_NAME),
            'pages_url' => $this->urls->generate('admin.cms.pages.list'),
            'forms_url' => $this->urls->generate('admin.cms.forms.list'),
        ]));
    }

    private function localeOfTheInterface(): string
    {
        $requested = (string) $this->requests->getMainRequest()?->getLocale();

        foreach (LangQuery::create()->filterByActive(1)->find() as $lang) {
            $locale = (string) $lang->getLocale();

            if ($locale === $requested || str_starts_with($locale, $requested.'_')) {
                return $locale;
            }
        }

        return Lang::getDefaultLanguage()->getLocale();
    }
}
