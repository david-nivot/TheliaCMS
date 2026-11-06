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

namespace TheliaCMS\Tests\Integration\Seo;

use TheliaCMS\Model\CmsPage;
use TheliaCMS\Seo\CmsPageSeoElement;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;

/**
 * The breadcrumb a CMS page hands to SEOne, which is both what the theme shows
 * and what goes into the BreadcrumbList a search engine reads. Every entry in
 * it is a promise that the address behind it answers.
 */
final class CmsPageBreadcrumbTest extends CmsIntegrationTestCase
{
    public function testThePathIsTheTreeFromTheRootDownToThePage(): void
    {
        $parent = $this->createPage('Nos services');
        $child = $this->createPage('Conseil', parent: (int) $parent->getId());

        $breadcrumb = $this->breadcrumbOf((int) $child->getId());

        self::assertSame(['Nos services', 'Conseil'], array_column($breadcrumb, 'title'));
        self::assertStringEndsWith('/nos-services', $breadcrumb[0]['url']);
        self::assertStringEndsWith('/nos-services/conseil', $breadcrumb[1]['url']);
    }

    public function testAnAncestorNobodyCanOpenIsLeftOut(): void
    {
        $parent = $this->createPage('Section en cours', published: false);
        $child = $this->createPage('Page en ligne', parent: (int) $parent->getId());

        self::assertSame(
            ['Page en ligne'],
            array_column($this->breadcrumbOf((int) $child->getId()), 'title'),
            'The breadcrumb offers a link to a page that answers 404.',
        );
    }

    public function testAnAncestorTakenOfflineIsLeftOut(): void
    {
        $parent = $this->createPage('Section retirée');
        $child = $this->createPage('Page toujours en ligne', parent: (int) $parent->getId());

        self::assertCount(2, $this->breadcrumbOf((int) $child->getId()));

        $parent->setVisible(0)->save();

        self::assertCount(1, $this->breadcrumbOf((int) $child->getId()));
    }

    /**
     * The trail names every ancestor, however deep the page sits.
     *
     * The climb used to stop after a fixed number of ancestors, so a page nested
     * past it was handed a breadcrumb that started in the middle of the site, and
     * a BreadcrumbList that told a search engine the same thing.
     */
    public function testTheTrailNamesEveryAncestorOfADeeplyNestedPage(): void
    {
        $depth = 24;
        $parent = 0;
        $deepest = null;

        for ($level = 1; $level <= $depth; ++$level) {
            $deepest = $this->createPage('Niveau '.$level, parent: $parent);
            $parent = (int) $deepest->getId();
        }

        self::assertNotNull($deepest);
        self::assertCount($depth, $this->breadcrumbOf((int) $deepest->getId()));
    }

    /**
     * A tree holding a cycle stops the climb instead of repeating itself.
     */
    public function testATreeThatContainsItselfNamesEachPageOnce(): void
    {
        $top = $this->createPage('Anneau haut');
        $bottom = $this->createPage('Anneau bas', parent: (int) $top->getId());

        $top->setParent((int) $bottom->getId())->save();

        self::assertSame(
            ['Anneau haut', 'Anneau bas'],
            array_column($this->breadcrumbOf((int) $bottom->getId()), 'title'),
        );
    }

    public function testAnUnknownPageHasNoBreadcrumb(): void
    {
        self::assertSame([], $this->breadcrumbOf(0));
        self::assertSame([], $this->breadcrumbOf(999999));
    }

    /**
     * Every trail already starts at the root of the site: SEOne puts it first in
     * the BreadcrumbList, and a theme first in the trail it draws. The home page
     * answers on that root, so its own trail holds nothing more, and an entry for
     * it would link its own address, a 301 to the root.
     */
    public function testTheHomePageHasNoTrailOfItsOwn(): void
    {
        $home = $this->createPage('Accueil');

        $this->asHomePage($home, function () use ($home): void {
            self::assertSame([], $this->breadcrumbOf((int) $home->getId()));
        });
    }

    public function testAPageBelowTheHomePageDoesNotNameItAgain(): void
    {
        $home = $this->createPage('Accueil');
        $child = $this->createPage('Nos engagements', parent: (int) $home->getId());

        $this->asHomePage($home, function () use ($child): void {
            self::assertSame(['Nos engagements'], array_column($this->breadcrumbOf((int) $child->getId()), 'title'));
        });
    }

    private function asHomePage(CmsPage $page, callable $check): void
    {
        $previous = TheliaCMS::getConfigValue('home_page_id');
        TheliaCMS::setConfigValue('home_page_id', (string) $page->getId());

        try {
            $check();
        } finally {
            TheliaCMS::setConfigValue('home_page_id', $previous);
        }
    }

    /**
     * @return list<array{url: string, title: string}>
     */
    private function breadcrumbOf(int $pageId): array
    {
        return $this->getService(CmsPageSeoElement::class)->getSeoBreadcrumb($pageId);
    }
}
