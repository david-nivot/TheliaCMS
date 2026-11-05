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

namespace TheliaCMS\Page\Admin;

use Propel\Runtime\Connection\ConnectionInterface;
use TheliaCMS\Content\Editing\ContentKey;
use TheliaCMS\Content\Editing\ContentRow;
use TheliaCMS\Content\Editing\ContentTarget;
use TheliaCMS\Content\Editing\RevisionStore;
use TheliaCMS\Content\Pipeline\PlaceholderContentChecker;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContent;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageSearch;
use TheliaCMS\Model\CmsPageSearchQuery;
use TheliaCMS\Page\CmsUrlService;
use TheliaCMS\Page\Field\PageFields;
use TheliaCMS\Page\PageInvalidator;
use TheliaCMS\Search\SearchTextExtractor;

/**
 * A page in one language, for the shared writer: what only a page refuses
 * (the model of its type, the fields its type requires, the sample text of a
 * seeded page), the search index written with the publication, and the
 * invalidation of what shows the page.
 */
final readonly class PageContentTarget implements ContentTarget
{
    public function __construct(
        private CmsPage $page,
        private string $locale,
        private CmsActivityLog $activityLog,
        // Menus, lists of pages and a shared cache in front of the site hold
        // what a write changes, and none of them can know on its own.
        private PageInvalidator $invalidator,
        private PageFields $fields,
        private PlaceholderContentChecker $placeholders,
        private SearchTextExtractor $searchText,
        private PageRevisionStore $revisions,
        private CmsUrlService $urls,
    ) {
    }

    public function key(): ContentKey
    {
        return ContentKey::page($this->id(), $this->locale);
    }

    public function row(): ContentRow
    {
        $content = CmsPageContentQuery::create()
            ->filterByPageId($this->page->getId())
            ->filterByLocale($this->locale)
            ->findOne();

        return new PageContentRow($content ?? (new CmsPageContent())->setPageId($this->page->getId())->setLocale($this->locale));
    }

    public function revisions(): RevisionStore
    {
        return $this->revisions;
    }

    public function refuseBeforeRendering(): void
    {
        if (1 === $this->page->getModel()) {
            throw ModelPagePublicationException::for($this->id());
        }

        // Checked before the content: a list of the type sorts and filters
        // on these values, and a page without them has no place in it.
        $definitions = $this->fields->definitionsOf($this->page);
        $missing = $definitions->missingIn($this->fields->valuesOf($this->page, $this->locale, $definitions));

        if ([] !== $missing) {
            throw MissingPageFieldsException::for($this->id(), $this->locale, array_map(fn ($field): string => $field->label($this->locale), $missing));
        }
    }

    public function refuseRendered(string $html): void
    {
        // The legal pages are seeded as drafts holding instructions, on purpose.
        // Putting that text online tells visitors, and search engines, things
        // about the site that are not true. Refused in the one place every
        // publication goes through, so that a command asked to publish
        // everything cannot undo the precaution.
        $sentence = $this->placeholders->placeholderSentenceIn($html);

        if (null !== $sentence) {
            throw PlaceholderPageContentException::for($this->id(), $this->locale, $sentence);
        }
    }

    public function emptyContent(): \Throwable
    {
        return EmptyPageContentException::for($this->id(), $this->locale);
    }

    public function notAKeptDraft(int $revisionId): \InvalidArgumentException
    {
        return new \InvalidArgumentException(\sprintf('Revision #%d is not a kept draft of page #%d in %s.', $revisionId, $this->id(), $this->locale));
    }

    public function brokenHeadings(array $problems): \Throwable
    {
        return HeadingStructureException::for($this->id(), $this->locale, $problems);
    }

    public function inaccessible(array $problems): \Throwable
    {
        return AccessibilityException::for($this->id(), $this->locale, $problems);
    }

    /**
     * Refreshes the full-text payload of the page, so the front-office search
     * queries plain text and never the HTML columns, and gives the page the
     * address it lacks in this language.
     */
    public function duringPublication(string $html, ConnectionInterface $connection): void
    {
        $this->addressIfMissing();

        $index = CmsPageSearchQuery::create()
            ->filterByPageId($this->page->getId())
            ->filterByLocale($this->locale)
            ->findOne()
            ?? (new CmsPageSearch())->setPageId($this->page->getId())->setLocale($this->locale);

        $this->page->setLocale($this->locale);

        $index->setContent(trim($this->page->getTitle().' '.$this->searchText->extract($html)))
            ->save($connection);
    }

    /**
     * The address of a page is written when its settings are saved in a
     * language, so a page written in another language from the content screen
     * reaches publication without one. Given here, by the same rules as on the
     * settings screen, together with the first address of the pages under it.
     */
    private function addressIfMissing(): void
    {
        $this->page->setLocale($this->locale);

        if ('' !== trim((string) $this->page->getRewrittenUrl($this->locale))) {
            return;
        }

        $this->urls->refresh($this->page, $this->locale);
        $this->urls->rebuildDescendants($this->page, $this->locale);
    }

    public function afterSaved(): void
    {
        $this->activityLog->record('UPDATE', $this->id(), \sprintf('CMS page #%d content edited in %s', $this->id(), $this->locale));
    }

    public function afterPublished(bool $wasOnline, bool $withCustomCode): void
    {
        // Published again, the page only changes at its own address; for
        // the first time in this language, it appears in its menus too.
        if ($wasOnline) {
            $this->invalidator->contentChanged($this->id());
        } else {
            $this->invalidator->presenceChanged($this->id());
        }

        $this->activityLog->record('PUBLISH', $this->id(), \sprintf(
            'CMS page #%d published in %s%s',
            $this->id(),
            $this->locale,
            // Traced: this page went online through the wider allow list.
            $withCustomCode ? ' (custom code allowed)' : '',
        ));
    }

    public function afterRenderedAgain(): void
    {
        $this->invalidator->contentChanged($this->id());
    }

    public function afterUnpublished(bool $wasOnline): void
    {
        if ($wasOnline) {
            $this->invalidator->presenceChanged($this->id());
        }

        $this->activityLog->record('UNPUBLISH', $this->id(), \sprintf('CMS page #%d unpublished in %s', $this->id(), $this->locale));
    }

    private function id(): int
    {
        return (int) $this->page->getId();
    }
}
