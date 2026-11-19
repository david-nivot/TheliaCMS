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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Thelia\Model\RewritingUrl;
use TheliaCMS\Catalogue\Admin\ContentTargets;
use TheliaCMS\Content\Kind\UnknownContentKindException;
use TheliaCMS\ImportExport\Step\PageAddresses;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\Map\CmsPageContentTableMap;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\Admin\CmsPageAdminRepository;
use TheliaCMS\Page\PublishPagesCommand;
use TheliaCMS\Page\RenderPublishedCommand;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;

/**
 * What keeps a hidden content out of reach of the page machinery: no screen
 * opens one whose kind no module declares, no command publishes or renders it,
 * the actions of the list do not act on it, and it never gets an address back,
 * by the reactivation of the module nor by the import of a file.
 */
final class KindGuardsTest extends CmsIntegrationTestCase
{
    use DeclaresTestKinds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareTestKinds();
    }

    /**
     * Sabotage: drop `!$this->kinds->isOrphan($page)` from
     * `CmsPageAdminRepository::findLive()`: the content of a kind nobody
     * declares is opened by every screen that goes through it, and the first
     * assertion is red.
     */
    public function testNoScreenOpensAHiddenContentWhoseKindNoModuleDeclares(): void
    {
        $repository = $this->getService(CmsPageAdminRepository::class);
        $guide = $this->kindContent('test_guide', 'Guide', published: false);
        $page = $this->createPage('Page', published: false);

        self::assertNotNull($repository->findLive((int) $guide->getId()), 'Declared, it opens.');
        self::assertNotNull($repository->findLive((int) $page->getId()));

        $this->setKind($guide, 'module_gone');
        self::assertNull($repository->findLive((int) $guide->getId()), 'Its module is gone: no screen opens it.');

        $this->setKind($guide, null);
        self::assertNull($repository->findLive((int) $guide->getId()), 'Nor does a hidden page that has no kind at all.');
    }

    /**
     * Sabotage: make `findOrdinary()` return `findLive()` as it is: the content
     * of a kind and the model of a type are offered to the move, the copy and
     * the home page, and the first two assertions are red.
     */
    public function testTheActionsOfTheListActOnOrdinaryPagesOnly(): void
    {
        $repository = $this->getService(CmsPageAdminRepository::class);
        $guide = $this->kindContent('test_guide', 'Guide', published: false);
        $model = $this->createPage('Modèle', published: false);
        $this->getPropelConnection()->exec(\sprintf('UPDATE cms_page SET model = 1 WHERE id = %d', $model->getId()));
        $binned = $this->createPage('À la corbeille', published: false);
        $this->writer()->moveToTrash($binned);
        $page = $this->createPage('Page ordinaire', published: false);
        CmsPageTableMap::clearInstancePool();

        self::assertNull($repository->findOrdinary((int) $guide->getId()), 'A content of a kind is not moved, copied nor made the home page.');
        self::assertNull($repository->findOrdinary((int) $model->getId()), 'Nor is the model of a type.');
        self::assertNull($repository->findOrdinary((int) $binned->getId()), 'Nor a page in the bin.');
        self::assertSame((int) $page->getId(), (int) $repository->findOrdinary((int) $page->getId())?->getId());
    }

    /**
     * Sabotage: remove the `isOrphan()` check of `ContentTargets::forPage()`:
     * the orphan gets the target of a page, which writes an address and a line
     * of the search when it is published, and the first assertion is red.
     */
    public function testAHiddenContentWithNoDeclaredKindHasNoTargetAndIsPublishedByNoCommand(): void
    {
        $guide = $this->kindContent('test_guide', 'Guide orphelin', published: false);
        $this->setKind($guide, 'module_gone');
        $id = (int) $guide->getId();
        $orphan = CmsPageQuery::create()->findPk($id) ?? new CmsPage();

        try {
            $this->getService(ContentTargets::class)->forPage($orphan, $this->locale());
            self::fail('A content whose kind is not declared has no target.');
        } catch (UnknownContentKindException $exception) {
            self::assertStringContainsString((string) $id, $exception->getMessage());
        }

        $tester = new CommandTester($this->getService(PublishPagesCommand::class));
        $status = $tester->execute(['--page' => [(string) $id]]);

        self::assertSame(Command::FAILURE, $status, '`thelia_cms:publish` names it and refuses.');
        self::assertStringContainsString('no active module declares', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        CmsPageContentTableMap::clearInstancePool();
        self::assertNull(CmsPageContentQuery::create()->filterByPageId($id)->findOne()?->getPublishedAt(), 'Nothing went online.');
        self::assertSame(0, (int) $this->getPropelConnection()->query(\sprintf("SELECT COUNT(*) FROM rewriting_url WHERE view = 'cmspage' AND view_id = %d", $id))->fetchColumn(), 'No address.');

        // Declared again, it is published like any content of its kind.
        $this->setKind($guide, 'test_guide');
        self::assertSame(Command::SUCCESS, (new CommandTester($this->getService(PublishPagesCommand::class)))->execute(['--page' => [(string) $id]]));
    }

    /**
     * Sabotage: drop the `isOrphan()` filter of `pages()` in
     * `RenderPublishedCommand`: `--all` tries the orphan and lists it as failed.
     * (`RenderPagesCommand` rewrites a draft without a target, so it needs no
     * filter.).
     */
    public function testTheCommandThatRendersEveryPageOnlineLeavesOutAContentWhoseModuleIsGone(): void
    {
        $guide = $this->kindContent('test_guide', 'Guide orphelin', published: true);
        $id = (int) $guide->getId();
        $this->setKind($guide, 'module_gone');

        $tester = new CommandTester($this->getService(RenderPublishedCommand::class));
        $tester->execute(['--all' => true]);

        self::assertStringNotContainsString(\sprintf('#%d ', $id), $tester->getDisplay(), '`thelia_cms:render-published` does not try it.');
    }

    /**
     * Sabotage: drop `->filterByModel(ContentKinds::MODEL, Criteria::NOT_EQUAL)`
     * from `TheliaCMS::regenerateRewrittenUrls()`: the content is given an
     * address, and the last assertion is red. The page gets its address back,
     * which shows the method ran.
     */
    public function testReactivatingTheModuleGivesNoAddressToAHiddenContent(): void
    {
        $guide = $this->kindContent('test_guide', 'Guide sans adresse');
        $page = $this->createPage('Page qui retrouve son adresse');
        $this->getPropelConnection()->exec(\sprintf("DELETE FROM rewriting_url WHERE view = 'cmspage' AND view_id = %d", $page->getId()));

        (new \ReflectionMethod(TheliaCMS::class, 'regenerateRewrittenUrls'))->invoke(new TheliaCMS());

        self::assertSame(1, $this->addresses((int) $page->getId()), 'The page has its address again.');
        self::assertSame(0, $this->addresses((int) $guide->getId()), 'The content has none.');
    }

    /**
     * Sabotage: drop `->filterByModel(ContentKinds::MODEL, Criteria::NOT_EQUAL)`
     * from `PageAddresses::existing()`: the stale address of the content makes
     * the file's page replace it, and the assertion is red.
     */
    public function testAnAddressLeftOnAHiddenContentIsNeverTheOneAFileIsReadOnto(): void
    {
        $guide = $this->kindContent('test_guide', 'Guide à l’adresse restée');
        (new RewritingUrl())->setUrl('adresse-restee-du-guide')->setView(TheliaCMS::PAGE_VIEW)->setViewId((string) $guide->getId())->setViewLocale($this->locale())->save();

        $found = $this->getService(PageAddresses::class)->existing([$this->locale() => ['slug' => 'adresse-restee-du-guide']], 0);

        self::assertNull($found, 'A content of a kind is no page to replace.');
    }

    private function setKind(CmsPage $page, ?string $code): void
    {
        $this->getPropelConnection()->exec(null === $code
            ? \sprintf('UPDATE cms_page SET content_kind = NULL WHERE id = %d', $page->getId())
            : \sprintf("UPDATE cms_page SET content_kind = '%s' WHERE id = %d", $code, $page->getId()));
        CmsPageTableMap::clearInstancePool();
    }

    private function addresses(int $pageId): int
    {
        return (int) $this->getPropelConnection()->query(\sprintf("SELECT COUNT(*) FROM rewriting_url WHERE view = 'cmspage' AND view_id = %d", $pageId))->fetchColumn();
    }
}
