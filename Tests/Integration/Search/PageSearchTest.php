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

namespace TheliaCMS\Tests\Integration\Search;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Search\Front\CmsSearchController;
use TheliaCMS\Search\PageSearch;
use TheliaCMS\Search\SearchTerms;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;

/**
 * The front search runs on a FULLTEXT index filled at publication. What the
 * unit tests cannot check is the part that only exists in the database: that
 * the index is written at all, and that the query keeps out of the results
 * everything a visitor has no business seeing.
 */
final class PageSearchTest extends CmsIntegrationTestCase
{
    /**
     * InnoDB does not add a row to a FULLTEXT index until the transaction that
     * wrote it commits: under the usual rolled-back transaction, the search
     * finds nothing and every assertion here would pass for the wrong reason.
     * So these tests commit, and CmsIntegrationTestCase removes their pages
     * afterwards.
     */
    protected bool $useTransaction = false;

    public function testAPublishedPageIsFoundByAWordOfItsContent(): void
    {
        $this->createPage(
            'Atelier de reliure',
            html: '<h1>Atelier de reliure</h1><p>Nous restaurons les ouvrages anciens.</p>',
        );

        $found = $this->search('restaurons');

        self::assertCount(1, $found['results']);
        self::assertSame('Atelier de reliure', $found['results'][0]->title);
        self::assertStringContainsString('restaurons', $found['results'][0]->excerpt);
    }

    public function testADraftIsNotSearchable(): void
    {
        $this->createPage(
            'Projet confidentiel',
            html: '<h1>Projet confidentiel</h1><p>Un mot rarissime: xylographie.</p>',
            published: false,
        );

        self::assertSame(0, $this->search('xylographie')['total']);
    }

    public function testAHiddenPageLeavesTheResults(): void
    {
        $page = $this->createPage(
            'Page retirée',
            html: '<h1>Page retirée</h1><p>Un mot rarissime: héliogravure.</p>',
        );

        self::assertSame(1, $this->search('héliogravure')['total']);

        $page->setVisible(0)->save();

        self::assertSame(
            0,
            $this->search('héliogravure')['total'],
            'A page taken offline is still answered by the search.',
        );
    }

    public function testAPageInTheBinLeavesTheResults(): void
    {
        $page = $this->createPage(
            'Page supprimée',
            html: '<h1>Page supprimée</h1><p>Un mot rarissime: lithographie.</p>',
        );

        self::assertSame(1, $this->search('lithographie')['total']);

        $this->writer()->moveToTrash($page);

        self::assertSame(
            0,
            $this->search('lithographie')['total'],
            'A page in the bin is still answered by the search.',
        );
    }

    public function testAPageWaitingForItsPublicationDateStaysOut(): void
    {
        $page = $this->createPage(
            'Annonce à venir',
            html: '<h1>Annonce</h1><p>Un mot rarissime: sérigraphie.</p>',
        );

        $page->setPublishAt(new \DateTimeImmutable('+1 month'))->save();
        CmsPageQuery::create()->clear();

        self::assertSame(0, $this->search('sérigraphie')['total']);
    }

    /**
     * @return array{results: list<\TheliaCMS\Search\SearchResult>, total: int}
     */
    /**
     * The home page answers on the root only, its own address being a 301 to
     * it: a result links to the root, not through the redirect.
     */
    public function testTheHomePageIsLinkedOnTheRoot(): void
    {
        $home = $this->createPage(
            'Accueil de la boutique',
            html: '<h2>Accueil de la boutique</h2><p>Un mot introuvable ailleurs: enluminure.</p>',
        );
        $previous = (string) TheliaCMS::getConfigValue('home_page_id', '0');
        TheliaCMS::setConfigValue('home_page_id', (string) $home->getId());

        $request = Request::create('/recherche', 'GET', ['q' => 'enluminure']);
        $requests = $this->getService(RequestStack::class);
        $request->setSession($requests->getMainRequest()?->hasSession() ? $requests->getMainRequest()->getSession() : new Session(new MockArraySessionStorage()));
        $requests->push($request);

        try {
            $html = (string) $this->getService(CmsSearchController::class)->search($request)->getContent();
        } finally {
            $requests->pop();
            TheliaCMS::setConfigValue('home_page_id', $previous);
        }

        self::assertStringContainsString('Accueil de la boutique', $html);
        self::assertStringNotContainsString('accueil-de-la-boutique', $html, 'Its own address redirects.');
        self::assertMatchesRegularExpression('#href="https?://[^/"]+/"#', $html);
    }

    private function search(string $words): array
    {
        return $this->getService(PageSearch::class)
            ->find(SearchTerms::fromInput($words), $this->locale());
    }
}
