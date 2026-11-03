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

use Thelia\Core\Hook\Theme\ThemeHookInterface;
use Thelia\Model\ModuleConfigQuery;
use TheliaCMS\Catalogue\Admin\ItemContentWriter;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\ItemContentThemeHook;
use TheliaCMS\Content\Document;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;

/**
 * Whether the page of a product or a category, on a theme that never calls
 * cms_item_content(), shows the document published for it: measured on the
 * hooks Flexy fires, with the item passed the way Flexy passes it.
 */
final class ItemContentThemeHookTest extends CmsIntegrationTestCase
{
    private const int ITEM_ID = 876544;

    protected function setUp(): void
    {
        parent::setUp();

        // The rows go with the transaction of the test; the values the core
        // keeps in memory do not.
        ModuleConfigQuery::resetConfigCache();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        ModuleConfigQuery::resetConfigCache();
    }

    public function testThePublishedDocumentOfAProductIsPrintedBeforeTheProductsRelatedToIt(): void
    {
        $this->publish(CatalogueItemType::Product, '<h2>Questions</h2><p>Sur la fiche.</p>');

        $printed = $this->render('product.content', ['product' => ['id' => self::ITEM_ID]]);

        self::assertStringContainsString('<p>Sur la fiche.</p>', $printed);
        self::assertStringStartsWith('<div class="cms-item-hook">', $printed, 'Spaced from the sections of the theme around it.');
    }

    public function testThePublishedDocumentOfACategoryIsPrintedAtTheBottomOfItsPage(): void
    {
        $this->publish(CatalogueItemType::Category, '<h2>Guide</h2><p>Sur la rubrique.</p>');

        self::assertStringContainsString('<p>Sur la rubrique.</p>', $this->render('category.bottom', ['category' => ['id' => self::ITEM_ID]]));
    }

    public function testTheHookIsOnWhileTheSettingWasNeverSaved(): void
    {
        $this->publish(CatalogueItemType::Product, '<p>Par défaut.</p>');

        // Saving null stores an empty string: the row itself has to go.
        ModuleConfigQuery::create()->filterByModuleId(TheliaCMS::getModuleId())->filterByName(ItemContentThemeHook::SETTING)->delete();
        ModuleConfigQuery::resetConfigCache();

        // A site that installs or updates the module sees the document it
        // published without hunting for a setting.
        self::assertStringContainsString('Par défaut.', $this->render('product.content', ['product' => ['id' => self::ITEM_ID]]));
    }

    public function testATurnedOffHookPrintsNothing(): void
    {
        $this->publish(CatalogueItemType::Product, '<p>Déjà imprimé par le thème.</p>');

        TheliaCMS::setConfigValue(ItemContentThemeHook::SETTING, '0');

        // A theme that calls cms_item_content() itself would show it twice.
        self::assertSame('', $this->render('product.content', ['product' => ['id' => self::ITEM_ID]]));
    }

    public function testTheDocumentOfOneItemIsNotPrintedOnThePageOfAnotherType(): void
    {
        $this->publish(CatalogueItemType::Product, '<p>Produit seulement.</p>');

        // A category with the same id as the product has no document.
        self::assertSame('', $this->render('category.bottom', ['category' => ['id' => self::ITEM_ID]]));
    }

    public function testAPageWithoutItsItemPrintsNothing(): void
    {
        $this->publish(CatalogueItemType::Product, '<p>Orphelin.</p>');

        self::assertSame('', $this->render('product.content', []), 'No parameter.');
        self::assertSame('', $this->render('product.content', ['product' => null]), 'An item the API did not find.');
    }

    public function testOnlyTheContentOfAProductAndTheBottomOfACategoryAreAnswered(): void
    {
        $hook = $this->getService(ItemContentThemeHook::class);

        self::assertTrue($hook->supports('product.content'));
        self::assertTrue($hook->supports('category.bottom'));
        self::assertFalse($hook->supports('product.bottom'), 'One place per page, or the document shows twice.');
        self::assertFalse($hook->supports('product.top'));
        self::assertFalse($hook->supports('brand.bottom'), 'A brand carries no document.');
        self::assertInstanceOf(ThemeHookInterface::class, $hook);
    }

    private function publish(CatalogueItemType $type, string $html): void
    {
        $writer = $this->getService(ItemContentWriter::class);
        $writer->saveContent($type, self::ITEM_ID, $this->locale(), Document::richText($html));
        $writer->publish($type, self::ITEM_ID, $this->locale());
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function render(string $hookName, array $parameters): string
    {
        return $this->getService(ItemContentThemeHook::class)->render($hookName, $parameters);
    }
}
