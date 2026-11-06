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

namespace TheliaCMS\Tests\Integration\Module;

use Thelia\Core\Install\Database;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContent;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * Before 2.16.0 a content save left `cms_page.updated_by` empty. The update
 * gives such a page the author of the draft changed last, in whatever
 * language, and leaves alone a page that names someone already: played twice,
 * it changes nothing more.
 *
 * Runs in the transaction of the test: the update holds no ALTER, nothing
 * commits on its own.
 */
final class AuthorBackfillMigrationTest extends CmsIntegrationTestCase
{
    public function testAnEmptyAuthorIsTheAuthorOfTheDraftChangedLast(): void
    {
        $fixtures = new FixtureFactory($this->getPropelConnection());
        $first = (int) $fixtures->admin()->getId();
        $last = (int) $fixtures->admin()->getId();
        $kept = (int) $fixtures->admin()->getId();

        $translated = $this->page('Tarte tatin', [
            ['en_US', $first, '2026-01-01 10:00:00'],
            ['fr_FR', $last, '2026-03-01 10:00:00'],
            ['de_DE', null, '2026-05-01 10:00:00'],
        ]);
        $sameSecond = $this->page('Crumble', [
            ['en_US', $first, '2026-02-01 10:00:00'],
            ['fr_FR', $last, '2026-02-01 10:00:00'],
        ]);
        $named = $this->page('Charlotte', [['en_US', $first, '2026-02-01 10:00:00']], $kept);
        $nobody = $this->page('Pain perdu', [['en_US', null, '2026-02-01 10:00:00']]);

        $this->play();
        $this->play();

        self::assertSame($last, $this->updatedBy($translated), 'The draft changed last names the author, a draft by nobody aside.');
        self::assertSame($last, $this->updatedBy($sameSecond), 'Changed at the same second: the content written last.');
        self::assertSame($kept, $this->updatedBy($named), 'A page that names its author keeps it.');
        self::assertNull($this->updatedBy($nobody), 'No draft names anyone: nobody.');
        self::assertNull($this->author($translated, 'created_by'), 'Nothing tells who created it.');
    }

    /**
     * @param list<array{string, int|null, string}> $contents locale, author of the draft, last change
     */
    private function page(string $title, array $contents, ?int $updatedBy = null): int
    {
        $page = (new CmsPage())->setParent(0)->setPosition(0)->setVisible(1)->setPageType('default');
        $page->setLocale('en_US')->setTitle($title);
        $page->save();
        $id = (int) $page->getId();

        foreach ($contents as [$locale, $author, $changedAt]) {
            (new CmsPageContent())->setPageId($id)->setLocale($locale)->setDraftUpdatedBy($author)->save();
            CmsPageContentQuery::create()->filterByPageId($id)->filterByLocale($locale)->update(['UpdatedAt' => $changedAt]);
        }

        CmsPageQuery::create()->filterById($id)->update(['UpdatedBy' => $updatedBy, 'CreatedBy' => null]);

        return $id;
    }

    private function play(): void
    {
        (new Database($this->getPropelConnection()))->insertSql(null, [\dirname(__DIR__, 3).'/Config/update/2.16.0.sql']);
    }

    private function updatedBy(int $id): ?int
    {
        return $this->author($id, 'updated_by');
    }

    private function author(int $id, string $column): ?int
    {
        $statement = $this->getPropelConnection()->prepare(\sprintf('SELECT %s FROM cms_page WHERE id = ?', 'created_by' === $column ? 'created_by' : 'updated_by'));
        $statement->execute([$id]);
        $value = $statement->fetchColumn();

        return null === $value || false === $value ? null : (int) $value;
    }
}
