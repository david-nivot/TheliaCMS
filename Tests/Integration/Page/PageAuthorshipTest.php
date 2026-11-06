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

use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Admin;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Content\Document;
use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\ImportExport\SiteImporter;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;
use TheliaCMS\Zone\HeaderZone;

/**
 * Every write of the module that changes a page or its content says who made
 * it (`cms_page.updated_by`), and a creation who created it: the list of
 * pages filters on it. A page is written by one administrator, then changed
 * by another, who must be the one the page names.
 */
final class PageAuthorshipTest extends CmsIntegrationTestCase
{
    private Admin $writer;

    private Admin $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $fixtures = new FixtureFactory($this->getPropelConnection());
        $this->writer = $fixtures->admin();
        $this->editor = $fixtures->admin();
        $this->actAs($this->writer);
    }

    protected function tearDown(): void
    {
        $this->session()->clearAdminUser();

        parent::tearDown();
    }

    public function testACreationNamesItsAuthorTwice(): void
    {
        $page = $this->createPage('Tarte aux pommes');

        self::assertSame((int) $this->writer->getId(), $this->createdBy($page));
        self::assertSame((int) $this->writer->getId(), $this->updatedBy($page));
    }

    public function testSavingTheContentSignsThePageWithoutSavingIt(): void
    {
        $page = $this->createPage('Tarte aux poires');
        $this->actAs($this->editor);

        $this->writer()->saveContent($page, $this->locale(), Document::richText('<h2>Poires</h2>'));

        self::assertSame((int) $this->editor->getId(), $this->updatedBy($page));
        self::assertSame((int) $this->writer->getId(), $this->createdBy($page), 'The creator stays.');
    }

    public function testPublishingAndTakingOfflineSignThePage(): void
    {
        $page = $this->createPage('Tarte aux prunes', published: false);
        $this->actAs($this->editor);
        $this->writer()->publish($page, $this->locale());

        self::assertSame((int) $this->editor->getId(), $this->updatedBy($page), 'Published.');

        $this->actAs($this->writer);
        $this->writer()->unpublish($page, $this->locale());

        self::assertSame((int) $this->writer->getId(), $this->updatedBy($page), 'Taken offline.');
    }

    public function testHidingAPageSignsIt(): void
    {
        $page = $this->createPage('Tarte au citron');
        $this->actAs($this->editor);

        $this->writer()->toggleVisibility($page);

        self::assertSame((int) $this->editor->getId(), $this->updatedBy($page));
    }

    public function testMovingAPageSignsThePagesWhosePlaceChangedOnly(): void
    {
        $parent = $this->createPage('Desserts');
        $first = $this->createPage('Clafoutis', parent: (int) $parent->getId());
        $second = $this->createPage('Far breton', parent: (int) $parent->getId());
        $third = $this->createPage('Flan', parent: (int) $parent->getId());

        foreach ([$first, $second, $third] as $position => $page) {
            $page->setPosition($position + 1)->save();
        }

        $this->actAs($this->editor);
        $this->writer()->move($third, -1);

        self::assertSame((int) $this->editor->getId(), $this->updatedBy($third), 'Moved.');
        self::assertSame((int) $this->editor->getId(), $this->updatedBy($second), 'Moved down by it.');
        self::assertSame((int) $this->writer->getId(), $this->updatedBy($first), 'Left where it was.');
    }

    public function testTheBinAndTheRestoreSignTheWholeBranch(): void
    {
        $parent = $this->createPage('Entrées');
        $child = $this->createPage('Velouté', parent: (int) $parent->getId());
        $this->actAs($this->editor);

        $this->writer()->moveToTrash($parent);

        self::assertSame((int) $this->editor->getId(), $this->updatedBy($parent), 'Binned.');
        self::assertSame((int) $this->editor->getId(), $this->updatedBy($child), 'Binned with its parent.');

        $this->actAs($this->writer);
        $this->writer()->restore(CmsPageQuery::create()->findPk($parent->getId()) ?? $parent, $this->locale());

        self::assertSame((int) $this->writer->getId(), $this->updatedBy($parent), 'Restored.');
        self::assertSame((int) $this->writer->getId(), $this->updatedBy($child), 'Restored with its parent.');
    }

    public function testSettingTheHomePageSignsIt(): void
    {
        $page = $this->createPage('Accueil des recettes');
        $formerHome = TheliaCMS::getConfigValue('home_page_id', '0');
        $this->actAs($this->editor);

        try {
            $this->writer()->setAsHome($page);
        } finally {
            TheliaCMS::setConfigValue('home_page_id', (string) $formerHome);
        }

        self::assertSame((int) $this->editor->getId(), $this->updatedBy($page));
    }

    public function testACopyIsCreatedAndChangedByWhoeverCopiedIt(): void
    {
        $page = $this->createPage('Quiche');
        $this->actAs($this->editor);

        $copy = $this->writer()->duplicate($page, $this->locale(), '(copie)');

        self::assertSame((int) $this->editor->getId(), $this->createdBy($copy));
        self::assertSame((int) $this->editor->getId(), $this->updatedBy($copy));
        self::assertSame((int) $this->writer->getId(), $this->updatedBy($page), 'The page copied is not changed.');
    }

    public function testRemovingTheAiMarkSignsThePage(): void
    {
        $page = $this->createPage('Gaspacho');
        CmsPageContentQuery::create()->filterByPageId($page->getId())->update(['DraftProvenance' => '{"model":"test"}']);
        $this->actAs($this->editor);

        self::assertTrue($this->writer()->removeProvenance($page, $this->locale()));
        self::assertSame((int) $this->editor->getId(), $this->updatedBy($page));
    }

    public function testTheHeaderZoneIsCreatedByWhoeverMadeIt(): void
    {
        CmsPageQuery::create()->filterByModel(HeaderZone::MODEL)->delete();
        $this->actAs($this->editor);

        $zone = $this->getService(HeaderZone::class)->ensure();

        self::assertSame((int) $this->editor->getId(), $this->createdBy($zone));
        self::assertSame((int) $this->editor->getId(), $this->updatedBy($zone));
    }

    public function testAnImportedPageIsChangedByWhoeverRanTheImport(): void
    {
        $page = $this->createPage('Pot-au-feu');
        $document = $this->exportedPage($page);
        $this->actAs($this->editor);

        $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions(replace: true));

        self::assertSame((int) $this->editor->getId(), $this->updatedBy($page));
    }

    /**
     * The command line, the install, the scheduler: nobody acts, and the page
     * names nobody rather than the last administrator it knew.
     */
    public function testAChangeMadeByNobodyNamesNobody(): void
    {
        $page = $this->createPage('Soupe à l’oignon');
        $this->session()->clearAdminUser();

        $this->writer()->saveContent($page, $this->locale(), Document::richText('<h2>Oignons</h2>'));

        self::assertNull($this->updatedBy($page));
        self::assertSame((int) $this->writer->getId(), $this->createdBy($page));
    }

    private function actAs(Admin $admin): void
    {
        $this->session()->setAdminUser($admin);
    }

    private function session(): Session
    {
        $session = $this->getService(RequestStack::class)->getMainRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);

        return $session;
    }

    private function updatedBy(CmsPage $page): ?int
    {
        return $this->column($page, 'UpdatedBy');
    }

    private function createdBy(CmsPage $page): ?int
    {
        return $this->column($page, 'CreatedBy');
    }

    /**
     * Read from the table, past the copy of the page this test holds.
     */
    private function column(CmsPage $page, string $column): ?int
    {
        $statement = $this->getPropelConnection()->prepare(\sprintf('SELECT %s FROM cms_page WHERE id = ?', 'CreatedBy' === $column ? 'created_by' : 'updated_by'));
        $statement->execute([(int) $page->getId()]);
        $value = $statement->fetchColumn();

        return null === $value || false === $value ? null : (int) $value;
    }
}
