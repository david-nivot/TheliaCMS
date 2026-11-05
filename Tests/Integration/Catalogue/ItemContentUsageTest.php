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

namespace TheliaCMS\Tests\Integration\Catalogue;

use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Admin\SectionUsageFinder;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Content\ImageSection;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentUsage;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Content\Site\SitePropReader;
use TheliaCMS\Media\Admin\MediaUsageFinder;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Unit\Content\Site\PriceTable;
use TheliaLibrary\Model\LibraryImage;

/**
 * What the document of a catalogue item uses is in use: an image nobody may
 * delete from under it, a component nobody may put away or change so that it
 * would refuse what the document holds.
 */
final class ItemContentUsageTest extends CmsIntegrationTestCase
{
    private const int PRODUCT_ID = 765432;

    public function testAnImageInTheDocumentOfAnItemIsInUse(): void
    {
        $image = new LibraryImage();
        $image->setDecorative(1);
        $image->setLocale($this->locale())->setFileName('item.jpg')->setTitle('item.jpg');
        $image->save();
        $this->getService(CmsMediaLibrary::class)->tag($image);
        $imageId = (int) $image->getId();

        $this->itemWriter()->saveContent($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()), new Document([
            new Section('picture', ImageSection::NAME, ['image' => $imageId, 'alt' => '']),
        ]));

        $finder = $this->getService(MediaUsageFinder::class);

        self::assertSame([$imageId => 1], $finder->countsFor([$imageId]), 'The media grid would show the image as unused.');
        self::assertSame(1, $finder->useCount($imageId), 'Deleting the image would be allowed.');
        self::assertSame([['type' => CatalogueItemType::Product, 'id' => self::PRODUCT_ID, 'locales' => [$this->locale()]]], $finder->itemsUsing($imageId));
    }

    public function testAComponentInTheDocumentOfAnItemIsCountedAsUsed(): void
    {
        $before = $this->getService(SectionUsageFinder::class)->pageCounts()['cms:richtext'] ?? 0;

        $this->itemWriter()->saveContent($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()), Document::richText('<h2>Titre</h2>'));
        $this->itemWriter()->saveContent($this->itemTarget(CatalogueItemType::Category, self::PRODUCT_ID, $this->locale()), Document::richText('<h2>Titre</h2>'));

        self::assertSame($before + 2, $this->getService(SectionUsageFinder::class)->pageCounts()['cms:richtext'] ?? 0, 'Two items holding the same id are two uses.');
    }

    public function testAVersionOfAComponentThatWouldBreakTheDocumentOfAnItemNamesIt(): void
    {
        $repository = $this->getService(SiteComponentRepository::class);
        $repository->save((new SiteComponentValidator())->validate(PriceTable::component()), SiteComponentRepository::STATUS_READY);

        $this->itemWriter()->saveContent($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()), new Document([
            new Section('plans', 'Site:PriceTable', ['plans' => [['name' => 'Studio', 'price' => '40 €']]]),
        ]));

        $renamed = PriceTable::renamingThePlanName();
        $breakages = $this->getService(SiteComponentUsage::class)->breakages($renamed, (new SitePropReader())->read($renamed->props));

        self::assertCount(1, $breakages);
        self::assertStringContainsString(\sprintf('The product #%d', self::PRODUCT_ID), $breakages[0]);
        self::assertStringContainsString('"plans"', $breakages[0]);
    }

    private function itemWriter(): ContentWriter
    {
        return $this->getService(ContentWriter::class);
    }
}
