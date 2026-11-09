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

namespace TheliaCMS\Front;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Hook\Theme\ThemeHookInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\Lang;
use TheliaCMS\Catalogue\PrintedDocuments;
use TheliaCMS\Http\PageCacheListener;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\TheliaCMS;
use Twig\Environment;

/**
 * Draws, for an administrator who may change pages, a button that opens the
 * content screen of what the site shows: the CMS page served (the home page
 * and the page shown on a missing address included), or the document of the
 * catalogue item the page is about.
 *
 * At the bottom of the body, so the documents of the page are printed by the
 * time it asks which one is there. The administrator is read from the
 * session the front office shares with the back office; behind a proxy that
 * drops that cookie, nobody is recognised and no button is drawn.
 *
 * Neither preview of the back office shows it: the preview of a page never
 * marks the page served, and the preview of an item prints a draft, which is
 * never recorded.
 */
final readonly class EditButtonThemeHook implements ThemeHookInterface
{
    public const string HOOK = 'layout.body.bottom';

    private const string TEMPLATE = '@TheliaCMSModule/front/edit-button.html.twig';

    public function __construct(
        private RequestStack $requests,
        private SecurityContext $securityContext,
        private PrintedDocuments $printedDocuments,
        private LangService $langService,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private Environment $twig,
    ) {
    }

    public function supports(string $hookName): bool
    {
        return self::HOOK === $hookName;
    }

    public function render(string $hookName, array $parameters): string
    {
        $request = $this->requests->getMainRequest();

        if (self::HOOK !== $hookName || null === $request) {
            return '';
        }

        // What the page shows first: it is read from the request, while the
        // administrator is reloaded from the database once per request.
        $target = $this->target($request);

        if (null === $target || !$this->maySeeTheButton()) {
            return '';
        }

        // The page now carries a link to the back office for this one person.
        $request->attributes->set(PageCacheListener::PERSONAL_ATTRIBUTE, true);

        return $this->twig->render(self::TEMPLATE, [
            'url' => $target['url'],
            'label' => $this->translator->trans($target['label'], [], TheliaCMS::DOMAIN_NAME),
        ]);
    }

    private function maySeeTheButton(): bool
    {
        // The guard of the module lets a reader open the screen; the button
        // says "edit", so it is drawn for whoever may change the pages. Only an
        // administrator in the session holds the role: a visitor, or a
        // customer, is refused here.
        return $this->securityContext->isGranted(['ADMIN'], [CmsResources::PAGE], [], [AccessManager::UPDATE]);
    }

    /**
     * @return array{url: string, label: string}|null
     */
    private function target(Request $request): ?array
    {
        $pageId = $request->attributes->getInt(TheliaCMS::SERVED_PAGE_ATTRIBUTE);

        if ($pageId > 0) {
            return [
                'url' => $this->urls->generate('admin.cms.pages.content.edit', [
                    'id' => $pageId,
                    EditLanguage::PARAMETER => $this->languageId(),
                ]),
                'label' => 'Edit this page',
            ];
        }

        $item = $this->printedDocuments->itemOfThePage($request);

        if (null === $item) {
            return null;
        }

        [$type, $id] = $item;

        return [
            'url' => $this->urls->generate('admin.cms.catalogue.content.edit', [
                'type' => $type->value,
                'id' => $id,
                EditLanguage::PARAMETER => $this->languageId(),
            ]),
            'label' => 'Edit the content of this page',
        ];
    }

    /** The translation being read is the one the screen opens on. */
    private function languageId(): int
    {
        return ($this->langService->getLang() ?? Lang::getDefaultLanguage())->getId();
    }
}
