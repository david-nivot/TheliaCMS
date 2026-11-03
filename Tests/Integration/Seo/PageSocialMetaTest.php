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

use SEOne\Event\SEOneUrlEvent;
use Symfony\Component\DomCrawler\Crawler;
use Thelia\Tools\URL;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\TheliaCMS;
use TheliaLibrary\Model\LibraryImage;

/**
 * What the head of a page served by the front office says about it, from the
 * SEO tab of the page: the canonical address, the image of a share, and the
 * social title, description and card the theme is handed.
 *
 * Skipped on a shop without SEOne, which writes the canonical and the tags of
 * a share.
 */
final class PageSocialMetaTest extends AdminScreenTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(SEOneUrlEvent::class)) {
            self::markTestSkipped('SEOne is not installed on this shop.');
        }
    }

    public function testTheCanonicalTypedForThePageIsServed(): void
    {
        $page = $this->createPage('Nos tarifs');
        $this->localized($page)->setCanonical('https://www.example.com/tarifs')->save();

        $head = $this->headOf($page);

        self::assertSame('https://www.example.com/tarifs', $head->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('https://www.example.com/tarifs', $head->filter('meta[property="og:url"]')->attr('content'));
    }

    public function testAPageWithoutCanonicalNamesItsOwnAddress(): void
    {
        $page = $this->createPage('Nos horaires');

        self::assertStringEndsWith('/nos-horaires', (string) $this->headOf($page)->filter('link[rel="canonical"]')->attr('href'));
    }

    public function testTheSocialImageWinsOverTheImageOfThePage(): void
    {
        $library = $this->getService(CmsMediaLibrary::class);
        $pageImage = $this->image($library, 'image-de-page.jpg');
        $socialImage = $this->image($library, 'image-de-partage.jpg');

        $page = $this->createPage('Notre atelier');
        $page->setImageId((int) $pageImage->getId());
        $this->localized($page)->setOgImageId((int) $socialImage->getId())->save();

        $head = $this->headOf($page);

        // A network fetches the image from elsewhere: a path would lead nowhere.
        $expected = 'http://localhost'.$library->publicUrl($socialImage);
        self::assertSame($expected, $head->filter('meta[property="og:image"]')->attr('content'));
        self::assertSame($expected, $head->filter('meta[name="twitter:image"]')->attr('content'));
    }

    public function testTheSocialFieldsReachThePageTheThemeRenders(): void
    {
        $page = $this->createPage('Notre équipe');
        $this->localized($page)
            ->setOgTitle('Rencontrez l\'équipe')
            ->setOgDescription('Douze personnes, un atelier.')
            ->setTwitterCard('summary')
            ->save();

        $published = $this->publishedPages()->find((int) $page->getId(), $this->locale());

        self::assertNotNull($published);
        self::assertSame('Rencontrez l\'équipe', $published->ogTitle);
        self::assertSame('Douze personnes, un atelier.', $published->ogDescription);
        self::assertSame('summary', $published->twitterCard);
    }

    public function testACardTheNetworksDoNotKnowIsNoChoice(): void
    {
        $page = $this->createPage('Nos engagements');
        $this->localized($page)->setTwitterCard('player')->save();

        self::assertNull($this->publishedPages()->find((int) $page->getId(), $this->locale())?->twitterCard);
    }

    private function headOf(CmsPage $page): Crawler
    {
        $url = URL::getInstance()->retrieve(TheliaCMS::PAGE_VIEW, $page->getId(), $this->locale())->toString();
        $crawler = $this->client->request('GET', (string) parse_url($url, \PHP_URL_PATH));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $crawler->filter('head');
    }

    private function localized(CmsPage $page): CmsPage
    {
        return $page->setLocale($this->locale());
    }

    private function publishedPages(): PublishedPageRepository
    {
        return $this->getService(PublishedPageRepository::class);
    }

    private function image(CmsMediaLibrary $library, string $fileName): LibraryImage
    {
        $image = new LibraryImage();
        $image->setLocale($this->locale())->setFileName($fileName)->setTitle($fileName);
        $image->save();
        $library->tag($image);

        return $image;
    }
}
