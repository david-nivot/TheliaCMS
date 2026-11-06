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

use Thelia\Tools\URL;
use TheliaCMS\Menu\SiteRoot;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Seo\CmsPageSeoElement;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;

/**
 * The WebPage a CMS page hands to SEOne. Its url is the address a search
 * engine keeps for the page, so it must be the one the page answers on.
 */
final class CmsPageMicroDataTest extends CmsIntegrationTestCase
{
    public function testAPageIsDescribedAtItsOwnAddress(): void
    {
        $page = $this->createPage('Nos services');

        self::assertStringEndsWith('/nos-services', $this->webPageOf($page)['url']);
    }

    /**
     * The home page answers on the root of the site; its own address is a 301
     * to it.
     */
    public function testTheHomePageIsDescribedAtTheRootOfTheSite(): void
    {
        $home = $this->createPage('Accueil');
        $previous = TheliaCMS::getConfigValue('home_page_id');
        TheliaCMS::setConfigValue('home_page_id', (string) $home->getId());

        try {
            self::assertSame(SiteRoot::of($this->addressOf($home)), $this->webPageOf($home)['url']);
        } finally {
            TheliaCMS::setConfigValue('home_page_id', $previous);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function webPageOf(CmsPage $page): array
    {
        $html = $this->getService(CmsPageSeoElement::class)->getSeoMicroData((int) $page->getId(), TheliaCMS::PAGE_VIEW);

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $scripts);

        foreach ($scripts[1] as $json) {
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

            if (\is_array($data) && 'WebPage' === ($data['@type'] ?? null)) {
                return $data;
            }
        }

        self::fail('No WebPage in the microdata: '.$html);
    }

    private function addressOf(CmsPage $page): string
    {
        return URL::getInstance()->retrieve(TheliaCMS::PAGE_VIEW, $page->getId(), $this->locale())->toString();
    }
}
