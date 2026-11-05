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

use Thelia\Domain\Ai\AiGatewayInterface;
use Thelia\Model\Content;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Catalogue\Admin\ItemContentTabHook;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentVersions;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Content\ImageSection;
use TheliaCMS\Content\Section;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Model\CmsItemContentQuery;
use TheliaCMS\Model\CmsItemRevisionQuery;
use TheliaCMS\Model\CmsPageGenerationQuery;
use TheliaCMS\Preview\ItemPreviewLink;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;
use TheliaLibrary\Model\LibraryImage;

/**
 * The document of a catalogue item in the back office: the tab on the edit
 * screen of the item, the content screen of a page used for it, and the
 * signed preview of its draft.
 */
final class ItemContentScreenTest extends AdminScreenTestCase
{
    private FakeAiGateway $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeAiGateway();
        static::getContainer()->set(AiGatewayInterface::class, $this->api);
    }

    public function testTheEditScreenOfTheItemLeadsToItsContentScreen(): void
    {
        $content = $this->content();

        $screen = $this->screen(\sprintf('/admin/content/update/%d', $content->getId()));
        $tab = $screen->filter(\sprintf('#%s [data-testid="cms-item-content-tab"]', ItemContentTabHook::TAB_ID));

        self::assertSame(1, $screen->filter('[data-testid="content-tab-'.ItemContentTabHook::TAB_ID.'"]')->count(), 'The edit screen of a content has the tab of the CMS.');
        self::assertSame(1, $tab->count());
        self::assertGreaterThan(0, $tab->filter('[data-testid="cms-item-content-language"]')->count(), 'A row per language.');
        self::assertStringContainsString(
            \sprintf('/admin/cms/catalogue/content/%d/content', $content->getId()),
            (string) $tab->filter('[data-testid="cms-item-content-edit"]')->first()->attr('href'),
        );
    }

    public function testTheContentScreenOpensForTheItemAndAnUnknownItemIsNotFound(): void
    {
        $content = $this->content();

        $screen = $this->screen($this->url($content));

        self::assertSame(1, $screen->filter('[data-testid="cms-item-content"]')->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-content-empty"]')->count(), 'Nothing written yet.');
        self::assertStringContainsString(\sprintf('/admin/content/update/%d', $content->getId()), (string) $screen->filter('[data-testid="cms-content-back"]')->attr('href'));

        $this->client->request('GET', '/admin/cms/catalogue/content/999999/content');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', \sprintf('/admin/cms/catalogue/brand/%d/content', $content->getId()));
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'A brand carries no document.');
    }

    public function testASectionAddedByHandIsPublishedOnTheItem(): void
    {
        $content = $this->content();

        $form = $this->screen($this->url($content))->filter('[data-testid="cms-content-add-cms:richtext"]')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $row = CmsItemContentQuery::create()->filterByItemType('content')->filterByItemId($content->getId())->filterByLocale($this->locale())->findOne();
        self::assertNotNull(Document::fromJson($row?->getDraftDocument()), 'The section is in the draft of the item.');
        self::assertNull($row?->getPublishedHtml());

        $publish = $this->screen($this->url($content))->filter('form[data-testid="cms-content-publish"]')->form();
        self::assertSame(302, $this->send('POST', $publish->getUri(), $publish->getPhpValues()));

        $row->reload();
        self::assertNotNull($row->getPublishedAt(), 'Published from the screen.');
        self::assertNotEmpty($row->getPublishedHtml());

        $tab = $this->screen(\sprintf('/admin/content/update/%d', $content->getId()));
        $unpublish = $tab->filter('[data-testid="cms-item-content-unpublish"]')->first()->form();
        self::assertSame(302, $this->send('POST', $unpublish->getUri(), $unpublish->getPhpValues()));

        $row->reload();
        self::assertNull($row->getPublishedAt(), 'Taken offline from the tab.');
    }

    public function testASectionOfTheItemIsDuplicatedRightAfterItself(): void
    {
        $content = $this->content();

        $add = $this->screen($this->url($content))->filter('[data-testid="cms-content-add-cms:richtext"]')->form();
        self::assertSame(302, $this->send('POST', $add->getUri(), $add->getPhpValues()));

        $duplicate = $this->screen($this->url($content))->filter('[data-testid="cms-section-duplicate-richtext"]')->form();
        self::assertSame(302, $this->send('POST', $duplicate->getUri(), $duplicate->getPhpValues()));

        $row = CmsItemContentQuery::create()->filterByItemType('content')->filterByItemId($content->getId())->filterByLocale($this->locale())->findOne();
        $document = Document::fromJson($row?->getDraftDocument());

        self::assertSame(['richtext', 'richtext-2'], array_map(static fn (Section $section): string => $section->id, $document?->sections ?? []), 'The item takes the same duplication as a page.');
        self::assertSame($document?->section('richtext')?->props, $document?->section('richtext-2')?->props);
        self::assertSame(1, $this->screen($this->url($content))->filter('[data-testid="cms-builder-flash-success"]')->count());
    }

    public function testADraftTheValidatorRefusesIsReportedRatherThanPublished(): void
    {
        $content = $this->content();

        $form = $this->screen($this->url($content))->filter('[data-testid="cms-content-add-cms:richtext"]')->form();
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $row = CmsItemContentQuery::create()->filterByItemType('content')->filterByItemId($content->getId())->filterByLocale($this->locale())->findOne();
        $row?->setDraftDocument(Document::richText('<p>Écrit par un script.</p>', 'Not an id')->toJson())->save();

        $publish = $this->screen($this->url($content))->filter('form[data-testid="cms-content-publish"]')->form();
        self::assertSame(302, $this->send('POST', $publish->getUri(), $publish->getPhpValues()), 'The screen says why, it does not fail.');

        $row?->reload();
        self::assertNull($row?->getPublishedAt(), 'Nothing went online.');
    }

    public function testABriefWritesTheDraftOfTheItemAndIsFiledUnderIt(): void
    {
        $content = $this->content();

        $this->api->answer(FakeAiGateway::response(json_encode(['version' => 1, 'sections' => [
            ['id' => 'faq', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Questions</h2><p>Sur devis.</p>']],
        ]], \JSON_THROW_ON_ERROR)));

        $form = $this->screen($this->url($content))->filter('[data-testid="cms-content-brief"] form')->form();
        $form['cms_brief[text]'] = 'Les questions fréquentes sur ce contenu.';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $row = CmsItemContentQuery::create()->filterByItemType('content')->filterByItemId($content->getId())->filterByLocale($this->locale())->findOne();
        self::assertStringContainsString('Sur devis.', (string) $row?->getDraftHtml());
        self::assertSame(1, CmsPageGenerationQuery::create()->filterByTargetType('content')->filterByTargetId($content->getId())->count(), 'The call is in the ledger, under the item.');

        $prompt = $this->api->requests[0]->messages[0]->content;
        self::assertStringContainsString('# The content this document is shown on', $prompt, 'The model is told what the document is shown on.');
        self::assertStringContainsString('- Name: Notre méthode', $prompt);
        self::assertStringContainsString('repeat none of it', $prompt);
        self::assertStringNotContainsString('# The page', $prompt, 'An item is not a page of the CMS.');
    }

    public function testTheDraftOfAnItemABriefReplacesIsKeptAndCanBePutBack(): void
    {
        $content = $this->content();
        $id = (int) $content->getId();
        $writer = $this->getService(ContentWriter::class);
        $writer->saveContent($this->itemTarget(CatalogueItemType::Content, $id, $this->locale()), Document::richText('<h2>Avant</h2><p>Écrit à la main.</p>'));

        $this->api->answer(FakeAiGateway::response(json_encode(['version' => 1, 'sections' => [
            ['id' => 'intro', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Après</h2><p>Écrit par le générateur.</p>']],
        ]], \JSON_THROW_ON_ERROR)));
        $form = $this->screen($this->url($content))->filter('[data-testid="cms-content-brief"] form')->form();
        $form['cms_brief[text]'] = 'Les questions fréquentes.';
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $drafts = $this->screen($this->url($content))->filter('[data-testid="cms-content-previous-drafts"] form[data-cms-restore]');
        self::assertCount(1, $drafts, 'The draft the brief replaced is kept.');

        $restore = $drafts->first()->form();
        self::assertSame(302, $this->send('POST', $restore->getUri(), $restore->getPhpValues()));

        $row = CmsItemContentQuery::create()->filterByItemType('content')->filterByItemId($id)->filterByLocale($this->locale())->findOne();
        self::assertStringContainsString('Écrit à la main.', (string) $row?->getDraftHtml(), 'The previous draft is the draft again.');
        self::assertSame(2, CmsItemRevisionQuery::create()->filterByItemType('content')->filterByItemId($id)->filterByLabel(ContentVersions::DRAFT_LABEL)->count(), 'The draft it replaced is kept in turn.');
    }

    public function testTheSignedLinkShowsTheDraftAndATamperedOneIsNotFound(): void
    {
        $content = $this->content();
        $this->getService(ContentWriter::class)->saveContent($this->itemTarget(CatalogueItemType::Content, (int) $content->getId(), $this->locale()), Document::richText('<h2>Brouillon</h2><p>Pas encore en ligne.</p>'));

        $url = $this->getService(ItemPreviewLink::class)->urlFor(CatalogueItemType::Content, (int) $content->getId(), $this->locale());

        $preview = $this->screen($url);
        self::assertStringContainsString('Pas encore en ligne.', $preview->filter('.cms-page-content')->text());
        self::assertSame('noindex, nofollow', $this->client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'), 'The signed address does not leak through the Referer.');

        $this->client->request('GET', substr($url, 0, -1).('0' === substr($url, -1) ? '1' : '0'));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testThePreviewShowsTheWholePageOfAnItemOnlineAndTheContentAloneOtherwise(): void
    {
        $content = $this->content();
        $this->getService(ContentWriter::class)->saveContent($this->itemTarget(CatalogueItemType::Content, (int) $content->getId(), $this->locale()), Document::richText('<h2>Brouillon</h2><p>Pas encore en ligne.</p>'));

        $screen = $this->screen($this->url($content));
        $frame = (string) $screen->filter('[data-testid="cms-content-frame"]')->attr('src');
        $modes = $screen->filter('[data-testid="cms-content-preview-modes"] input');

        self::assertSame(2, $modes->count(), 'The whole page, or the content alone.');
        self::assertStringContainsString(ItemPreviewLink::PAGE_PARAMETER.'=', $frame, 'The page of the item first.');
        self::assertSame($frame, $modes->eq(0)->attr('data-url'));
        self::assertSame($frame, $screen->filter('[data-testid="cms-page-draft-preview-btn"]')->attr('href'), 'The link to share follows.');
        self::assertStringContainsString('/cms-preview/item/content/', (string) $modes->eq(1)->attr('data-url'));
        self::assertGreaterThan(1, $screen->filter('[data-testid="cms-content-preview-width"] option')->count(), 'Responsive, then the breakpoints of the theme.');

        $content->setVisible(0)->save();
        $offline = $this->screen($this->url($content));

        self::assertSame(0, $offline->filter('[data-testid="cms-content-preview-modes"]')->count(), 'An offline item has no page to show the draft on.');
        self::assertStringContainsString('/cms-preview/item/content/', (string) $offline->filter('[data-testid="cms-content-frame"]')->attr('src'));
    }

    public function testTheScreenOfAnImageNamesTheItemsUsingItAndKeepsItFromBeingDeleted(): void
    {
        $content = $this->content();
        $image = new LibraryImage();
        $image->setDecorative(1);
        $image->setLocale($this->locale())->setFileName('item-screen.jpg')->setTitle('item-screen.jpg');
        $image->save();
        $this->getService(CmsMediaLibrary::class)->tag($image);

        $this->getService(ContentWriter::class)->saveContent($this->itemTarget(CatalogueItemType::Content, (int) $content->getId(), $this->locale()), new Document([
            new Section('picture', ImageSection::NAME, ['image' => (int) $image->getId(), 'alt' => '']),
        ]));

        $screen = $this->screen(\sprintf('/admin/cms/media/%d', $image->getId()));
        $usage = $screen->filter('[data-testid="cms-media-item-usage"]');

        self::assertSame(1, $usage->count());
        self::assertStringContainsString('Notre méthode', $usage->text());
        self::assertStringContainsString(\sprintf('/admin/cms/catalogue/content/%d/content', $content->getId()), (string) $usage->filter('a')->attr('href'));
        self::assertNotNull($screen->filter('form[action*="/delete"] button')->attr('disabled'), 'An image in use cannot be deleted.');
    }

    private function content(): Content
    {
        $factory = new FixtureFactory($this->getPropelConnection());

        return $factory->content($factory->folder(), ['title' => 'Notre méthode', 'locale' => $this->locale()]);
    }

    private function url(Content $content): string
    {
        return \sprintf('/admin/cms/catalogue/content/%d/content', $content->getId());
    }
}
