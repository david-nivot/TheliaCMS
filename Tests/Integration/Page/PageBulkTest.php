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

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\Lang;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsTag;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\PageDraft;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\TheliaCMS;

/**
 * Several pages of the list answered at once: shown or hidden, tagged, moved
 * to the bin with what is under them, published or unpublished, each action
 * under its own right, back on the list the selection was made on.
 */
final class PageBulkTest extends AdminScreenTestCase
{
    private const string LIST = '/admin/cms/pages';

    private const string BULK = '/admin/cms/pages/bulk';

    /** A word every page of a test carries, so the list is a flat list of them. */
    private const string SEARCH = 'Bulkpage';

    public function testHidingSetsTheStateAskedWhateverEachPageWasAndCountsWhatChanged(): void
    {
        $shown = $this->createPage('Bulkpage shown');
        $hidden = $this->createPage('Bulkpage hidden');
        $this->writer()->setVisibility([$hidden], false);

        self::assertSame(302, $this->bulk('hide', [$shown, $hidden]));

        self::assertSame(0, $this->reloaded($shown)->getVisible());
        self::assertSame(0, $this->reloaded($hidden)->getVisible(), 'A set, not a toggle: the hidden page stays hidden.');
        self::assertContains($this->say('%count% page(s) hidden from the site.', ['%count%' => 1]), $this->flashesAfterRedirect('success'), 'Only the page that changed is counted.');

        $this->bulk('show', [$shown, $hidden]);

        self::assertSame(1, $this->reloaded($shown)->getVisible());
        self::assertSame(1, $this->reloaded($hidden)->getVisible());
    }

    public function testASelectionAlreadyInTheStateAskedIsSaidSoAndNotWritten(): void
    {
        $page = $this->createPage('Bulkpage already');
        $before = $this->reloaded($page)->getUpdatedBy();

        $this->bulk('show', [$page]);

        self::assertContains($this->say('Nothing to change: the selected pages were already so.'), $this->flashesAfterRedirect('info'));
        self::assertSame($before, $this->reloaded($page)->getUpdatedBy(), 'Not written, so not signed.');
    }

    public function testATagReplacesTheOneEachPageHadAndNoTagTakesItOff(): void
    {
        $news = $this->givenTag('Bulk news');
        $events = $this->givenTag('Bulk events');
        $first = $this->createPage('Bulkpage first');
        $second = $this->createPage('Bulkpage second');
        $second->setTagId($events->getId())->save();
        $admin = (new FixtureFactory($this->getPropelConnection()))->admin();
        $this->logInAs($admin);

        $this->bulk('tag', [$first, $second], ['set_tag' => (string) $news->getId()]);

        self::assertSame((int) $news->getId(), (int) $this->reloaded($first)->getTagId());
        self::assertSame((int) $news->getId(), (int) $this->reloaded($second)->getTagId(), 'The tag is set, the former one replaced.');
        self::assertSame((int) $admin->getId(), (int) $this->reloaded($second)->getUpdatedBy(), 'Signed by the admin acting.');
        self::assertContains($this->say('%count% page(s) tagged “%tag%”.', ['%count%' => 2, '%tag%' => 'Bulk news']), $this->flashesAfterRedirect('success'));

        $this->bulk('tag', [$first], ['set_tag' => 'none']);

        self::assertNull($this->reloaded($first)->getTagId());
        self::assertSame((int) $news->getId(), (int) $this->reloaded($second)->getTagId(), 'A page not selected keeps its tag.');
    }

    public function testATagTheSiteDoesNotHaveChangesNothing(): void
    {
        $news = $this->givenTag('Bulk news');
        $page = $this->createPage('Bulkpage tagged');
        $page->setTagId($news->getId())->save();

        $this->bulk('tag', [$page], ['set_tag' => '999999']);

        self::assertSame((int) $news->getId(), (int) $this->reloaded($page)->getTagId());
        self::assertContains($this->say('Choose the tag to give.'), $this->flashesAfterRedirect('warning'));
    }

