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

namespace TheliaCMS\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\Lang;
use TheliaCMS\Content\ExampleRenderingInterface;
use TheliaCMS\Content\Partial\PartialSection;
use TheliaCMS\Content\Pipeline\ImageRewriter;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Front\ThemeTemplateRenderer;
use TheliaCMS\Http\CacheTags;
use TheliaCMS\Http\ShownTags;
use TheliaCMS\Locale\AlternateUrls;
use TheliaCMS\Media\MediaFile;
use TheliaCMS\Menu\CmsMenuProvider;
use TheliaCMS\Page\Document\PageDocumentBlock;
use TheliaCMS\Page\Document\PageDocumentLink;
use TheliaCMS\Page\Document\PageDocumentLinks;
use TheliaCMS\Page\Field\FieldFormatter;
use TheliaCMS\Page\PageCard;
use TheliaCMS\Page\PageImage;
use TheliaCMS\Page\PageListQuery;
use TheliaCMS\Page\PageTypeRepository;
use TheliaCMS\Page\PublishedPage;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\Partial\Definition\PageListPartial;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Settings\SiteIcon;
use TheliaCMS\TheliaCMS;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The template API of the module.
 *
 * It returns data, never markup: navigation markup belongs to the theme, and a
 * module that returned HTML would have to be fought instead of used.
 */
