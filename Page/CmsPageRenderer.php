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

namespace TheliaCMS\Page;

use TheliaCMS\Front\ThemeTemplateRenderer;
use TheliaCMS\Partial\PartialRenderer;

/**
 * Renders a CMS page with the template of its type: see PageTemplateResolver
 * for the order the theme and the module are tried in.
 *
 * The dynamic blocks of the page are resolved here rather than at publish time —
 * a news list stored in the page would be the news of the day it was published.
 */
final readonly class CmsPageRenderer
{
    public const string CONTENT_THEME_TEMPLATE = 'cms-page-content-preview';

    public const string CONTENT_MODULE_TEMPLATE = '@TheliaCMSModule/front/cms-page-content-preview.html.twig';

    public function __construct(
        private ThemeTemplateRenderer $templates,
        private PageTemplateResolver $templateResolver,
        private PartialRenderer $partials,
    ) {
    }

    public function render(PublishedPage $page): string
    {
        $html = $this->partials->substitute($page->html, $page->locale);

        return $this->templates->renderTemplate($this->templateResolver->resolve($page->pageType)->name, [
            'cms_page' => $page->withHtml((string) $html),
        ]);
    }

    /**
     * The sections of the page alone, in the layout of the theme: what the
     * preview shows when asked for the content only. A theme draws it its own
     * way by shipping `cms-page-content-preview.html.twig` at its root.
     */
    public function renderContent(PublishedPage $page): string
    {
        $html = $this->partials->substitute($page->html, $page->locale);

        return $this->templates->render(self::CONTENT_THEME_TEMPLATE, self::CONTENT_MODULE_TEMPLATE, [
            'cms_page' => $page->withHtml((string) $html),
        ]);
    }
}