    public function testTheBinIsAskedAgainWithThePagesUnderTheSelectionCountedOnce(): void
    {
        $parent = $this->createPage('Bulkpage parent');
        $child = $this->under($this->createPage('Bulkpage child'), $parent);
        $grandchild = $this->under($this->createPage('Bulkpage grandchild'), $child);
        $alone = $this->createPage('Bulkpage alone');

        // The parent and its child ticked: the child goes once, the grandchild with them.
        $screen = $this->confirmation('trash', [$parent, $child, $alone]);

        self::assertNull($this->reloaded($parent)->getDeletedAt(), 'Asking writes nothing.');
        self::assertStringContainsString(
            $this->say('%count% page(s) go to the bin: the %selected% selected and %inside% page(s) under them.', ['%count%' => 4, '%selected%' => 3, '%inside%' => 1]),
            $screen->filter('[data-testid="cms-pages-bulk-total"]')->text(),
        );
        self::assertSame(['Bulkpage grandchild'], $screen->filter('[data-testid="cms-pages-bulk-inside"] li')->each(static fn (Crawler $item): string => trim($item->text())));

        $confirm = $screen->filter('[data-testid="cms-pages-bulk-confirm-form"]');
        self::assertSame(1, $confirm->count());
        self::assertSame(302, $this->send('POST', (string) $confirm->attr('action'), $confirm->form()->getPhpValues()));

        foreach ([$parent, $child, $grandchild, $alone] as $page) {
            self::assertNotNull($this->reloaded($page)->getDeletedAt(), \sprintf('%s is in the bin.', $page->getTitle()));
        }

        self::assertContains($this->say('%count% page(s) moved to the bin.', ['%count%' => 4]), $this->flashesAfterRedirect('success'), 'The pages that went, not the boxes ticked.');
    }

