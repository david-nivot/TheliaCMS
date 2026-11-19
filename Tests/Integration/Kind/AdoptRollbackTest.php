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

use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsPageSearchQuery;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\Kind\KindContents;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * A page that fails to be adopted halfway is as it was: the transaction of
 * `adopt()` is rolled back, the row, the address and the line of the search
 * included.
 *
 * Runs outside a transaction, as a nested rollback of Propel only marks the
 * outer one and undoes nothing. The failure is a trigger of the test database
 * that refuses the deletion of a line of the search, which `adopt()` does after
 * it wrote the page.
 */
final class AdoptRollbackTest extends CmsIntegrationTestCase
{
    use DeclaresTestKinds;

    private const string TRIGGER = 'cms_test_refuse_search_deletion';

    protected bool $useTransaction = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareTestKinds();
    }

    protected function tearDown(): void
    {
        $this->getPropelConnection()->exec('DROP TRIGGER IF EXISTS '.self::TRIGGER);

        parent::tearDown();
    }

    /**
     * Sabotage: replace `$connection->rollBack();` by `$connection->commit();` in
     * the `catch` of `KindContents::adopt()`: the written page stays a hidden
     * content and the first assertion is red.
     */
    public function testAFailureHalfwayLeavesTheWholePageAsItWas(): void
    {
        $page = $this->createPage('Page qui ne change pas');
        $id = (int) $page->getId();
        $addresses = $this->addresses($id);
        self::assertSame(1, CmsPageSearchQuery::create()->filterByPageId($id)->count());
        $this->getPropelConnection()->exec('DROP TRIGGER IF EXISTS '.self::TRIGGER);
        $this->getPropelConnection()->exec(\sprintf("CREATE TRIGGER %s BEFORE DELETE ON cms_page_search FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by the test'", self::TRIGGER));

        $failed = false;

        try {
            $this->getService(KindContents::class)->adopt($page, 'test_guide');
        } catch (\Throwable) {
            $failed = true;
        }

        self::assertTrue($failed, 'The deletion of the line of the search is refused, so the adoption fails.');

        CmsPageTableMap::clearInstancePool();
        $stored = CmsPageQuery::create()->findPk($id);
        self::assertNotNull($stored);
        self::assertSame(0, $stored->getModel(), 'Still a page.');
        self::assertNull($stored->getContentKind());
        self::assertSame(1, $stored->getVisible());
        self::assertSame($addresses, $this->addresses($id), 'With its address,');
        self::assertSame(1, CmsPageSearchQuery::create()->filterByPageId($id)->count(), 'and its line of the search.');
    }

    private function addresses(int $pageId): int
    {
        return (int) $this->getPropelConnection()->query(\sprintf("SELECT COUNT(*) FROM rewriting_url WHERE view = 'cmspage' AND view_id = %d", $pageId))->fetchColumn();
    }
}
