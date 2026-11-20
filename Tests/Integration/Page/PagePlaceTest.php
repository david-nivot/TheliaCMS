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
use Symfony\Component\DomCrawler\Crawler;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\Lang;
use Thelia\Model\RewritingUrlQuery;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\PageDraft;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * A page dragged on the list of pages and dropped on another one: just before
 * it, just after it, or inside it. Dropped inside a page of another branch, it
 * changes address with every page under it, and the list comes back with the
 * branch it went into opened.
 */
final class PagePlaceTest extends AdminScreenTestCase
{
    public function testAPageDroppedBeforeASiblingTakesItsPlace(): void
    {
        $parent = $this->createPage('Place parent');
        [$first, $second, $third] = $this->childrenOf($parent, 'Place before');

        self::assertSame(200, $this->drop($third, $first, 'before', ['open' => (string) $parent->getId()]));

        self::assertSame([$third->getId(), $first->getId(), $second->getId()], $this->orderUnder($parent));
    }

    public function testAPageDroppedAfterASiblingFollowsIt(): void
    {
        $parent = $this->createPage('Place parent');
        [$first, $second, $third] = $this->childrenOf($parent, 'Place after');

        self::assertSame(200, $this->drop($first, $second, 'after', ['open' => (string) $parent->getId()]));

        self::assertSame([$second->getId(), $first->getId(), $third->getId()], $this->orderUnder($parent));
        self::assertStringEndsWith('#cms-page-'.$first->getId(), $this->answeredUrl(), 'The list comes back on the row moved.');
    }

    public function testAPageDroppedInsideAnotherGoesFirstUnderItWithItsBranch(): void
    {
        $formerParent = $this->createPage('Rubrique de départ glissée');
        $newParent = $this->createPage('Rubrique d’arrivée glissée');
        $already = $this->pageUnder('Déjà rangée', $newParent, 1);
        $branch = $this->pageUnder('Notre agence glissée', $formerParent, 1);
        $leaf = $this->pageUnder('Notre équipe glissée', $branch, 1);
        $formerLeafAddress = (string) $leaf->getRewrittenUrl($this->locale());

        self::assertSame(200, $this->drop($branch, $newParent, 'inside', ['open' => (string) $formerParent->getId()]));

        CmsPageTableMap::clearInstancePool();
        $branch = CmsPageQuery::create()->findPk($branch->getId());
        $leaf = CmsPageQuery::create()->findPk($leaf->getId());
        self::assertNotNull($branch);
        self::assertNotNull($leaf);
        self::assertSame((int) $newParent->getId(), (int) $branch->getParent());
        self::assertSame([$branch->getId(), $already->getId()], $this->orderUnder($newParent), 'Dropped inside, a page comes first under it.');

        $newParentAddress = (string) $newParent->getRewrittenUrl($this->locale());
        self::assertStringStartsWith($newParentAddress.'/', (string) $branch->getRewrittenUrl($this->locale()));
        self::assertStringStartsWith((string) $branch->getRewrittenUrl($this->locale()).'/', (string) $leaf->getRewrittenUrl($this->locale()), 'The pages under it follow it.');

        $former = RewritingUrlQuery::create()->findOneByUrl($formerLeafAddress);
        $current = RewritingUrlQuery::create()->findOneByUrl((string) $leaf->getRewrittenUrl($this->locale()));
        self::assertNotNull($former);
        self::assertNotNull($current);
        self::assertSame((int) $current->getId(), (int) $former->getRedirected(), 'The former address of a page under it redirects to the new one.');

        parse_str((string) parse_url($this->answeredUrl(), \PHP_URL_QUERY), $query);
        self::assertIsString($query['open'] ?? null);
        self::assertContains((string) $newParent->getId(), explode(',', $query['open']), 'The branch the page went into is opened, or the page would vanish from the list.');
        self::assertContains((string) $formerParent->getId(), explode(',', $query['open']), 'The branches the editor had open stay open.');
    }

    public function testAPageCannotGoInsideItsOwnBranch(): void
    {
        $branch = $this->createPage('Own branch');
        $child = $this->pageUnder('Own branch child', $branch, 1);

        self::assertSame(409, $this->drop($branch, $child, 'inside', ['open' => (string) $branch->getId()]));
        self::assertNotEmpty(json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? null, 'The editor is told why.');

        CmsPageTableMap::clearInstancePool();
        self::assertSame(0, (int) CmsPageQuery::create()->findPk($branch->getId())?->getParent());
    }

