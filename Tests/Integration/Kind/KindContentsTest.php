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

namespace TheliaCMS\Tests\Integration\Kind;

use TheliaCMS\Content\Kind\ContentKinds;
use TheliaCMS\Content\Kind\KindContentDeletionRefusedException;
use TheliaCMS\Content\Kind\KindContentNotFoundException;
use TheliaCMS\Content\Kind\UnknownContentKindException;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\Map\CmsPageContentTableMap;
use TheliaCMS\Model\Map\CmsPageI18nTableMap;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\Admin\CmsPageAdminRepository;
use TheliaCMS\Page\Admin\PageStatus;
use TheliaCMS\Page\Kind\KindContents;
use TheliaCMS\Page\TrashPurger;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Integration\CountsStatements;
use TheliaCMS\Tests\Support\GuideKind;
use TheliaCMS\Tests\Support\RecordingCachePurger;
use TheliaCMS\Zone\HeaderZone;
use TheliaCMS\Zone\HeaderZoneKind;

/**
 * What a module does with its contents of a kind: make, find, read what is
 * published, send to the bin. The kinds are declared by the test (see
 * `DeclaresTestKinds`) next to the header zone.
 */
final class KindContentsTest extends CmsIntegrationTestCase
{
    use CountsStatements;
    use DeclaresTestKinds;

