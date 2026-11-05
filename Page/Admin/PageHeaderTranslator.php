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

use Propel\Runtime\Propel;
use Thelia\Core\Security\SecurityContext;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Content\Pipeline\PublishedContentSanitizer;
use TheliaCMS\Generation\HeaderOutcome;
use TheliaCMS\Generation\PageHeader;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Page\CmsUrlService;
use TheliaCMS\Page\PageInvalidator;
use TheliaCMS\Page\ReservedSlugException;

/**
 * Writes the header a translation proposed for a page, in the language it was
 * written in.
 *
 * It only ever fills: a column the page holds something in, in that language,
 * is left as the editor wrote it, for the header is live as soon as it is
 * saved and keeps no revision to put back. The address follows the same rule:
 * derived from the title, under the address of the parent in that language,
 * only where the language has none (no address, no slug an editor chose, no
 * fixed address), so a page that is online at an address never moves.
 */
final readonly class PageHeaderTranslator
{
    public function __construct(
        private CmsUrlService $urls,
        private SecurityContext $securityContext,
        private CmsActivityLog $activityLog,
        private PublishedContentSanitizer $sanitizer,
        private PageInvalidator $invalidator,
        private ContentWriter $content,
    ) {
    }

    public function write(CmsPage $page, string $locale, PageHeader $proposed): HeaderOutcome
    {
        $proposals = $this->filtered($proposed);

        if ([] === $proposals) {
            return new HeaderOutcome([], [], null);
        }

        $connection = Propel::getConnection('TheliaMain');
        $connection->beginTransaction();

        try {
            $page->setLocale($locale);
            $written = [];
            $kept = [];

            foreach ($proposals as $column => $text) {
                if ('' !== trim((string) $page->{'get'.self::property($column)}())) {
                    $kept[] = $column;

                    continue;
                }

                $page->{'set'.self::property($column)}($text);
                $written[] = $column;
            }

            if ([] !== $written) {
                $page->setUpdatedBy($this->securityContext->getAdminUser()?->getId())->save($connection);
            }

            [$address, $parentNotAddressed] = $this->address($page, $locale);

            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }

        $this->invalidate($page, $written, HeaderOutcome::ADDRESS_WRITTEN === $address);

        if ([] !== $written || HeaderOutcome::ADDRESS_WRITTEN === $address) {
            $this->activityLog->record('UPDATE', (int) $page->getId(), \sprintf('CMS page #%d header (%s) written in %s', $page->getId(), implode(', ', [...$written, ...(HeaderOutcome::ADDRESS_WRITTEN === $address ? ['address'] : [])]), $locale));
        }

        return new HeaderOutcome($written, $kept, $address, $parentNotAddressed);
    }

    /**
     * What is proposed, column by column, as it would be written: the summary
     * and the description through the filter an edited page goes through, and
     * what comes out empty left out.
     *
     * @return array<string, string>
     */
    private function filtered(PageHeader $proposed): array
    {
        $mayPublishCustomCode = $this->content->mayPublishCustomCode();
        $texts = [];

        foreach ($proposed->words() as $column => $text) {
            $text = \in_array($column, ['chapo', 'description'], true) ? trim((string) $this->sanitizer->html($text, $mayPublishCustomCode)) : $text;

            if ('' !== $text) {
                $texts[$column] = $text;
            }
        }

        return $texts;
    }

    /**
     * Gives the language its first address, from the title it holds now.
     *
     * @return array{0: string|null, 1: bool} the state of the address, and whether it was derived under a parent that has none in this language
     */
    private function address(CmsPage $page, string $locale): array
    {
        // A model is not a page of the site, and without a title there is
        // nothing to derive an address from.
        if (1 === $page->getModel() || '' === trim((string) $page->setLocale($locale)->getTitle())) {
            return [null, false];
        }

        if ('' !== (string) $page->getRewrittenUrl($locale) || '' !== trim((string) $page->getSlug()) || null !== $this->urls->fixedAddressOf($page, $locale)) {
            return [HeaderOutcome::ADDRESS_KEPT, false];
        }

        try {
            $this->urls->refresh($page, $locale);
        } catch (ReservedSlugException) {
            return [HeaderOutcome::ADDRESS_RESERVED, false];
        }

        // The pages under it written in this language before it had an address
        // answer at the root of that language until this runs.
        $this->urls->rebuildDescendants($page, $locale);
        $parent = (int) $page->getParent();

        return [HeaderOutcome::ADDRESS_WRITTEN, $parent > 0 && '' === (string) CmsPageQuery::create()->findPk($parent)?->getRewrittenUrl($locale)];
    }

    /**
     * @param list<string> $written
     */
    private function invalidate(CmsPage $page, array $written, bool $addressWritten): void
    {
        if ([] === $written && !$addressWritten) {
            return;
        }

        // A title or an address that appeared is what the links to the page and
        // the breadcrumbs under it show; the rest is on the page and in lists.
        if ($addressWritten || \in_array('title', $written, true)) {
            $this->invalidator->presenceChanged((int) $page->getId());

            return;
        }

        $this->invalidator->contentChanged((int) $page->getId());
    }

    private static function property(string $column): string
    {
        return str_replace('_', '', ucwords($column, '_'));
    }
}
