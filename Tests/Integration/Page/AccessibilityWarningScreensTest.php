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

use Thelia\Model\LangQuery;
use TheliaCMS\Content\Document;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Settings\SiteMode;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\TheliaCMS;
use TheliaLibrary\Model\LibraryImage;

/**
 * The page list and the dashboard name the pages that are online with an
 * image nobody described or a link that says nothing, and send the reader to
 * the content screen of the language that needs the fix.
 */
final class AccessibilityWarningScreensTest extends AdminScreenTestCase
{
    public function testThePageListNamesThePageAndLinksToItsContentInTheRightLanguage(): void
    {
        $page = $this->onlinePageWithAnUndescribedImage('Galerie sans texte');

        $warning = $this->screen('/admin/cms/pages')->filter('[data-testid="cms-accessibility-warning"]');

        self::assertSame(1, $warning->count());
        self::assertStringContainsString('Galerie sans texte', $warning->text());
        self::assertStringContainsString('no description', $warning->text());

        $link = $warning->filter('a')->reduce(static fn ($anchor): bool => str_contains((string) $anchor->attr('href'), '/pages/'))->first();
        $languageId = (int) LangQuery::create()->findOneByLocale($this->locale())?->getId();

        self::assertStringContainsString(\sprintf('/admin/cms/pages/%d/content', $page->getId()), (string) $link->attr('href'));
        self::assertStringContainsString('edit_language_id='.$languageId, (string) $link->attr('href'));
    }

    public function testThePageListSaysNothingWhenNoPageHasAProblem(): void
    {
        $this->createPage('Propre');

        self::assertSame(0, $this->screen('/admin/cms/pages')->filter('[data-testid="cms-accessibility-warning"]')->count());
    }

    public function testTheDashboardOfAShowcaseSiteShowsIt(): void
    {
        $this->onlinePageWithAnUndescribedImage('Galerie du tableau de bord');
        $previous = TheliaCMS::getConfigValue(CmsSettings::SITE_MODE);
        TheliaCMS::setConfigValue(CmsSettings::SITE_MODE, SiteMode::Showcase->value);

        try {
            $warning = $this->screen('/admin')->filter('[data-testid="cms-accessibility-warning"]');
        } finally {
            TheliaCMS::setConfigValue(CmsSettings::SITE_MODE, $previous ?? SiteMode::Commerce->value);
        }

        self::assertSame(1, $warning->count());
        self::assertStringContainsString('Galerie du tableau de bord', $warning->text());
        self::assertSame('H3', strtoupper($warning->filter('h3, h2')->first()->nodeName()), 'Under the h2 of the block.');
    }

    private function onlinePageWithAnUndescribedImage(string $title): CmsPage
    {
        $image = (new LibraryImage())->setFileName(bin2hex(random_bytes(6)).'.jpg')->setWidth(800)->setHeight(600)->setDecorative(0);
        $image->setLocale($this->locale())->setTitle('Image');
        $image->save();
        $this->getService(CmsMediaLibrary::class)->tag($image);

        $page = $this->createPage($title, published: false);
        $writer = $this->getService(CmsPageWriter::class);
        $writer->saveContent($page, $this->locale(), Document::richText(\sprintf('<h2>%s</h2><img src="/image-library/%d/full/800,600/0/default.jpg" alt="">', htmlspecialchars($title), $image->getId())));
        $writer->publish($page, $this->locale());

        return $page;
    }
}