    /** @var array{guide: GuideKind, kept: GuideKind, header: HeaderZoneKind} */
    private array $kinds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kinds = $this->declareTestKinds();
    }

    /**
     * Sabotage: drop `->setContentKind($kind->code())` from `KindContents::create()`:
     * `find()` finds nothing and the kind assertion is red.
     */
    public function testACreatedContentIsAHiddenPageOfItsKindWithNoAddress(): void
    {
        $content = $this->contents()->create('test_guide', ['fr_FR' => 'Guide des blousons', 'en_US' => 'Jackets guide']);

        CmsPageTableMap::clearInstancePool();
        $stored = CmsPageQuery::create()->findPk($content->getId());
        self::assertNotNull($stored);
        self::assertSame(2, $stored->getModel());
        self::assertSame('test_guide', $stored->getContentKind());
        self::assertSame(0, $stored->getVisible(), 'Hidden.');
        self::assertSame(0, $stored->getParent(), 'At the root, under no page.');
        self::assertSame('Guide des blousons', $stored->getTranslation('fr_FR')->getTitle());
        self::assertSame('Jackets guide', $stored->getTranslation('en_US')->getTitle());
        self::assertSame(0, (int) $this->getPropelConnection()->query(\sprintf("SELECT COUNT(*) FROM rewriting_url WHERE view = 'cmspage' AND view_id = %d", $content->getId()))->fetchColumn(), 'No address.');
        self::assertSame((int) $content->getId(), (int) $this->contents()->find('test_guide', (int) $content->getId())?->getId());
    }

    public function testACreationWithoutKindOrWithoutTitleIsRefused(): void
    {
        try {
            $this->contents()->create('ghost', ['fr_FR' => 'Fantôme']);
            self::fail('No module declares the kind.');
        } catch (UnknownContentKindException $exception) {
            self::assertStringContainsString('"ghost"', $exception->getMessage());
        }

        $before = CmsPageQuery::create()->count();

        try {
            $this->contents()->create('test_guide', ['fr_FR' => '  ', 'en_US' => '']);
            self::fail('A content needs a title.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, CmsPageQuery::create()->count(), 'Nothing written.');
        }
    }

    /**
     * Sabotage: drop `filterByContentKind()` from `KindContents::query()`: the
     * other kind and the header zone come back in `all()` and `find()`.
     */
    public function testReadsLeaveOutTheOtherKindsThePagesAndTheBin(): void
    {
        $first = $this->kindContent('test_guide', 'Premier guide', published: false);
        $second = $this->kindContent('test_guide', 'Second guide', published: false);
        $kept = $this->kindContent('test_kept', 'Guide gardé', published: false);
        $zone = $this->getService(HeaderZone::class)->ensure();
        $page = $this->createPage('Page ordinaire', published: false);

        self::assertSame([(int) $first->getId(), (int) $second->getId()], $this->ids($this->contents()->all('test_guide')));
        self::assertSame([(int) $kept->getId()], $this->ids($this->contents()->all('test_kept')));
        self::assertSame([(int) $zone->getId()], $this->ids($this->contents()->all('header')), 'The zone is the content of the kind header.');

        self::assertNotNull($this->contents()->find('test_guide', (int) $first->getId()));
        self::assertNull($this->contents()->find('test_guide', (int) $kept->getId()), 'The content of another kind is not found by this one.');
        self::assertNull($this->contents()->find('test_guide', (int) $zone->getId()));
        self::assertNull($this->contents()->find('test_guide', (int) $page->getId()), 'Nor a page.');
        self::assertNull($this->contents()->find('test_guide', 0));

        $this->contents()->moveToBin('test_guide', (int) $first->getId());

        self::assertNull($this->contents()->find('test_guide', (int) $first->getId()), 'Nor what is in the bin.');
        self::assertSame([(int) $second->getId()], $this->ids($this->contents()->all('test_guide')));

        $this->expectException(UnknownContentKindException::class);
        $this->contents()->all('ghost');
    }

    public function testTheRegistryNamesTheKindOfAContentAndNothingElse(): void
    {
        $registry = $this->getService(ContentKinds::class);
        $guide = $this->kindContent('test_guide', 'Un guide', published: false);
        $page = $this->createPage('Une page', published: false);

        self::assertSame($this->kinds['guide'], $registry->of($guide));
        self::assertNull($registry->of($page), 'A page has no kind.');

        $this->getPropelConnection()->exec(\sprintf("UPDATE cms_page SET content_kind = 'ghost' WHERE id = %d", $guide->getId()));
        CmsPageTableMap::clearInstancePool();
        self::assertNull($registry->of(CmsPageQuery::create()->findPk($guide->getId()) ?? new CmsPage()), 'A kind no module declares names nothing.');

        $this->getPropelConnection()->exec(\sprintf("UPDATE cms_page SET model = 0, content_kind = 'test_guide' WHERE id = %d", $guide->getId()));
        CmsPageTableMap::clearInstancePool();
        self::assertNull($registry->of(CmsPageQuery::create()->findPk($guide->getId()) ?? new CmsPage()), 'The kind is read on a hidden page only.');
    }

    /**
     * Sabotage: drop `filterByPublishedAt(null, Criteria::ISNOTNULL)` from
     * `KindContents::publishedHtml()`: the draft is returned before publishing.
     */
    public function testThePublishedHtmlIsReadInTheLanguageOfTheKindAndOutOfTheBinOnly(): void
    {
        $content = $this->kindContent('test_guide', 'Un guide', html: '<h2>Tailles</h2><p>Mesurez votre tour de taille.</p>', published: false);
        $id = (int) $content->getId();
        $other = 'fr_FR' === $this->locale() ? 'en_US' : 'fr_FR';

        self::assertNull($this->contents()->publishedHtml('test_guide', $id, $this->locale()), 'A draft is not published.');

        $this->writer()->publish($content, $this->locale());
        CmsPageContentTableMap::clearInstancePool();

        $html = $this->contents()->publishedHtml('test_guide', $id, $this->locale());
        self::assertNotNull($html);
        self::assertStringContainsString('Mesurez votre tour de taille.', $html);
        self::assertNull($this->contents()->publishedHtml('test_guide', $id, $other), 'One publication per language.');
        self::assertNull($this->contents()->publishedHtml('test_kept', $id, $this->locale()), 'Asked as another kind.');
        self::assertNull($this->contents()->publishedHtml('test_guide', $id + 1000, $this->locale()));

        // A row that holds markup but no publication date is not published.
        $this->getPropelConnection()->exec(\sprintf('UPDATE cms_page_content SET published_at = NULL WHERE page_id = %d', $id));
        CmsPageContentTableMap::clearInstancePool();
        self::assertNull($this->contents()->publishedHtml('test_guide', $id, $this->locale()), 'The date says whether it is online.');
        $this->getPropelConnection()->exec(\sprintf('UPDATE cms_page_content SET published_at = NOW() WHERE page_id = %d', $id));
        CmsPageContentTableMap::clearInstancePool();

        $this->contents()->moveToBin('test_guide', $id);

        self::assertNull($this->contents()->publishedHtml('test_guide', $id, $this->locale()), 'Nothing is read from the bin.');
    }

    /**
     * Sabotage: remove `->setDeletedAt(...)` from `KindContents::moveToBin()`:
     * `find()` still finds the content and the purge deletes nothing.
     */
    public function testAContentGoesToTheBinThenTheBinPurgeDeletesIt(): void
    {
        $content = $this->kindContent('test_guide', 'Guide à jeter');
        $id = (int) $content->getId();
        $repository = $this->getService(CmsPageAdminRepository::class);
        $inTheBin = $repository->countInTrash();
        $purger = new RecordingCachePurger();

        $this->purgingWith(KindContents::class, $purger)->moveToBin('test_guide', $id);

        CmsPageTableMap::clearInstancePool();
        self::assertNotNull(CmsPageQuery::create()->findPk($id)?->getDeletedAt(), 'Binned.');
        self::assertNull($this->contents()->find('test_guide', $id));
        self::assertContains(GuideKind::tagOf($id), $purger->purged, 'The cache of what shows it is dropped.');
        self::assertSame($inTheBin, $repository->countInTrash(), 'The bin of the pages does not count it: its module brings it back.');
        self::assertNotContains($id, array_map(static fn (array $row): int => (int) $row['page']->getId(), $repository->trash($this->locale())), 'Nor does it list it.');

        $this->getService(TrashPurger::class)->purge(new \DateTimeImmutable('+40 days'));

        CmsPageTableMap::clearInstancePool();
        self::assertNull(CmsPageQuery::create()->findPk($id), 'The purge of the bin deletes it once its retention is over.');
    }

    public function testAContentThatIsNotThereOrAlreadyBinnedIsNotFound(): void
    {
        $content = $this->kindContent('test_guide', 'Guide', published: false);
        $other = $this->kindContent('test_kept', 'Autre genre', published: false);
        $id = (int) $content->getId();

        foreach ([['test_guide', $id + 1000], ['test_guide', (int) $other->getId()]] as [$code, $target]) {
            try {
                $this->contents()->moveToBin($code, $target);
                self::fail('There is no such content of the kind.');
            } catch (KindContentNotFoundException) {
                self::addToAssertionCount(1);
            }
        }

        $this->contents()->moveToBin('test_guide', $id);

        $this->expectException(KindContentNotFoundException::class);
        $this->contents()->moveToBin('test_guide', $id);
    }

    /**
     * Sabotage: drop the `null !== $refusal` branch of `KindContents::moveToBin()`:
     * the guarded content and the zone go to the bin.
     */
    public function testAKindThatRefusesTheBinKeepsItsContentsAndTheZoneIsOne(): void
    {
        $kept = $this->kindContent('test_kept', 'Guide protégé', published: false);
        $zone = $this->getService(HeaderZone::class)->ensure();

        try {
            $this->contents()->moveToBin('test_kept', (int) $kept->getId());
            self::fail('The kind refuses the bin.');
        } catch (KindContentDeletionRefusedException $exception) {
            self::assertSame(GuideKind::DELETION_REFUSAL, $exception->getMessage());
            self::assertSame('test_kept', $exception->kind);
        }

        try {
            $this->contents()->moveToBin('header', (int) $zone->getId());
            self::fail('The header zone is never deleted.');
        } catch (KindContentDeletionRefusedException $exception) {
            self::assertSame($this->kinds['header']->deletionRefusal(), $exception->getMessage());
            self::assertNotSame('', $exception->getMessage());
        }

        CmsPageTableMap::clearInstancePool();
        self::assertNull(CmsPageQuery::create()->findPk($kept->getId())?->getDeletedAt());
        self::assertNull(CmsPageQuery::create()->findPk($zone->getId())?->getDeletedAt());
    }

    /**
     * Sabotage: remove `setDeletedAt(null)` from `KindContents::restore()`: the
     * content stays in the bin and the second block is red. Drop
     * `filterByDeletedAt(..., ISNOTNULL)` from `query()` for `binned`: `binned()`
     * lists the live ones and the first block is red.
     */
    public function testABinnedContentIsListedByItsModuleAndBroughtBackAsItWas(): void
    {
        $gone = $this->kindContent('test_guide', 'Guide à rendre', html: '<h2>Tailles</h2><p>Texte gardé en corbeille.</p>');
        $stays = $this->kindContent('test_guide', 'Guide resté', published: false);
        $other = $this->kindContent('test_kept', 'Autre genre', published: false);
        $id = (int) $gone->getId();
        $purger = new RecordingCachePurger();

        self::assertSame([], $this->contents()->binned('test_guide'));
        $this->contents()->moveToBin('test_guide', $id);

        self::assertSame([$id], $this->ids($this->contents()->binned('test_guide')), 'The bin of the kind lists what was binned of the kind.');
        self::assertSame([], $this->contents()->binned('test_kept'), 'Not what another kind binned.');
        self::assertNull($this->contents()->publishedHtml('test_guide', $id, $this->locale()));

        $restored = $this->purgingWith(KindContents::class, $purger)->restore('test_guide', $id);

        self::assertSame($id, (int) $restored->getId());
        self::assertSame([], $this->contents()->binned('test_guide'));
        self::assertContains(GuideKind::tagOf($id), $purger->purged, 'The cache of what shows it is dropped.');
        CmsPageTableMap::clearInstancePool();
        self::assertNull(CmsPageQuery::create()->findPk($id)?->getDeletedAt());
        self::assertStringContainsString('Texte gardé en corbeille.', (string) $this->contents()->publishedHtml('test_guide', $id, $this->locale()), 'Its publication was kept.');
        self::assertSame([(int) $id, (int) $stays->getId()], $this->ids($this->contents()->all('test_guide')));

        foreach ([['test_guide', $id], ['test_guide', (int) $other->getId()], ['test_kept', (int) $other->getId()]] as [$code, $target]) {
            try {
                $this->contents()->restore($code, $target);
                self::fail('Nothing of that kind is in the bin.');
            } catch (KindContentNotFoundException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * Sabotage: remove `joinWithI18n()` from `KindContents::all()`: each title
     * costs a query of its own and the count is no longer 1.
     */
    public function testTheTitlesOfAllTheContentsAreReadInTheSameQuery(): void
    {
        foreach (['Un', 'Deux', 'Trois'] as $title) {
            $this->kindContent('test_guide', 'Guide '.$title, published: false);
        }

        $this->forgetWhatWasRead([CmsPageTableMap::class, CmsPageI18nTableMap::class]);
        $locale = $this->locale();
        $titles = [];

        $statements = $this->statementsFor(function () use ($locale, &$titles): void {
            foreach ($this->contents()->all('test_guide', $locale) as $content) {
                $titles[] = $content->setLocale($locale)->getTitle();
            }
        });

        self::assertSame(['Guide Un', 'Guide Deux', 'Guide Trois'], $titles);
        self::assertSame(1, $statements, 'One query, whatever the number of contents.');
    }

    /**
     * Sabotage: make `statusOfContent()` read `PageStatus::resolve($page, $content)`:
     * a published content, hidden as its page is, reads "unpublished" and the
     * second assertion is red.
     */
    public function testTheStateOfAContentIsReadFromItsContentAlone(): void
    {
        $repository = $this->getService(CmsPageAdminRepository::class);
        $content = $this->kindContent('test_guide', 'Guide', published: false);

        self::assertSame(PageStatus::Draft, $repository->statusOfContent($content, $this->locale()));

        $this->writer()->publish($content, $this->locale());
        CmsPageContentTableMap::clearInstancePool();
        self::assertSame(0, $content->getVisible(), 'Hidden, as every content of a kind is.');
        self::assertSame(PageStatus::Published, $repository->statusOfContent($content, $this->locale()));

        $this->getPropelConnection()->exec(\sprintf('UPDATE cms_page_content SET updated_at = DATE_ADD(published_at, INTERVAL 1 HOUR) WHERE page_id = %d', $content->getId()));
        CmsPageContentTableMap::clearInstancePool();
        self::assertSame(PageStatus::ModifiedSincePublish, $repository->statusOfContent($content, $this->locale()), 'Written since.');
    }

    private function contents(): KindContents
    {
        return $this->getService(KindContents::class);
    }

    /**
     * @param list<CmsPage> $pages
     *
     * @return list<int>
     */
    private function ids(array $pages): array
    {
        return array_map(static fn (CmsPage $page): int => (int) $page->getId(), $pages);
    }
}
