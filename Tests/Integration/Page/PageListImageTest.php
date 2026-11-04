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

use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Page\PageImage;
use TheliaCMS\Partial\Definition\PageListPartial;
use TheliaCMS\Partial\PartialRenderer;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Twig\CmsExtension;
use TheliaLibrary\Model\LibraryImage;

/**
 * The image of a card of a list is served the way an image of a page is: a set
 * of widths and its size, not the file at full size for every visitor.
 */
final class PageListImageTest extends CmsIntegrationTestCase
{
    public function testTheCardOfAListGivesItsImageAFileForEveryWidth(): void
    {
        $this->useAnInstalledFrontTheme();
        $image = $this->libraryImage(1200, 800);
        $page = $this->createPage('Page with a picture');
        $page->setImageId((int) $image->getId())->save();
        $this->writer()->publish($page, $this->locale());
        $id = (int) $image->getId();

        $section = $this->getService(SectionCatalog::class)->find('Partial:'.PageListPartial::NAME);
        $props = $section?->normalizeProps(['type' => 'default', 'limit' => 48]) ?? [];
        $html = (string) $this->getService(PartialRenderer::class)->renderOne(PageListPartial::NAME, $props, $this->locale(), cache: false);

        self::assertStringContainsString(\sprintf('srcset="/image-library/%1$d/full/480,320/0/default.jpg 480w, /image-library/%1$d/full/960,640/0/default.jpg 960w, /image-library/%1$d/full/max/0/default.jpg 1200w"', $id), $html);
        self::assertStringContainsString('sizes="(min-width: 64rem) 25vw, (min-width: 40rem) 50vw, 100vw"', $html);
        self::assertStringContainsString('width="1200"', $html);
        self::assertStringContainsString('height="800"', $html);
    }

    public function testATemplateStatesTheWidthItDrawsTheImageAt(): void
    {
        $attributes = $this->getService(CmsExtension::class)->imageAttributes(
            new PageImage(id: 7, url: '/image-library/7/full/max/0/default.webp', alt: '', width: 2400, height: 1600),
            '(min-width: 64rem) 25vw, 50vw',
        );

        self::assertSame('(min-width: 64rem) 25vw, 50vw', $attributes['sizes']);
        self::assertStringContainsString('/image-library/7/full/1440,960/0/default.webp 1440w', $attributes['srcset'], 'The format of the file is kept.');
        self::assertSame('2400', $attributes['width']);

        self::assertSame([], $this->getService(CmsExtension::class)->imageAttributes(null));
        self::assertStringContainsString('1440px', $this->getService(CmsExtension::class)->imageAttributes(new PageImage(7, '/image-library/7/full/max/0/default.jpg', null, 2400, 1600), 'url(x)')['sizes'], 'A value that is not a width is not passed on.');
    }

    private function libraryImage(int $width, int $height): LibraryImage
    {
        $image = new LibraryImage();
        $image->setLocale($this->locale())->setFileName('card.jpg')->setTitle('card')->setWidth($width)->setHeight($height);
        $image->save();
        $this->getService(CmsMediaLibrary::class)->tag($image);

        return $image;
    }
}
