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

namespace TheliaCMS\Tests\Integration\Page;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Page\Admin\PageStatus;
use TheliaCMS\Page\Admin\PageStatusCriteria;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * The two descriptions of the publication state have to agree.
 *
 * PageStatus::resolve() is the one the rest of the module trusts; the SQL in
 * PageStatusCriteria exists so the listing can keep only the drafts before it
 * counts them and cuts a page of fifty rows. Two descriptions of one rule agree
 * until somebody changes one of them, so this builds a page in each of the five
 * states and asserts that both answers name the same one.
 */
final class PageStatusCriteriaTest extends CmsIntegrationTestCase
{
    use ClockSensitiveTrait;

    public function testTheDatabaseAndThePhpNameTheSameStateForEveryPage(): void
    {
        $locale = $this->locale();
        $pages = $this->onePageInEachState($locale);

        self::assertCount(5, $pages, 'the fixture does not cover the five states');

        foreach ($pages as $expected => $page) {
            $state = PageStatus::from($expected);

            $selected = $this->idsSelectedBy($state, $locale);

            self::assertContains(
                (int) $page->getId(),
                $selected,
                \sprintf('the database does not see page #%d as "%s"', $page->getId(), $expected),
            );

            // And the state resolved in PHP is the same one, on the same row.
            self::assertSame(
                $state,
                PageStatus::resolve($page, $this->contentOf($page, $locale)),
                \sprintf('PHP does not see page #%d as "%s"', $page->getId(), $expected),
            );
        }
    }

    public function testAPageIsSelectedByExactlyOneState(): void
    {
        $locale = $this->locale();
        $pages = $this->onePageInEachState($locale);
        $ids = array_map(static fn (CmsPage $page): int => (int) $page->getId(), $pages);

        foreach (PageStatus::cases() as $state) {
            $selected = array_intersect($this->idsSelectedBy($state, $locale), $ids);

            self::assertCount(
                1,
                $selected,
                \sprintf('"%s" selects %d of the five pages instead of one', $state->value, \count($selected)),
            );
        }
    }

    public function testAPageJustPublishedIsNotModifiedSincePublication(): void
    {
        // The clock is frozen far from the real time: the date of the last
        // edit has to come from the same instant as the publication date, not
        // from a second reading of the clock when the row is saved (a second
        // turning in between made a page read "modified" at once).
        self::mockTime('2020-01-15 10:00:00');
        $locale = $this->locale();
        $page = $this->createPage('Criteria instant', $locale);
        $content = $this->contentOf($page, $locale);

        self::assertEquals($content?->getPublishedAt(), $content?->getUpdatedAt());
        self::assertSame(PageStatus::Published, PageStatus::resolve($page, $content));
        self::assertContains((int) $page->getId(), $this->idsSelectedBy(PageStatus::Published, $locale));
        self::assertNotContains((int) $page->getId(), $this->idsSelectedBy(PageStatus::ModifiedSincePublish, $locale));
    }

    public function testAskingForSeveralStatesSelectsTheirUnion(): void
    {
        $locale = $this->locale();
        $pages = $this->onePageInEachState($locale);

        $selected = $this->idsSelectedByAny([PageStatus::Draft, PageStatus::Scheduled], $locale);

        self::assertContains((int) $pages[PageStatus::Draft->value]->getId(), $selected);
        self::assertContains((int) $pages[PageStatus::Scheduled->value]->getId(), $selected);
        self::assertNotContains((int) $pages[PageStatus::Published->value]->getId(), $selected);
    }

    public function testAskingForNoStateSelectsEverything(): void
    {
        $locale = $this->locale();
        $page = $this->createPage('Criteria no filter', $locale);

        self::assertContains((int) $page->getId(), $this->idsSelectedByAny([], $locale));
    }

    /**
     * One page in each of the five states, keyed by the state it is meant to be in.
     *
     * @return array<string, CmsPage>
     */
    private function onePageInEachState(string $locale): array
    {
        $now = new \DateTimeImmutable();

        $draft = $this->createPage('Criteria draft', $locale, published: false);

        $published = $this->createPage('Criteria published', $locale);

        $scheduled = $this->createPage('Criteria scheduled', $locale);
        $scheduled->setPublishAt($now->modify('+10 days'))->save();

        $unpublished = $this->createPage('Criteria unpublished', $locale, visible: false);

        $modified = $this->createPage('Criteria modified', $locale);
        // Touched after the snapshot went live: what visitors see is no longer
        // what the editor last saved.
        $this->contentOf($modified, $locale)
            ?->setDraftHtml('<p>rewritten</p>')
            ->setUpdatedAt($now->modify('+1 hour'))
            ->save();

        return [
            PageStatus::Draft->value => $draft,
            PageStatus::Published->value => $published,
            PageStatus::Scheduled->value => $scheduled,
            PageStatus::Unpublished->value => $unpublished,
            PageStatus::ModifiedSincePublish->value => $modified,
        ];
    }

    /**
     * @return list<int>
     */
    private function idsSelectedBy(PageStatus $state, string $locale): array
    {
        return $this->idsSelectedByAny([$state], $locale);
    }

    /**
     * @param list<PageStatus> $states
     *
     * @return list<int>
     */
    private function idsSelectedByAny(array $states, string $locale): array
    {
        $query = CmsPageQuery::create()->filterByDeletedAt(null, Criteria::ISNULL);

        PageStatusCriteria::restrictTo(
            PageStatusCriteria::joinContent($query, $locale),
            $states,
            new \DateTimeImmutable(),
        );

        return array_map(intval(...), $query->select(['Id'])->find()->toArray());
    }

    private function contentOf(CmsPage $page, string $locale): ?\TheliaCMS\Model\CmsPageContent
    {
        return CmsPageContentQuery::create()
            ->filterByPageId($page->getId())
            ->filterByLocale($locale)
            ->findOne();
    }
}
