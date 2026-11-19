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

use Thelia\Model\ModuleConfigQuery;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Kind\PageCannotBeAdoptedException;
use TheliaCMS\Content\Kind\UnknownContentKindException;
use TheliaCMS\Http\CacheTags;
use TheliaCMS\Menu\MenuCache;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsMenu;
use TheliaCMS\Model\CmsMenuItem;
use TheliaCMS\Model\CmsMenuItemQuery;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContent;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsPageSearchQuery;
use TheliaCMS\Model\Map\CmsPageContentTableMap;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\Kind\KindContents;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\GuideKind;
use TheliaCMS\Tests\Support\RecordingCachePurger;
use TheliaCMS\TheliaCMS;

/**
 * An ordinary page taken in as a content of a kind, in place: the id, the
 * texts, the versions and the publications stay; the address, the lines of the
 * search and the entries of the menus go. What a page of the site could not
 * lose without leaving something behind (children, the home page) refuses.
 */
final class AdoptPageTest extends CmsIntegrationTestCase
{
    use DeclaresTestKinds;

    private const string MENU = 'adopt-test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareTestKinds();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // The rollback takes back the home page a test chose, not the copy kept in memory.
        ModuleConfigQuery::resetConfigCache();
    }

    /**
     * Sabotage: skip the `RewritingUrlQuery ... ->delete()`, the `CmsPageSearchQuery`
     * delete or `removeFromMenus()` in `KindContents::adopt()`: the address, the
     * search line or the entry assertion is red. Replace `->setModel(...)` by
     * nothing: the model assertion is red.
     */
    public function testAPageBecomesAContentInPlaceAndLosesWhatMadeItAPage(): void
    {
        $page = $this->createPage('Guide des tailles', html: '<h2>Guide des tailles</h2><p>Tableau des mesures.</p>');
        $id = (int) $page->getId();
        $this->writer()->saveContent($page, $this->locale(), Document::richText('<h2>Guide des tailles</h2><p>Brouillon une.</p>'));
        $this->writer()->keepDraft($page, $this->locale());
        $this->writer()->saveContent($page, $this->locale(), Document::richText('<h2>Guide des tailles</h2><p>Brouillon deux.</p>'));
        $menu = $this->menu();
        $entry = $this->entry($menu, 'page', $id, 'Guide');
        $below = $this->entry($menu, 'url', null, 'Sous le guide', parent: $entry);
        $elsewhere = $this->entry($menu, 'page', (int) $this->createPage('Autre page')->getId(), 'Autre page');

        $published = (string) $this->content($id)?->getPublishedHtml();
        self::assertStringContainsString('Tableau des mesures.', $published);
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM rewriting_url WHERE view = 'cmspage' AND view_id = %d", $id), 'Before: the page has an address,');
        self::assertSame(1, CmsPageSearchQuery::create()->filterByPageId($id)->count(), 'a line of the search,');
        $drafts = $this->writer()->previousDrafts($page, $this->locale());
        self::assertNotEmpty($drafts, 'and a version.');
        $purger = new RecordingCachePurger();

        $adopted = $this->purgingWith(KindContents::class, $purger)->adopt($page, 'test_guide');

        self::assertSame($id, (int) $adopted->getId(), 'Same id.');
        CmsPageTableMap::clearInstancePool();
        CmsPageContentTableMap::clearInstancePool();
        $stored = CmsPageQuery::create()->findPk($id);
        self::assertNotNull($stored);
        self::assertSame(2, $stored->getModel());
        self::assertSame('test_guide', $stored->getContentKind());
        self::assertSame(0, $stored->getVisible());
        self::assertSame(0, $stored->getParent());
        self::assertSame('Guide des tailles', $stored->getTranslation($this->locale())->getTitle(), 'Same texts.');
        self::assertSame($published, (string) $this->content($id)?->getPublishedHtml(), 'The published document is the same.');
        self::assertSame($published, $this->getService(KindContents::class)->publishedHtml('test_guide', $id, $this->locale()), 'And the module reads it.');
        self::assertStringContainsString('Brouillon deux.', (string) $this->content($id)?->getDraftHtml(), 'The draft is kept.');
        self::assertEquals($drafts, $this->writer()->previousDrafts($stored, $this->locale()), 'The versions are kept.');

        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM rewriting_url WHERE view = 'cmspage' AND view_id = %d", $id), 'After: no address,');
        self::assertSame(0, CmsPageSearchQuery::create()->filterByPageId($id)->count(), 'no line of the search,');
        self::assertNull(CmsMenuItemQuery::create()->findPk($entry->getId()), 'no entry of a menu.');
        self::assertNotNull(CmsMenuItemQuery::create()->findPk($elsewhere->getId()), 'The entry of another page stays.');
        $kept = CmsMenuItemQuery::create()->findPk($below->getId());
        self::assertNotNull($kept, 'What hung under the entry stays in the menu,');
        self::assertSame(0, $kept->getParent(), 'under the parent of the entry that went.');

        self::assertContains(CacheTags::page($id), $purger->purged, 'The cache of the page is dropped, as for the bin.');
        self::assertContains(CacheTags::MENUS, $purger->purged, 'The menus are drawn on every page.');
        self::assertContains(GuideKind::tagOf($id), $purger->purged, 'And what the kind shows it in.');
    }

    /**
     * Sabotage: delete the `filterByParent($id)->exists()` check of
     * `KindContents::adopt()`: the child is left under a page that is no page,
     * and the first case is red.
     */
    public function testAPageWithPagesUnderItIsRefusedAndLeftAsItWas(): void
    {
        $parent = $this->createPage('Parente');
        $child = $this->createPage('Enfant', parent: (int) $parent->getId());

        $this->assertRefused($parent, 'test_guide', 'pages under it');

        // A child in the bin still names its parent, and comes back under it.
        $this->writer()->moveToTrash($child);
        $this->assertRefused($parent, 'test_guide', 'pages under it');
    }

    public function testTheHomePageIsRefused(): void
    {
        $home = $this->createPage('Accueil du test');
        $this->writer()->setAsHome($home);

        $this->assertRefused($home, 'test_guide', 'home page');
    }

    public function testOnlyAnOrdinaryPageOutOfTheBinIsAdopted(): void
    {
        $model = $this->createPage('Modèle', published: false);
        $this->getPropelConnection()->exec(\sprintf('UPDATE cms_page SET model = 1 WHERE id = %d', $model->getId()));
        CmsPageTableMap::clearInstancePool();
        $model = CmsPageQuery::create()->findPk($model->getId()) ?? new CmsPage();
        $this->assertRefused($model, 'test_guide', 'only an ordinary page', expectedModel: 1);

        $content = $this->kindContent('test_guide', 'Déjà un guide', published: false);
        $this->assertRefused($content, 'test_kept', 'only an ordinary page', expectedModel: 2);

        $binned = $this->createPage('À la corbeille');
        $this->writer()->moveToTrash($binned);
        CmsPageTableMap::clearInstancePool();
        $this->assertRefused(CmsPageQuery::create()->findPk($binned->getId()) ?? new CmsPage(), 'test_guide', 'in the bin');
    }

    public function testAnUnknownKindIsRefusedAndLeavesThePageAlone(): void
    {
        $page = $this->createPage('Pour un genre fantôme');

        try {
            $this->getService(KindContents::class)->adopt($page, 'ghost');
            self::fail('No module declares the kind.');
        } catch (UnknownContentKindException) {
            CmsPageTableMap::clearInstancePool();
            self::assertSame(0, CmsPageQuery::create()->findPk($page->getId())?->getModel());
            self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM rewriting_url WHERE view = 'cmspage' AND view_id = %d", (int) $page->getId()), 'Its address is still there.');
        }
    }

    /**
     * Sabotage: remove the `isLive()` check of `KindContents::adopt()`: the
     * hidden page, the one not yet in its window and the one past it come out
     * as published contents, and the first three refusals are red.
     */
    public function testAnOfflinePageHoldingAPublishedVersionIsRefusedButADraftIsNot(): void
    {
        $hidden = $this->createPage('Cachée et publiée');
        $this->getPropelConnection()->exec(\sprintf('UPDATE cms_page SET visible = 0 WHERE id = %d', $hidden->getId()));
        $early = $this->createPage('Pas encore en ligne');
        $this->getPropelConnection()->exec(\sprintf('UPDATE cms_page SET publish_at = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE id = %d', $early->getId()));
        $past = $this->createPage('Plus en ligne');
        $this->getPropelConnection()->exec(\sprintf('UPDATE cms_page SET unpublish_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = %d', $past->getId()));
        CmsPageTableMap::clearInstancePool();

        foreach ([$hidden, $early, $past] as $page) {
            $this->assertRefused(CmsPageQuery::create()->findPk($page->getId()) ?? new CmsPage(), 'test_guide', 'is offline');
        }

        // Nothing published, nothing that could come out published: a hidden draft is adopted.
        $draft = $this->createPage('Brouillon caché', published: false, visible: false);
        $this->getService(KindContents::class)->adopt($draft, 'test_guide');
        CmsPageTableMap::clearInstancePool();
        self::assertSame(2, CmsPageQuery::create()->findPk($draft->getId())?->getModel());
    }

    /**
     * Sabotage: remove `->setPublishAt(null)->setUnpublishAt(null)` from
     * `KindContents::adopt()`: the dates stay on the content and this is red.
     */
    public function testTheDatesOfPublicationDoNotFollowAPageIntoAContent(): void
    {
        $page = $this->createPage('Page à fenêtre');
        $this->getPropelConnection()->exec(\sprintf('UPDATE cms_page SET publish_at = DATE_SUB(NOW(), INTERVAL 1 DAY), unpublish_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = %d', $page->getId()));
        CmsPageTableMap::clearInstancePool();

        $this->getService(KindContents::class)->adopt(CmsPageQuery::create()->findPk($page->getId()) ?? new CmsPage(), 'test_guide');

        CmsPageTableMap::clearInstancePool();
        $stored = CmsPageQuery::create()->findPk($page->getId());
        self::assertNull($stored?->getPublishAt());
        self::assertNull($stored?->getUnpublishAt());
    }

    /**
     * Sabotage: remove one of the three checks (404 page, maintenance page,
     * privacy policy of a form) from `KindContents::adopt()`: the matching
     * refusal is red.
     */
    public function testAPageTheSiteStillPointsAtIsRefused(): void
    {
        $notFound = $this->createPage('Page 404');
        TheliaCMS::setConfigValue(CmsSettings::NOT_FOUND_PAGE, (string) $notFound->getId());
        $this->assertRefused($notFound, 'test_guide', 'does not exist');

        $maintenance = $this->createPage('Page de maintenance');
        TheliaCMS::setConfigValue(CmsSettings::MAINTENANCE_PAGE, (string) $maintenance->getId());
        $this->assertRefused($maintenance, 'test_guide', 'maintenance');

        $privacy = $this->createPage('Politique de confidentialité');
        (new CmsForm())->setCode('adopt-privacy-'.bin2hex(random_bytes(4)))->setPrivacyPolicyPageId((int) $privacy->getId())->save();
        $this->assertRefused($privacy, 'test_guide', 'privacy policy');
    }

    private function assertRefused(CmsPage $page, string $code, string $reason, int $expectedModel = 0): void
    {
        $id = (int) $page->getId();
        $addresses = $this->countRows("SELECT COUNT(*) FROM rewriting_url WHERE view = 'cmspage' AND view_id = %d", $id);

        try {
            $this->getService(KindContents::class)->adopt($page, $code);
            self::fail(\sprintf('Page #%d should have been refused (%s).', $id, $reason));
        } catch (PageCannotBeAdoptedException $exception) {
            self::assertStringContainsString($reason, $exception->getMessage());
        }

        CmsPageTableMap::clearInstancePool();
        self::assertSame($expectedModel, CmsPageQuery::create()->findPk($id)?->getModel(), 'A refusal changes nothing.');
        self::assertSame($addresses, $this->countRows("SELECT COUNT(*) FROM rewriting_url WHERE view = 'cmspage' AND view_id = %d", $id));
    }

    private function content(int $pageId): ?CmsPageContent
    {
        return CmsPageContentQuery::create()->filterByPageId($pageId)->filterByLocale($this->locale())->findOne();
    }

    private function countRows(string $sql, int $id): int
    {
        return (int) $this->getPropelConnection()->query(\sprintf($sql, $id))->fetchColumn();
    }

    private function menu(): CmsMenu
    {
        $menu = (new CmsMenu())->setCode(self::MENU);
        $menu->setLocale($this->locale())->setTitle('Menu de test');
        $menu->save();

        return $menu;
    }

    private function entry(CmsMenu $menu, string $type, ?int $targetId, string $label, ?CmsMenuItem $parent = null): CmsMenuItem
    {
        $item = (new CmsMenuItem())
            ->setMenuId($menu->getId())
            ->setParent(null === $parent ? 0 : (int) $parent->getId())
            ->setPosition(0)
            ->setTargetType($type)
            ->setTargetId($targetId)
            ->setUrl('url' === $type ? 'https://example.com/'.rawurlencode($label) : null);
        $item->setLocale($this->locale())->setLabel($label);
        $item->save();

        $this->getService(MenuCache::class)->invalidate();

        return $item;
    }
}
