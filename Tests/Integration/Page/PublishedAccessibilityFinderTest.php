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

use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Page\PublishedAccessibilityFinder;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Integration\CountsStatements;
use TheliaLibrary\Model\LibraryImage;

/**
 * Finding the pages that are online with an image nobody described or a link
 * that says nothing: the pages that went online before the check existed, or
 * through a path that does not run it.
 */
final class PublishedAccessibilityFinderTest extends CmsIntegrationTestCase
{
    use CountsStatements;

    public function testAnOnlinePageWithAnUndescribedImageIsListedWithItsTitleAndLanguage(): void
    {
        $page = $this->pageWith($this->image(), 'Galerie');

        $found = $this->finder()->publishedPagesWithProblems($this->locale());
        $position = array_search((int) $page->getId(), array_column($found['pages'], 'id'), true);

        self::assertNotFalse($position);
        self::assertSame('Galerie', $found['pages'][$position]['title']);
        self::assertSame($this->locale(), $found['pages'][$position]['locale']);
        self::assertNotSame('', $found['pages'][$position]['language']);
        self::assertGreaterThan(0, $found['pages'][$position]['language_id']);
        self::assertNotSame([], $found['pages'][$position]['problems']);
        self::assertGreaterThanOrEqual(1, $found['total']);
    }

    public function testAPageIsNamedByItsTitleInTheLanguageWhereTheProblemIs(): void
    {
        $other = 'fr_FR' === $this->locale() ? 'en_US' : 'fr_FR';
        $page = $this->createPage('Galerie ailleurs', locale: $other, html: \sprintf('<img src="/image-library/%d/full/800,600/0/default.jpg" alt="">', $this->image()->getId()));

        $pages = $this->finder()->publishedPagesWithProblems($this->locale(), namedAtMost: \PHP_INT_MAX)['pages'];
        $position = array_search((int) $page->getId(), array_column($pages, 'id'), true);

        self::assertNotFalse($position);
        self::assertSame($other, $pages[$position]['locale']);
        self::assertSame('Galerie ailleurs', $pages[$position]['title']);
    }

    public function testADecorativeOrDescribedImageIsNotListed(): void
    {
        $decorative = $this->pageWith($this->image(decorative: true), 'Séparateur');
        $described = $this->pageWith($this->image(alt: 'Un fauteuil'), 'Fauteuil');

        $ids = $this->listedIds();

        self::assertNotContains((int) $decorative->getId(), $ids);
        self::assertNotContains((int) $described->getId(), $ids);
    }

    public function testADraftIsNotListed(): void
    {
        $draft = $this->pageWith($this->image(), 'Brouillon', published: false);

        self::assertNotContains((int) $draft->getId(), $this->listedIds());
    }

    public function testAPageInTheBinIsNotListed(): void
    {
        $binned = $this->pageWith($this->image(), 'Au rebut');
        self::assertContains((int) $binned->getId(), $this->listedIds(), 'The fixture proves nothing unless the page was listed before it was binned.');

        $this->writer()->moveToTrash($binned);

        self::assertNotContains((int) $binned->getId(), $this->listedIds());
    }

    public function testAHiddenPageAndTheModelOfATypeAreNotListed(): void
    {
        $hidden = $this->pageWith($this->image(), 'Cachée');
        $hidden->setVisible(0)->save();
        $model = $this->pageWith($this->image(), 'Modèle');
        $model->setModel(1)->save();

        $ids = $this->listedIds();

        self::assertNotContains((int) $hidden->getId(), $ids);
        self::assertNotContains((int) $model->getId(), $ids, 'A model is never a page of the site, even holding a published version.');
    }

    public function testAPageNotYetInItsPublicationWindowIsNotListed(): void
    {
        $scheduled = $this->pageWith($this->image(), 'Programmée');
        $scheduled->setPublishAt(new \DateTimeImmutable('+1 day'))->save();

        self::assertNotContains((int) $scheduled->getId(), $this->listedIds());
    }

    public function testOnlyTheFirstPagesAreNamedAndTheRestIsCounted(): void
    {
        $image = $this->image();

        for ($index = 1; $index <= 14; ++$index) {
            $this->pageWith($image, 'Page '.$index);
        }

        $found = $this->finder()->publishedPagesWithProblems($this->locale());

        self::assertCount(12, $found['pages']);
        self::assertGreaterThanOrEqual(14, $found['total']);
    }

    public function testAPageWithoutAnyImageOrLinkProblemIsNotListed(): void
    {
        $clean = $this->createPage('Propre', html: '<h2>Titre</h2><p>Texte <a href="/contact">Écrivez-nous</a>.</p>');

        self::assertNotContains((int) $clean->getId(), $this->listedIds());
    }

    public function testTheCostDoesNotGrowWithTheNumberOfPagesBeyondTheChunksRead(): void
    {
        $image = $this->image();

        for ($index = 1; $index <= 3; ++$index) {
            $this->pageWith($image, 'Coût '.$index);
        }
        $few = $this->statementsFor(fn () => $this->finder()->publishedPagesWithProblems($this->locale(), namedAtMost: 1));
        $pagesBefore = $this->finder()->publishedPagesWithProblems($this->locale(), namedAtMost: 1)['total'];

        for ($index = 4; $index <= 60; ++$index) {
            $this->pageWith($image, 'Coût '.$index);
        }
        $many = $this->statementsFor(fn () => $this->finder()->publishedPagesWithProblems($this->locale(), namedAtMost: 1));
        $pagesAfter = $this->finder()->publishedPagesWithProblems($this->locale(), namedAtMost: 1)['total'];

        self::assertSame($pagesBefore + 57, $pagesAfter);
        // Pages are read a hundred at a time, and a chunk costs its read and one
        // question to the library: sixty pages are one chunk, as three were.
        self::assertSame($few, $many, \sprintf('%d pages cost %d statements and %d cost %d: something is asked once per page', $pagesBefore, $few, $pagesAfter, $many));
    }

    /**
     * @return list<int>
     */
    private function listedIds(): array
    {
        return array_column($this->finder()->publishedPagesWithProblems($this->locale(), namedAtMost: \PHP_INT_MAX)['pages'], 'id');
    }

    private function finder(): PublishedAccessibilityFinder
    {
        return $this->getService(PublishedAccessibilityFinder::class);
    }

    private function pageWith(LibraryImage $image, string $title, bool $published = true): CmsPage
    {
        return $this->createPage($title, html: \sprintf('<h2>%s</h2><img src="/image-library/%d/full/800,600/0/default.jpg" alt="">', htmlspecialchars($title), $image->getId()), published: $published);
    }

    private function image(?string $alt = null, bool $decorative = false): LibraryImage
    {
        $image = (new LibraryImage())->setFileName(bin2hex(random_bytes(6)).'.jpg')->setWidth(800)->setHeight(600)->setDecorative($decorative ? 1 : 0);
        $image->setLocale($this->locale())->setTitle('Image');

        if (null !== $alt) {
            $image->setAlt($alt);
        }

        $image->save();
        $this->getService(CmsMediaLibrary::class)->tag($image);

        return $image;
    }
}