final class CmsExtension extends AbstractExtension
{
    public function __construct(
        private readonly CmsMenuProvider $menus,
        private readonly AlternateUrls $alternates,
        private readonly SiteIcon $icon,
        private readonly UrlGeneratorInterface $urls,
        private readonly CmsSettings $settings,
        private readonly PublishedPageRepository $pages,
        private readonly LangService $langService,
        private readonly SectionCatalog $sections,
        private readonly PageTypeRepository $pageTypes,
        private readonly FieldFormatter $fieldFormatter,
        private readonly ShownTags $shownTags,
        private readonly PageDocumentLinks $documentLinks,
        private readonly PageDocumentBlock $documentBlock,
        private readonly ThemeTemplateRenderer $templates,
        private readonly ImageRewriter $images,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            // The one function that answers markup: a section is markup by
            // definition, and the theme calls it to show a section outside a
            // page — in the toolkit, on a template of its own.
            new TwigFunction('cms_section', $this->section(...), ['is_safe' => ['html']]),
            new TwigFunction('cms_catalogue', $this->catalogue(...)),
            new TwigFunction('cms_menu', $this->menus->menu(...)),
            new TwigFunction('cms_page_alternates', $this->alternates->all(...)),
            new TwigFunction('cms_site_icon', $this->siteIcon(...)),
            // A theme has to be able to ask. On a showcase site the cart and the
            // checkout answer 404, and a header linking to them puts a dead link
            // on every page of the site.
            new TwigFunction('cms_is_showcase', $this->settings->isShowcase(...)),
            new TwigFunction('cms_pages_tagged', $this->pagesTagged(...)),
            new TwigFunction('cms_pages_of_type', $this->pagesOfType(...)),
            new TwigFunction('cms_page_type', $this->pageType(...)),
            new TwigFunction('cms_page_fields', $this->pageFields(...)),
            new TwigFunction('cms_page_documents', $this->pageDocuments(...)),
            new TwigFunction('cms_page_documents_block', $this->pageDocumentsBlock(...), ['is_safe' => ['html']]),
            new TwigFunction('cms_is_home', $this->isHome(...)),
            new TwigFunction('cms_image_attributes', $this->imageAttributes(...)),
        ];
    }

    /**
     * The attributes that make the image of a card responsive, as a page gets
     * them when it is published: `width`, `height`, `srcset` and `sizes`. A
     * theme prints them on its own `<img>`; none for an image without a
     * library file behind it.
     *
     * `sizes` is the width the image is drawn at, in the form of
     * `data-cms-sizes` (`(min-width: 64rem) 25vw, 50vw`); left out, the
     * viewport up to the width of the file. `height` is the fixed height it
     * is drawn at, if it has one.
     *
     * @return array<string, string>
     */
    public function imageAttributes(?PageImage $image, ?string $sizes = null, ?string $height = null): array
    {
        if (null === $image) {
            return [];
        }

        return $this->images->attributes(
            new MediaFile($image->id, strtolower(pathinfo($image->url, \PATHINFO_EXTENSION) ?: 'jpg'), $image->width, $image->height),
            $sizes,
            $height,
        );
    }

    /**
     * A section of the catalogue rendered with the given props, or with its
     * sample props when none are given: `cms_section('cms:richtext', {html: '…'})`.
     * An unknown section renders nothing.
     *
     * @param array<string, mixed>|null $props
     */
    public function section(string $name, ?array $props = null, ?string $locale = null): string
    {
        $definition = $this->sections->find($name);

        if (null === $definition) {
            return '';
        }

        // The toolkit asks for no props: a section that draws its example in
        // its own way (a picture of the theme) is asked to.
        if (null === $props && $definition instanceof ExampleRenderingInterface) {
            return $definition->renderExample($locale ?? $this->langService->getLocale());
        }

        return $definition->render(
            $definition->normalizeProps($props ?? $definition->sample()),
            $locale ?? $this->langService->getLocale(),
        );
    }

    /**
     * Every section the site offers, as the toolkit lists them: what
     * to call it, what it is for, and whether it renders on its own or is
     * live content the server fills when the page is served.
     *
     * @return list<array{name: string, label: string, description: string, live: bool}>
     */
    public function catalogue(): array
    {
        $catalogue = [];

        foreach ($this->sections->offered() as $definition) {
            $catalogue[] = [
                'name' => $definition->name(),
                'label' => $definition->label(),
                'description' => $definition->description(),
                'live' => str_starts_with($definition->name(), PartialSection::PREFIX),
            ];
        }

        return $catalogue;
    }

    /**
     * The pages online carrying a tag, the most recently published first, in
     * the language being served unless told otherwise. Data, not markup: what
     * a theme puts around a page is the theme's business.
     *
     * @return list<PageCard>
     */
    public function pagesTagged(string $tag, ?string $locale = null): array
    {
        $this->showsPageList();

        return $this->pages->withTag($tag, $locale ?? $this->currentLocale());
    }

    /**
     * The pages online of a type, the model left out, ordered, filtered and
     * cut as the options say (see PageListQuery):
     * `cms_pages_of_type('recipe', {sort: 'cooking_time', filter: {servings: {min: 4}}, limit: 6})`.
     *
     * @param array<string, mixed> $options sort, direction, filter, limit, offset
     *
     * @return list<PageCard>
     */
    public function pagesOfType(string $pageType, array $options = [], ?string $locale = null): array
    {
        $this->showsPageList();

        return $this->pages->ofType($pageType, $locale ?? $this->currentLocale(), PageListQuery::fromArray($options));
    }

    /**
     * What a heading of a list says about the type: its name and its
     * description in the language served.
     *
     * @return array{code: string, title: string, description: string|null}
     */
    public function pageType(string $pageType, ?string $locale = null): array
    {
        $locale ??= $this->currentLocale();
        $details = $this->pageTypes->details($pageType);

        return ['code' => $details->code, 'title' => $details->title($locale), 'description' => $details->description($locale)];
    }

    /**
     * The fields of a page or a card that hold a value, each with its label
     * and its value written for a visitor ("1 h 30 min"), in the order of
     * the type. `page.fields.<code>` is the raw value.
     *
     * @return list<array{code: string, label: string, value: int|float|string|bool, text: string}>
     */
    public function pageFields(PublishedPage|PageCard $page): array
    {
        if ([] === $page->fields) {
            return [];
        }

        return $this->fieldFormatter->rows($this->pageTypes->fieldsOf($page->pageType), $page->fields, $page->locale);
    }

    /**
     * The files attached to a page, in the order the editor set, with what
     * was written of them in the language served (never another language's):
     * `cms_page_documents(page)`. Data, not markup: how a file is listed is the
     * theme's business.
     *
     * Read when the page is served and not frozen with its HTML, so adding or
     * removing a file does not call for publishing again; the page is filed
     * in the shared cache under its tag, which the writer of the documents drops.
     * A page unknown or in the trash has no document.
     *
     * @return list<PageDocumentLink>
     */
    public function pageDocuments(PublishedPage|int|string|null $page, ?string $locale = null): array
    {
        $pageId = $page instanceof PublishedPage ? $page->id : (int) $page;

        if ($pageId <= 0) {
            return [];
        }

        // A template of another page may list these files: that page has to be
        // dropped with this one when they change.
        $this->shownTags->add(CacheTags::page($pageId));

        return $this->documentLinks->forPage($pageId, $locale ?? $this->currentLocale());
    }

    /**
     * The files of a page listed, markup included: `cms_page_documents_block(page)`.
     * Nothing when the page has no file, so a template calls it without a test.
     *
     * The template is `cms-page-documents.html.twig` at the root of the theme
     * when it ships one, the neutral one of the module otherwise; it receives
     * one variable, `cms_page_documents_block`: `documents` (see
     * PageDocumentBlock), `page` (what was given) and `heading`. One name only,
     * because the theme parser keeps every variable of a render for the rest of
     * the page. The language is the one given, else the one of the page when a
     * page is given, else the one of the request. The files are read through
     * `pageDocuments()`, which files the page under its cache tag.
     */
    public function pageDocumentsBlock(PublishedPage|int|string|null $page, ?string $locale = null): string
    {
        $locale ??= $page instanceof PublishedPage ? $page->locale : $this->currentLocale();
        $links = $this->pageDocuments($page, $locale);

        if ([] === $links) {
            return '';
        }

        $context = $this->documentBlock->context($links, $locale);

        return $this->templates->render('cms-page-documents', '@TheliaCMSModule/front/cms-page-documents.html.twig', [
            'cms_page_documents_block' => [
                'documents' => $context['documents'],
                'page' => $page,
                'heading' => $context['heading'],
            ],
        ]);
    }

    private function currentLocale(): string
    {
        return $this->langService->getLang()?->getLocale() ?? Lang::getDefaultLanguage()->getLocale();
    }

    /**
     * A list read from a template changes with the same pages as the block a
     * page holds, and is dropped with it.
     */
    private function showsPageList(): void
    {
        $this->shownTags->add(CacheTags::partial(PageListPartial::NAME));
    }

    /**
     * The address of the icon uploaded in the store configuration, or null when
     * there is none and the theme should fall back on its own.
     */
    public function siteIcon(): ?string
    {
        return $this->icon->exists() ? $this->urls->generate('cms.site_icon') : null;
    }

    /**
     * A theme passes the id of a page as it holds it, or nothing on a screen
     * that is not a page: a site with no home page set has none, whatever id.
     */
    public function isHome(int|string|null $id): bool
    {
        $home = (int) TheliaCMS::getConfigValue('home_page_id', 0);

        return $home > 0 && (int) $id === $home;
    }
}
