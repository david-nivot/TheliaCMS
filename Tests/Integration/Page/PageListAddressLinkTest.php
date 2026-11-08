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
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * The address of a page online opens it, in a new tab, from the list of pages;
 * the address of a page visitors cannot read stays plain text.
 */
final class PageListAddressLinkTest extends AdminScreenTestCase
{
    private ?string $multiDomain = null;

    private ?string $languageUrl = null;

    protected function tearDown(): void
    {
        if (null !== $this->multiDomain) {
            ConfigQuery::write('one_domain_foreach_lang', $this->multiDomain);
        }

        if (null !== $this->languageUrl) {
            Lang::getDefaultLanguage()->setUrl($this->languageUrl)->save();
        }

        parent::tearDown();
    }

    public function testThePageOnlineOpensInANewTabAtItsAddress(): void
    {
        $page = $this->createPage('Addresslink online');

        $link = $this->linkOf($page);

        self::assertSame(1, $link->count(), 'A page online has its address as a link.');
        self::assertMatchesRegularExpression('#^https?://[^/]+/#', (string) $link->attr('href'), 'The link is a whole address, host included.');
        self::assertStringEndsWith($this->addressOf($page), (string) $link->attr('href'), 'The link goes to the address the cell shows.');
        self::assertSame('_blank', $link->attr('target'));
        self::assertSame('noopener', $link->attr('rel'));
        self::assertStringContainsString('(', $link->filter('.visually-hidden')->text(), 'A reader of the screen hears that a tab opens.');
    }

    public function testAPageChangedSincePublicationStillOpens(): void
    {
        $page = $this->createPage('Addresslink changed');
        // Saved after it went online: the draft is newer than what visitors read.
        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        \assert(null !== $content);
        $content->setPublishedAt(new \DateTimeImmutable('-1 hour'))->save();

        self::assertSame(1, $this->linkOf($page)->count(), 'What visitors read is the version online: the page opens.');
    }

    public function testAPageNotOnlineKeepsItsAddressAsText(): void
    {
        $draft = $this->createPage('Addresslink draft', published: false);
        $hidden = $this->createPage('Addresslink hidden');
        $hidden->setVisible(0)->save();

        foreach ([$draft, $hidden] as $page) {
            self::assertSame(0, $this->linkOf($page)->count(), \sprintf('Page %d answers nothing to visitors: no link.', $page->getId()));
            self::assertStringContainsString($this->addressOf($page), $this->cellOf($page)->text(), 'The address is still shown.');
        }
    }

    public function testWithADomainPerLanguageTheLinkIsOnTheDomainOfTheLanguage(): void
    {
        $this->multiDomain = (string) ConfigQuery::read('one_domain_foreach_lang', '0');
        $language = Lang::getDefaultLanguage();
        $this->languageUrl = (string) $language->getUrl();
        ConfigQuery::write('one_domain_foreach_lang', '1');
        $language->setUrl('https://addresslink.example.test')->save();
        $page = $this->createPage('Addresslink domain');

        self::assertSame(
            'https://addresslink.example.test/'.ltrim($this->addressOf($page), '/'),
            $this->linkOf($page)->attr('href'),
        );
    }

    private function cellOf(CmsPage $page): Crawler
    {
        return $this->screen('/admin/cms/pages?'.http_build_query(['edit_language_id' => Lang::getDefaultLanguage()->getId(), 'q' => 'Addresslink']))
            ->filter(\sprintf('[data-testid="cms-page-row-%d"] .cms-page-address', $page->getId()));
    }

    private function linkOf(CmsPage $page): Crawler
    {
        return $this->cellOf($page)->filter(\sprintf('a[data-testid="cms-page-address-link-%d"]', $page->getId()));
    }

    private function addressOf(CmsPage $page): string
    {
        $address = (string) $this->cellOf($page)->attr('title');
        self::assertNotSame('', $address, 'The page has an address in this language.');

        return $address;
    }
}
