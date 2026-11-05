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

namespace TheliaCMS\Tests\Integration\Twig;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use TheliaCMS\Catalogue\CatalogueItem;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Preview\ItemDraftRequest;
use TheliaCMS\Preview\ItemPreviewLink;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;
use TheliaCMS\Twig\ItemContentExtension;
use Twig\Environment;

/**
 * What a theme prints with cms_item_content(): the published document of the
 * item, and nothing at all otherwise; the draft on the page opened from the
 * signed link to it.
 */
final class ItemContentExtensionTest extends CmsIntegrationTestCase
{
    private const int PRODUCT_ID = 876543;

    public function testThePublishedDocumentIsPrintedAndTheDraftIsNot(): void
    {
        $writer = $this->getService(ContentWriter::class);
        $writer->saveContent($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()), Document::richText('<h2>Questions</h2><p>En ligne.</p>'));

        self::assertSame('', $this->render("{{ cms_item_content('product', id) }}"), 'A draft never reaches the front.');

        $writer->publish($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()));
        $writer->saveContent($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()), Document::richText('<h2>Questions</h2><p>Pas encore.</p>'));

        $printed = $this->render("{{ cms_item_content('product', id) }}");

        self::assertStringContainsString('<p>En ligne.</p>', $printed, 'Printed as markup, not escaped.');
        self::assertStringContainsString('cms-page-content', $printed, 'In the container the styles of the site are scoped to.');
        self::assertStringNotContainsString('Pas encore.', $printed, 'The draft saved since stays in the back office.');
    }

    public function testAGeneratedDocumentIsWrappedInAnElementThatCarriesTheMarkAndAHandWrittenOneIsNot(): void
    {
        $writer = $this->getService(ContentWriter::class);
        $writer->saveContent($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()), Document::richText('<h2>Questions</h2><p>Écrit à la main.</p>'));
        $writer->publish($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()));

        self::assertStringNotContainsString('data-ai-generated', $this->render("{{ cms_item_content('product', id) }}"), 'A document a person wrote is printed as it always was.');

        $writer->saveContent($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()), Document::richText('<h2>Questions</h2><p>Écrit par le modèle.</p>'), FakeAiGateway::provenance('theliacms.page_generation'));

        self::assertStringNotContainsString('data-ai-generated', $this->render("{{ cms_item_content('product', id) }}"), 'The mark of a draft is not the one of what is online.');

        $writer->publish($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()));
        $printed = $this->render("{{ cms_item_content('product', id) }}");

        self::assertStringStartsWith('<div class="cms-item-content" data-ai-generated="true" data-ai-usage="theliacms.page_generation" data-ai-model="fake-model" data-ai-generated-at="2026-10-28T10:00:00+00:00">', $printed);
        self::assertStringEndsWith('</div>', $printed);
        self::assertStringContainsString('<p>Écrit par le modèle.</p>', $printed);

        $writer->unpublish($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()));
        self::assertSame('', $this->render("{{ cms_item_content('product', id) }}"));
    }

    public function testAnythingElsePrintsNothing(): void
    {
        self::assertSame('', $this->render("{{ cms_item_content('product', id) }}"), 'No document.');
        self::assertSame('', $this->render("{{ cms_item_content('brand', id) }}"), 'A type that carries no document.');
        self::assertSame('', $this->render("{{ cms_item_content('product', null) }}"), 'No item.');
    }

    public function testThePageOpenedFromTheSignedLinkToTheDraftPrintsTheDraft(): void
    {
        $writer = $this->getService(ContentWriter::class);
        $writer->saveContent($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()), Document::richText('<h2>Questions</h2><p>En ligne.</p>'));
        $writer->publish($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()));
        $writer->saveContent($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()), Document::richText('<h2>Questions</h2><p>Pas encore.</p>'));

        $links = $this->getService(ItemPreviewLink::class);
        $item = static fn (int $id, string $locale): CatalogueItem => new CatalogueItem(CatalogueItemType::Product, $id, $locale, 'Produit', null, null, null, 'https://shop.test/produit.html');

        $draft = $this->openedFrom((string) $links->pageUrlFor($item(self::PRODUCT_ID, $this->locale())));
        $printed = $this->render("{{ cms_item_content('product', id) }}");
        self::assertStringContainsString('Pas encore.', $printed, 'The draft, in the page it goes online on.');
        self::assertStringContainsString('data-cms-section', $printed, 'Marked, so the editor finds each section in the page.');
        self::assertTrue($draft->attributes->get(ItemDraftRequest::SERVED_ATTRIBUTE), 'Marked, so the answer stays out of caches.');

        $signed = (string) $links->pageUrlFor($item(self::PRODUCT_ID, $this->locale()));
        // The signature is hexadecimal: its last character is changed to
        // another one, never to a fixed one it may already be.
        $tampered = substr($signed, 0, -1).('0' === substr($signed, -1) ? '1' : '0');

        $refused = [
            'another item' => (string) $links->pageUrlFor($item(self::PRODUCT_ID + 1, $this->locale())),
            'another language' => (string) $links->pageUrlFor($item(self::PRODUCT_ID, 'xx_XX')),
            'an expired link' => (string) $links->pageUrlFor($item(self::PRODUCT_ID, $this->locale()), new \DateTimeImmutable('-73 hours')),
            'a tampered link' => $tampered,
        ];

        foreach ($refused as $case => $url) {
            $this->leave();
            $request = $this->openedFrom($url);
            self::assertStringContainsString('En ligne.', $this->render("{{ cms_item_content('product', id) }}"), \sprintf('The link to %s shows what visitors see.', $case));
            self::assertNull($request->attributes->get(ItemDraftRequest::SERVED_ATTRIBUTE), $case);
        }
    }

    protected function tearDown(): void
    {
        $this->leave();

        parent::tearDown();
    }

    /**
     * The page being served, as opened from `$url`: the request the test case
     * runs under carries the query of the link.
     */
    private function openedFrom(string $url): Request
    {
        $request = $this->getService(RequestStack::class)->getMainRequest();
        \assert($request instanceof Request);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $request->query->replace($query);

        return $request;
    }

    private function leave(): void
    {
        $request = $this->getService(RequestStack::class)->getMainRequest();
        $request?->query->remove(ItemPreviewLink::PAGE_PARAMETER);
        $request?->attributes->remove(ItemDraftRequest::SERVED_ATTRIBUTE);
    }

    private function render(string $template): string
    {
        $twig = $this->getService(Environment::class);
        \assert($twig instanceof Environment);

        self::assertNotNull($twig->getExtension(ItemContentExtension::class));

        return $twig->createTemplate($template)->render(['id' => self::PRODUCT_ID]);
    }
}