    public function testADropOnAPageThatIsGoneIsRefused(): void
    {
        $page = $this->createPage('Dropped on nothing');
        $screen = $this->listing([]);

        self::assertSame(409, $this->send('POST', $this->placeUrl($screen, $page), ['target' => '999999', 'where' => 'before', '_token' => $this->tokenOfTheScripts($screen)], self::XHR));
        self::assertSame(409, $this->send('POST', $this->placeUrl($screen, $page), ['target' => (string) $page->getId(), 'where' => 'beside', '_token' => $this->tokenOfTheScripts($screen)], self::XHR));
    }

    public function testPlacingAPageTakesTheRightToChangePages(): void
    {
        $page = $this->createPage('Not mine to move');
        $other = $this->createPage('Not mine either');
        $screen = $this->listing([]);
        $url = $this->placeUrl($screen, $page);
        $token = $this->tokenOfTheScripts($screen);

        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([CmsResources::PAGE => [AccessManager::VIEW]]));

        self::assertSame(403, $this->send('POST', $url, ['target' => (string) $other->getId(), 'where' => 'inside', '_token' => $token], self::XHR));
        CmsPageTableMap::clearInstancePool();
        self::assertSame(0, (int) CmsPageQuery::create()->findPk($page->getId())?->getParent());
    }

    public function testRowsAreDraggedWhereTheirButtonsMoveThem(): void
    {
        $parent = $this->createPage('Drag handles');
        [$first] = $this->childrenOf($parent, 'Drag handles');

        $tree = $this->listing(['open' => (string) $parent->getId()]);
        self::assertSame(1, $tree->filter(\sprintf('[data-testid="cms-page-drag-%d"]', $first->getId()))->count(), 'A row of the tree has a handle.');
        self::assertSame((string) $parent->getId(), $tree->filter(\sprintf('tr[data-row-id="%d"]', $first->getId()))->attr('data-parent'));

        $section = $this->listing(['inside' => (string) $parent->getId()]);
        self::assertSame(1, $section->filter(\sprintf('[data-testid="cms-page-drag-%d"]', $first->getId()))->count(), 'The pages of one page move among themselves.');

        $search = $this->listing(['q' => 'Drag handles']);
        self::assertSame(0, $search->filter('[data-cms-page-drag]')->count(), 'A list of results has no order of its own to change.');
        self::assertSame(0, $search->filter('[data-cms-page-place]')->count());
    }

    /**
     * @param array<string, string> $query the state of the list the page is dragged on
     */
    private function drop(CmsPage $page, CmsPage $target, string $where, array $query): int
    {
        $screen = $this->listing($query);

        return $this->send('POST', $this->placeUrl($screen, $page), [
            'target' => (string) $target->getId(),
            'where' => $where,
            '_token' => $this->tokenOfTheScripts($screen),
        ], self::XHR);
    }

    private function placeUrl(Crawler $screen, CmsPage $page): string
    {
        $list = $screen->filter('[data-cms-page-place]');

        self::assertSame(1, $list->count(), 'The list says where a drop is sent.');

        return str_replace('{id}', (string) $page->getId(), (string) $list->attr('data-cms-page-place'));
    }

    private function answeredUrl(): string
    {
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertIsString($payload['url'] ?? null);

        return $payload['url'];
    }

    /**
     * @return list<CmsPage>
     */
    private function childrenOf(CmsPage $parent, string $title): array
    {
        return array_map(fn (int $position): CmsPage => $this->pageUnder($title.' '.$position, $parent, $position), [1, 2, 3]);
    }

    private function pageUnder(string $title, CmsPage $parent, int $position): CmsPage
    {
        $writer = $this->getService(CmsPageWriter::class);

        $page = (new CmsPage())->setParent((int) $parent->getId())->setPosition($position)->setVisible(1)->setPageType('default');
        $writer->saveDraft($page, $this->locale(), new PageDraft(title: $title));

        return $page;
    }

    /**
     * @return list<int>
     */
    private function orderUnder(CmsPage $parent): array
    {
        CmsPageTableMap::clearInstancePool();

        return array_map(
            static fn (CmsPage $page): int => (int) $page->getId(),
            iterator_to_array(CmsPageQuery::create()->filterByParent($parent->getId())->filterByDeletedAt(null, Criteria::ISNULL)->orderByPosition()->orderById()->find(), false),
        );
    }

    /**
     * @param array<string, string> $query
     */
    private function listing(array $query): Crawler
    {
        return $this->screen('/admin/cms/pages?'.http_build_query(['edit_language_id' => Lang::getDefaultLanguage()->getId(), ...$query]));
    }
}