    public function testTheBinTakesTheRightToDeletePages(): void
    {
        $page = $this->createPage('Bulkpage kept');
        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::PAGE => [AccessManager::VIEW, AccessManager::UPDATE],
        ]));

        self::assertSame(403, $this->bulk('trash', [$page], ['confirmed' => '1']));
        self::assertNull($this->reloaded($page)->getDeletedAt());

        self::assertSame(302, $this->bulk('hide', [$page]), 'Hiding takes the right to change pages only.');
    }

    public function testChangingThePagesTakesTheRightToChangeThem(): void
    {
        $page = $this->createPage('Bulkpage watched');
        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::PAGE => [AccessManager::VIEW, AccessManager::DELETE],
        ]));

        foreach (['show', 'hide', 'tag', 'publish', 'unpublish'] as $action) {
            self::assertSame(403, $this->bulk($action, [$page], ['confirmed' => '1']), $action);
        }

        self::assertSame(1, $this->reloaded($page)->getVisible());
    }

    public function testThePublicationIsAskedAgainWithWhatWillBeRefusedThenReportedPageByPage(): void
    {
        $ready = $this->createPage('Bulkpage ready', published: false);
        $empty = $this->emptyPage('Bulkpage empty');

        $screen = $this->confirmation('publish', [$ready, $empty]);

        self::assertFalse($this->isOnline($ready), 'Asking publishes nothing.');
        self::assertSame(['Bulkpage ready'], $screen->filter('[data-testid="cms-pages-bulk-ready"] li')->each(static fn (Crawler $item): string => trim($item->text())));
        $refused = $screen->filter('[data-testid="cms-pages-bulk-refused"] li');
        self::assertSame(1, $refused->count());
        self::assertStringContainsString('Bulkpage empty', $refused->text());
        self::assertStringContainsString($this->say('This page has no content yet: add at least one block in the editor before publishing it.'), $refused->text(), 'The reason the publication gives.');

        $confirm = $screen->filter('[data-testid="cms-pages-bulk-confirm-form"]');
        self::assertSame(302, $this->send('POST', (string) $confirm->attr('action'), $confirm->form()->getPhpValues()));

        self::assertTrue($this->isOnline($ready));
        self::assertFalse($this->isOnline($empty));

        $screen = $this->client->followRedirect();
        self::assertContains($this->say('%count% page(s) published in “%language%”.', ['%count%' => 1, '%language%' => $this->languageTitle()]), $this->flashes($screen, 'success'));
        $danger = implode("\n", $this->flashes($screen, 'danger'));
        self::assertStringContainsString('Bulkpage empty', $danger, 'The refused page is named.');
        self::assertStringContainsString($this->say('This page has no content yet: add at least one block in the editor before publishing it.'), $danger);
    }

    public function testNothingToPublishLeavesNoButtonToConfirm(): void
    {
        $screen = $this->confirmation('publish', [$this->emptyPage('Bulkpage empty')]);

        self::assertSame(0, $screen->filter('[data-testid="cms-pages-bulk-confirm-form"]')->count());
    }

    public function testUnpublishingCountsThePagesThatWereOnline(): void
    {
        $online = $this->createPage('Bulkpage online');
        $draft = $this->createPage('Bulkpage draft', published: false);

        $this->bulk('unpublish', [$online, $draft]);

        self::assertFalse($this->isOnline($online));
        self::assertContains($this->say('%count% page(s) unpublished in “%language%”.', ['%count%' => 1, '%language%' => $this->languageTitle()]), $this->flashesAfterRedirect('success'));
    }

    public function testAModelOrAPageOfTheBinIsNotPartOfASelection(): void
    {
        $model = $this->createPage('Bulkpage model');
        $model->setModel(1)->save();
        $binned = $this->createPage('Bulkpage binned');
        $this->writer()->moveToTrash($binned);
        // A page the list shows, so the list has its form.
        $this->createPage('Bulkpage listed');

        $this->bulk('hide', [$model, $binned]);

        self::assertContains($this->say('Select at least one page.'), $this->flashesAfterRedirect('warning'));
        self::assertSame(1, $this->reloaded($model)->getVisible());
        self::assertSame(1, $this->reloaded($binned)->getVisible());
    }

    public function testTheListOffersTheSelectionOnTheFlatListOnlyAndLandsBackOnIt(): void
    {
        $page = $this->createPage('Bulkpage listed');

        $tree = $this->screen(self::LIST);
        self::assertSame(0, $tree->filter('[data-testid="cms-pages-bulk"]')->count(), 'No box on the tree: a folded branch would go to the bin unseen.');
        self::assertSame(0, $tree->filter('input[name="pages[]"]')->count());

        $flat = $this->screen(self::LIST.'?q='.self::SEARCH.'&sort=title');
        $box = $flat->filter(\sprintf('[data-testid="cms-page-select-%d"]', $page->getId()));
        self::assertSame('cms-pages-bulk', $box->attr('form'));
        self::assertSame(1, $flat->filter(\sprintf('label[for="cms-pages-select-%d"]', $page->getId()))->count(), 'Each box is named.');

        parse_str((string) parse_url((string) $flat->filter('form#cms-pages-bulk')->attr('action'), \PHP_URL_QUERY), $query);
        self::assertSame(self::SEARCH, $query['q'] ?? null, 'The form carries the filters of the list.');
        self::assertSame('title', $query['sort'] ?? null);

        $this->bulk('hide', [$page]);

        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $back);
        self::assertSame(self::SEARCH, $back['q'] ?? null, 'Back on the list the selection was made on.');
        self::assertSame('title', $back['sort'] ?? null);
    }

    public function testAnUnknownActionChangesNothing(): void
    {
        $page = $this->createPage('Bulkpage untouched');

        $this->bulk('delete-for-good', [$page]);

        self::assertContains($this->say('Choose what to do with the selected pages.'), $this->flashesAfterRedirect('warning'));
        self::assertNull($this->reloaded($page)->getDeletedAt());
    }

    /**
     * @param list<CmsPage>         $pages
     * @param array<string, string> $extra
     */
    private function bulk(string $action, array $pages, array $extra = []): int
    {
        return $this->send('POST', self::BULK.'?q='.self::SEARCH.'&sort=title', [
            '_token' => $this->token(),
            'action' => $action,
            'pages' => array_map(static fn (CmsPage $page): string => (string) $page->getId(), $pages),
            ...$extra,
        ]);
    }

    /**
     * @param list<CmsPage> $pages
     */
    private function confirmation(string $action, array $pages): Crawler
    {
        $status = $this->bulk($action, $pages);
        self::assertSame(200, $status, 'The screen that asks again.');

        return new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost'.self::BULK);
    }

    private function token(): string
    {
        // The token of the back office, which the form of the selection carries.
        return $this->tokenOfTheForm($this->screen(self::LIST.'?q='.self::SEARCH), self::BULK);
    }

    /**
     * @return list<string>
     */
    private function flashesAfterRedirect(string $type): array
    {
        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        return $this->flashes($this->client->followRedirect(), $type);
    }

    /**
     * @return list<string>
     */
    private function flashes(Crawler $screen, string $type): array
    {
        return $screen->filter(\sprintf('[data-testid="bo-flash-%s"]', $type))->each(static fn (Crawler $flash): string => trim(html_entity_decode($flash->text())));
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function say(string $message, array $parameters = []): string
    {
        return $this->getService(TranslatorInterface::class)->trans($message, $parameters, TheliaCMS::DOMAIN_NAME);
    }

    private function languageTitle(): string
    {
        return (string) Lang::getDefaultLanguage()->getTitle();
    }

    private function emptyPage(string $title): CmsPage
    {
        $page = new CmsPage();
        $page->setParent(0)->setPosition(0)->setVisible(1)->setPageType('default');
        $this->writer()->saveDraft($page, $this->locale(), new PageDraft(title: $title));

        return $page;
    }

    private function under(CmsPage $page, CmsPage $parent): CmsPage
    {
        $page->setParent($parent->getId())->save();

        return $page;
    }

    private function givenTag(string $name): CmsTag
    {
        $tag = (new CmsTag())->setName($name);
        $tag->save();

        return $tag;
    }

    private function isOnline(CmsPage $page): bool
    {
        return null !== CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne()?->getPublishedAt();
    }

    private function reloaded(CmsPage $page): CmsPage
    {
        CmsPageTableMap::clearInstancePool();
        $found = CmsPageQuery::create()->findPk($page->getId());
        self::assertNotNull($found);

        return $found;
    }

    private function writer(): CmsPageWriter
    {
        return $this->getService(CmsPageWriter::class);
    }
}
