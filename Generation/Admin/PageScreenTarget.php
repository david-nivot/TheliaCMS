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

namespace TheliaCMS\Generation\Admin;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Model\Lang;
use TheliaCMS\Catalogue\Admin\ContentTargets;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentTarget;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Content\Editing\TranslationFreshness;
use TheliaCMS\Content\Editing\TranslationLink;
use TheliaCMS\Content\Editing\TranslationSource;
use TheliaCMS\Content\Site\SiteComponentPermission;
use TheliaCMS\Generation\Alongside;
use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Generation\GenerationTarget;
use TheliaCMS\Generation\GenerationTargets;
use TheliaCMS\Generation\HeaderOutcome;
use TheliaCMS\Generation\PageHeader;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Page\Admin\AccessibilityException;
use TheliaCMS\Page\Admin\CmsPageAdminRepository;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaCMS\Page\Admin\EmptyPageContentException;
use TheliaCMS\Page\Admin\HeadingStructureException;
use TheliaCMS\Page\Admin\MissingPageFieldsException;
use TheliaCMS\Page\Admin\ModelPagePublicationException;
use TheliaCMS\Page\Admin\PlaceholderPageContentException;
use TheliaCMS\Page\PageTypeCode;
use TheliaCMS\Page\PageTypeRepository;
use TheliaCMS\Preview\PreviewLink;

/**
 * A page on the content screen: its fields and the search engine proposal
 * the generator writes along with the document, the component it may make
 * for the page, the model of a type that is never published.
 */
