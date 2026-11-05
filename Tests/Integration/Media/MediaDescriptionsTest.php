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

namespace TheliaCMS\Tests\Integration\Media;

use TheliaCMS\Media\ImageState;
use TheliaCMS\Media\MediaDescriptions;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Integration\CountsStatements;
use TheliaLibrary\Model\LibraryImage;

/**
 * What the library recorded about the description of the images a page holds,
 * read in the language of the page and for all of them at once.
 */
final class MediaDescriptionsTest extends CmsIntegrationTestCase
{
    use CountsStatements;

    public function testAnImageDescribedInOneLanguageSaysNothingInTheOther(): void
    {
        $image = $this->image('en_US', 'A chair');
        $french = $this->url($image);

        self::assertSame(ImageState::Described, $this->states([$french], 'en_US')[$french]);
        self::assertSame(ImageState::Undescribed, $this->states([$french], 'fr_FR')[$french]);
    }

    public function testADecorativeImageIsDecorativeWhateverItsText(): void
    {
        $image = $this->image('fr_FR', null, decorative: true);
        $url = $this->url($image);

        self::assertSame(ImageState::Decorative, $this->states([$url], 'fr_FR')[$url]);
        self::assertSame(ImageState::Decorative, $this->states([$url], 'en_US')[$url]);
    }

    public function testABlankTextDescribesNothing(): void
    {
        $url = $this->url($this->image('en_US', '   '));

        self::assertSame(ImageState::Undescribed, $this->states([$url], 'en_US')[$url]);
    }

    public function testADeletedImageAndAnAddressOutsideTheLibraryAreAbsent(): void
    {
        $image = $this->image('en_US', 'Gone');
        $url = $this->url($image);
        $image->delete();

        $states = $this->states([$url, 'https://example.com/a.jpg', '/uploads/a.jpg', '/image-library/999999999/full/max/0/default.jpg'], 'en_US');

        self::assertSame([], $states);
    }

    public function testTheAddressesOfAPageCostOneStatementWhateverTheirNumber(): void
    {
        $few = array_map(fn (): string => $this->url($this->image('en_US', 'x')), range(1, 2));
        $many = array_map(fn (): string => $this->url($this->image('en_US', 'x')), range(1, 12));

        $costOfFew = $this->statementsFor(fn () => $this->states($few, 'en_US'));
        $costOfMany = $this->statementsFor(fn () => $this->states($many, 'en_US'));

        self::assertSame(1, $costOfFew);
        self::assertSame($costOfFew, $costOfMany);
        self::assertCount(12, $this->states($many, 'en_US'));
    }

    /**
     * @param list<string> $urls
     *
     * @return array<string, ImageState>
     */
    private function states(array $urls, string $locale): array
    {
        return $this->getService(MediaDescriptions::class)->statesOf($urls, $locale);
    }

    private function url(LibraryImage $image): string
    {
        return '/image-library/'.$image->getId().'/full/800,600/0/default.jpg';
    }

    private function image(string $locale, ?string $alt, bool $decorative = false): LibraryImage
    {
        $image = (new LibraryImage())->setFileName(bin2hex(random_bytes(6)).'.jpg')->setWidth(800)->setHeight(600)->setDecorative($decorative ? 1 : 0);
        $image->setLocale($locale)->setTitle('Image');

        if (null !== $alt) {
            $image->setAlt($alt);
        }

        $image->save();

        return $image;
    }
}
