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

use TheliaCMS\Page\PageAddressRemover;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * What the remover takes with the addresses of a page: every row that
 * redirects to them, of any view, and the rows that redirect to those.
 */
final class PageAddressRemoverTest extends CmsIntegrationTestCase
{
    use BuildsRedirections;

    public function testRedirectionsOfRedirectionsGoWhateverTheirViewAndOtherPagesStay(): void
    {
        $page = $this->createPage('Page');
        $first = $this->redirectFromAnotherView($page, 'niveau-un.html');
        $this->redirection('folder', '555', 'niveau-deux.html', $first);
        $other = $this->createPage('Autre');
        $this->newerAddressRedirectingToTheFirst($other, 'autre-retour.html');

        $deleted = $this->getService(PageAddressRemover::class)->removeOfPages([(int) $page->getId()]);

        self::assertSame(3, $deleted->total, 'The address of the page and the two redirections.');
        self::assertSame(2, $deleted->ofOtherViews, 'Two of them were addresses of other views.');
        self::assertFalse($this->pageHasAddresses($page));
        self::assertFalse($this->addressExists('niveau-un.html'));
        self::assertFalse($this->addressExists('niveau-deux.html'));
        self::assertTrue($this->pageHasAddresses($other));
        self::assertTrue($this->addressExists('autre-retour.html'));
    }

    public function testACycleOfRedirectionsIsWalkedOnce(): void
    {
        $page = $this->createPage('Page');
        $address = $this->firstAddressOf($page);
        $loop = $this->redirectFromAnotherView($page, 'boucle.html');
        $address->setRedirected((int) $loop->getId())->save();

        $deleted = $this->getService(PageAddressRemover::class)->removeOfPages([(int) $page->getId()]);

        self::assertSame(2, $deleted->total);
        self::assertFalse($this->addressExists('boucle.html'));
    }

    public function testNothingIsRemovedForNoPage(): void
    {
        $page = $this->createPage('Page');

        self::assertSame(0, $this->getService(PageAddressRemover::class)->removeOfPages([])->total);
        self::assertTrue($this->pageHasAddresses($page));
    }

    public function testEveryAddressOfEveryPageGoesWithTheRedirectionsToThem(): void
    {
        $first = $this->createPage('Un');
        $second = $this->createPage('Deux');
        $this->redirectFromAnotherView($first, 'ancien-un.html');
        $this->newerAddressRedirectingToTheFirst($second, 'retour-deux.html');

        $this->getService(PageAddressRemover::class)->removeOfAllPages();

        self::assertFalse($this->pageHasAddresses($first));
        self::assertFalse($this->pageHasAddresses($second));
        self::assertFalse($this->addressExists('ancien-un.html'));
        self::assertFalse($this->addressExists('retour-deux.html'));
    }

    /**
     * Five rows redirect to the address of the page and one more redirects to
     * each of them, with chunks of 2: the second level spans three chunks and
     * the rows that redirect to the rows of the later chunks must be found too.
     */
    public function testRedirectionsSpanningSeveralChunksGoWhole(): void
    {
        $page = $this->createPage('Page');
        $target = $this->firstAddressOf($page);

        for ($level = 1; $level <= 5; ++$level) {
            $first = $this->redirection('folder', (string) (700 + $level), \sprintf('niveau-un-%d.html', $level), $target);
            $this->redirection('product', (string) (800 + $level), \sprintf('niveau-deux-%d.html', $level), $first);
        }

        $deleted = (new PageAddressRemover(2))->removeOfPages([(int) $page->getId()]);

        self::assertSame(11, $deleted->total);
        self::assertSame(10, $deleted->ofOtherViews);

        for ($level = 1; $level <= 5; ++$level) {
            self::assertFalse($this->addressExists(\sprintf('niveau-un-%d.html', $level)));
            self::assertFalse($this->addressExists(\sprintf('niveau-deux-%d.html', $level)));
        }
    }
}