final readonly class PageScreenTarget implements ContentScreenTarget
{
    /** The routes of the screen, by the name the templates ask for; the last two are served by `CmsPageComponentController`. */
    private const array ROUTES = [
        'generate' => 'admin.cms.pages.content.generate',
        'revise' => 'admin.cms.pages.content.revise',
        'section' => 'admin.cms.pages.content.section',
        'update' => 'admin.cms.pages.content.update',
        'remove' => 'admin.cms.pages.content.remove',
        'restore' => 'admin.cms.pages.content.restore',
        'move' => 'admin.cms.pages.content.move',
        'duplicate' => 'admin.cms.pages.content.duplicate',
        'add' => 'admin.cms.pages.content.add',
        'translate' => 'admin.cms.pages.content.translate',
        'translation' => 'admin.cms.pages.content.translation',
        'publish' => 'admin.cms.pages.content.publish',
        'component_generate' => 'admin.cms.pages.content.component.generate',
        'component_keep' => 'admin.cms.pages.content.component.keep',
    ];

    public function __construct(
        private CmsPage $page,
        private Lang $lang,
        private UrlGeneratorInterface $urls,
        private AdminControllerSupport $support,
        private CmsPageAdminRepository $pages,
        private CmsPageWriter $pageWriter,
        private ContentWriter $writer,
        private ContentTargets $contentTargets,
        private GenerationTargets $generationTargets,
        private PreviewLink $previewLinks,
        private TranslationSources $translationSources,
        private TranslationFreshness $freshness,
        private PageTypeRepository $pageTypes,
        private SiteComponentPermission $componentPermission,
    ) {
    }

    public function lang(): Lang
    {
        return $this->lang;
    }

    public function locale(): string
    {
        return (string) $this->lang->getLocale();
    }

    public function screenUrl(): string
    {
        return $this->urls->generate('admin.cms.pages.content.edit', $this->parameters());
    }

    public function subject(): ContentSubject
    {
        $locale = $this->locale();
        $this->page->setLocale($locale);
        $status = $this->pages->statusOf($this->page, $locale);
        $parameters = $this->parameters();
        $routes = ['screen' => $this->screenUrl()];

        foreach (self::ROUTES as $action => $name) {
            $routes[$action] = $this->urls->generate($name, $parameters);
        }

        return new ContentSubject(
            target: $this->generationTarget(),
            locale: $locale,
            languageId: (int) $this->lang->getId(),
            statusLabel: $status->label(),
            statusBadgeClass: $status->badgeClass(),
            backUrl: $this->urls->generate('admin.cms.pages.edit', $parameters),
            backLabel: $this->support->translate('Back to settings'),
            draftPreviewUrl: $this->previewLinks->contentUrlFor((int) $this->page->getId(), $locale),
            previewUrl: $this->page->getUrl($locale) ?: null,
            document: $this->draftDocument(),
            publishHint: $this->support->translate('Publishing puts the draft online at the address of the page.'),
            routes: $routes,
            testId: 'cms-page-content',
            translationSources: $this->translationSources(),
            publishable: 1 !== $this->page->getModel(),
            draftPagePreviewUrl: $this->previewLinks->urlFor((int) $this->page->getId(), $locale),
            template: $this->template($locale),
            previousDrafts: $this->writer->previousDrafts($this->contentTarget()),
            translationLink: $this->translationLink(),
        );
    }

    public function contentTarget(): ContentTarget
    {
        return $this->contentTargets->forPage($this->page, $this->locale());
    }

    public function generationTarget(): GenerationTarget
    {
        return $this->generationTargets->forPage($this->page, $this->locale());
    }

    public function draftDocument(): ?Document
    {
        return $this->pages->draftDocument($this->page, $this->locale());
    }

    public function draftHtml(): ?string
    {
        return $this->pages->draftHtml($this->page, $this->locale());
    }

    public function translationSources(): array
    {
        return $this->translationSources->forPage((int) $this->page->getId(), $this->locale());
    }

    public function translationSource(string $sourceLocale): ?TranslationSource
    {
        return $this->translationSources->pageSource((int) $this->page->getId(), $sourceLocale);
    }

    public function translationLink(): ?TranslationLink
    {
        return $this->freshness->link($this->contentTarget(), fn (string $locale): ContentTarget => $this->contentTargets->forPage($this->page, $locale));
    }

    public function followTranslation(string $sourceLocale): bool
    {
        return $this->freshness->follow($this->contentTarget(), $this->contentTargets->forPage($this->page, $sourceLocale));
    }

    public function recordTranslation(string $sourceLocale, TranslationSource $source): void
    {
        $this->freshness->record($this->contentTarget(), $sourceLocale, $source);
    }

    /**
     * The generator may make a component of the site for this page when
     * whoever asks may make one; the settings have the last word.
     */
    public function prepare(GenerationRequest $generation): GenerationRequest
    {
        return $generation->withComponentMaking($this->componentPermission->mayMake());
    }

    public function translationHeader(string $sourceLocale): ?PageHeader
    {
        return $this->translationSources->pageHeader((int) $this->page->getId(), $sourceLocale);
    }

    public function saveAlongside(Alongside $alongside): ?HeaderOutcome
    {
        $locale = $this->locale();

        if (null !== $alongside->fields && [] !== $alongside->fields) {
            $this->pageWriter->saveFields($this->page, $locale, $alongside->fields);
        }

        // Only where the page has none in this language.
        if ([] !== $alongside->seo) {
            $this->pageWriter->saveSeo($this->page, $locale, $alongside->seo);
        }

        // Only the columns the page holds nothing in, and an address only where
        // the language has none.
        return null === $alongside->header ? null : $this->pageWriter->saveTranslatedHeader($this->page, $locale, $alongside->header);
    }

    public function nothingToChange(): string
    {
        return $this->support->translate('This page holds nothing to change yet: write it from a brief first.');
    }

    public function invalidWhenPublishing(): string
    {
        return $this->support->translate('The page was not published: its content is no longer valid. Open it in the editor to see what to fix.');
    }

    public function online(): string
    {
        return $this->support->translate('The page is online.');
    }

    public function explainRefusal(\Throwable $refusal): ?array
    {
        return match (true) {
            $refusal instanceof HeadingStructureException => [...$refusal->problems, $this->support->translate('The page was not published: fix the heading structure first.')],
            $refusal instanceof AccessibilityException => [...$refusal->problems, $this->support->translate('The page was not published: fix the accessibility problems first.')],
            $refusal instanceof EmptyPageContentException => [$this->support->translate('This page has no content yet: write it from a brief before publishing it.')],
            $refusal instanceof PlaceholderPageContentException => [$this->support->translate('This page still holds the sample text it was created with: write your own before publishing it.')],
            $refusal instanceof ModelPagePublicationException => [$this->support->translate('This page is the model of its type: it is never published. Start a new page from it instead.')],
            $refusal instanceof MissingPageFieldsException => [$this->support->translate('Fill in the fields this type requires before publishing: %fields%.', ['%fields%' => implode(', ', $refusal->labels)])],
            default => null,
        };
    }

    /**
     * @return array<string, int|string|null>
     */
    private function parameters(): array
    {
        return ['id' => $this->page->getId(), EditLanguage::PARAMETER => $this->lang->getId()];
    }

    /**
     * The template of pages the page belongs to, named and linked to its
     * settings; nothing for a page of the default type.
     *
     * @return array{label: string, url: string}|null
     */
    private function template(string $locale): ?array
    {
        $code = (string) $this->page->getPageType();

        if ('' === $code || PageTypeCode::DEFAULT === $code || !$this->pageTypes->exists($code)) {
            return null;
        }

        return [
            'label' => $this->pageTypes->details($code)->title($locale),
            'url' => $this->urls->generate('admin.cms.settings.page_types.edit', ['code' => $code]),
        ];
    }
}
