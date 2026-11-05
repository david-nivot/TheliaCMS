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

namespace TheliaCMS\Tests\Integration\Content;

use TheliaCMS\Content\HighlightsSection;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaLibrary\Model\LibraryImage;

/**
 * The pictures of a block drawn from the media library: the address of the
 * file for a computer, the one chosen for a narrow screen, and the text the
 * library holds for each language.
 */
final class HighlightsRenderTest extends CmsIntegrationTestCase
{
    public function testTheMobileImageOfTheLibraryIsDrawnBelow1024Pixels(): void
    {
        $desktop = $this->image('Un salon');
        $mobile = $this->image('Un salon, vu de près');

        $html = $this->render(['title' => 'Salons', 'image' => $desktop->getId(), 'image_mobile' => $mobile->getId()]);

        self::assertStringContainsString(\sprintf('<source media="(max-width: 1023px)" srcset="/image-library/%d/full/max/0/default.jpg">', $mobile->getId()), $html);
        self::assertStringContainsString(\sprintf('src="/image-library/%d/full/max/0/default.jpg"', $desktop->getId()), $html);
        self::assertStringContainsString('alt="Un salon"', $html, 'The text of the picture of the computer, which the img carries on every screen.');
    }

    public function testWithoutAMobileImageThePictureOfTheComputerIsAloneInTheBlock(): void
    {
        $desktop = $this->image('Un salon');

        $html = $this->render(['title' => 'Salons', 'image' => $desktop->getId()]);

        self::assertStringNotContainsString('<source', $html);
        self::assertStringNotContainsString('<picture', $html);
        self::assertStringContainsString(\sprintf('src="/image-library/%d/full/max/0/default.jpg"', $desktop->getId()), $html);
    }

    public function testADecorativeImageHasAnEmptyText(): void
    {
        $image = $this->image(null, decorative: true);

        self::assertStringContainsString('alt=""', $this->render(['title' => 'Salons', 'image' => $image->getId(), 'image_alt' => 'Ancien texte']));
    }

    /**
     * @param array<string, mixed> $block
     */
    private function render(array $block): string
    {
        $section = new HighlightsSection();

        return $section->render($section->normalizeProps(['blocks' => [$block]]), $this->locale());
    }

    private function image(?string $alt, bool $decorative = false): LibraryImage
    {
        $image = (new LibraryImage())->setFileName(bin2hex(random_bytes(6)).'.jpg')->setDecorative($decorative ? 1 : 0);
        $image->setLocale($this->locale())->setTitle('Image')->setAlt($alt);
        $image->save();

        $this->getService(CmsMediaLibrary::class)->tag($image);

        return $image;
    }
}
