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

namespace TheliaCMS\Tests\Integration\Generation;

use Symfony\Component\DomCrawler\Crawler;
use Thelia\Model\Lang;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaLibrary\Model\LibraryImage;

/**
 * The image of a section is chosen in the media library, not in a list of
 * titles: the form holds its id, the picker reads the library by pages. A
 * width is chosen on buttons that show it.
 */
final class SectionImagePickerTest extends AdminScreenTestCase
{
    public function testTheImageOfASectionIsAnIdTheScreenDrawsAndPicks(): void
    {
        $page = $this->pageWith(null);

        $field = $this->dialog($page)->filter('input[name="cms_section_picture[image]"]');

        self::assertSame(1, $field->count(), 'One field, not a list of every image.');
        self::assertSame('number', $field->attr('type'));
        self::assertStringEndsWith('/admin/cms/media/picker', (string) $field->attr('data-cms-image-picker'));
        self::assertStringEndsWith('/admin/cms/media', (string) $field->attr('data-cms-image-upload'), 'Whoever may add to the library sends an image from the picker.');
        self::assertSame(0, $this->dialog($page)->filter('select[name="cms_section_picture[image]"]')->count());
    }

    public function testAnImageOfTheLibraryIsKeptAndAnIdFromElsewhereIsNot(): void
    {
        $page = $this->pageWith(null);
        $image = $this->image('Fauteuil vert');
        $foreign = (new LibraryImage())->setFileName('produit.jpg');
        $foreign->setLocale($this->locale())->setTitle('Image d’un produit');
        $foreign->save();

        self::assertSame($image->getId(), $this->save($page, (string) $image->getId()));
        self::assertNull($this->save($page, (string) $foreign->getId()), 'An image the CMS does not own is no image of a section.');
    }

    public function testThePickerReadsTheLibraryByPagesAndOneImageByItsId(): void
    {
        $chosen = $this->image('Fauteuil vert', alt: 'Un fauteuil');

        for ($n = 1; $n <= 49; ++$n) {
            $this->image(\sprintf('Image %02d', $n));
        }

        $this->client->request('GET', '/admin/cms/media/picker', [], [], self::XHR);
        $page = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertCount(48, $page['items']);
        self::assertSame(50, $page['total']);
        self::assertSame(2, $page['pageCount']);

        $this->client->request('GET', '/admin/cms/media/picker?search=vert', [], [], self::XHR);
        $found = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([$chosen->getId()], array_column($found['items'], 'id'));
        self::assertTrue($found['items'][0]['described']);

        $this->client->request('GET', '/admin/cms/media/picker?id='.$chosen->getId(), [], [], self::XHR);
        self::assertSame('Fauteuil vert', json_decode((string) $this->client->getResponse()->getContent(), true)['items'][0]['title']);

        $this->client->request('GET', '/admin/cms/media/picker?id=999999', [], [], self::XHR);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testTheWidthIsChosenOnButtonsThatShowIt(): void
    {
        $buttons = $this->dialog($this->pageWith(null))->filter('[data-testid="cms-choice-width"]');

        self::assertSame(4, $buttons->filter('input[type="radio"].btn-check')->count(), 'Narrow, column, full, a size in pixels: no "none" for a width.');
        self::assertSame('narrow', $buttons->filter('input:checked')->attr('value'));
        self::assertSame(3, $buttons->filter('label svg')->count(), 'Each width drawn as its shape.');
        self::assertSame(1, $buttons->filter('label i.bi-rulers')->count(), 'The size in pixels has the pictogram of a ruler.');
    }

    public function testTheFormFollowsTheOrderOfThePageAndSpeaksToTheEditor(): void
    {
        $dialog = $this->dialog($this->pageWith(null));
        $names = $dialog->filter('form[data-cms-section-form] [name^="cms_section_picture["]')->each(static fn (Crawler $field): string => (string) $field->attr('name'));
        $order = array_values(array_unique(array_map(static fn (string $name): string => explode(']', substr($name, \strlen('cms_section_picture[')))[0], $names)));
        $visible = array_values(array_intersect($order, ['image', 'width', 'width_px', 'height_px', 'fit', 'focus_x', 'focus_y', 'zoom', 'caption', 'alt']));

        self::assertSame(['image', 'width', 'width_px', 'height_px', 'fit', 'focus_x', 'focus_y', 'zoom', 'caption', 'alt'], $visible);
        self::assertStringContainsString('How wide the picture is on the page', $dialog->filter('[data-testid="cms-choice-width"]')->text(), 'The help written for the editor, not the instruction to the model.');
        self::assertStringNotContainsString('wider only when the brief asks', $dialog->filter('[data-testid="cms-choice-width"]')->text());
        self::assertSame(1, $dialog->filter('[data-testid="cms-choice-fit"][data-cms-choice-buttons]')->count(), 'The fit is a choice of buttons, which take the whole line.');
    }

    /**
     * The script hides the pixels, the framing and the kept part and writes a
     * sentence for the last: served, every field is there and none is hidden,
     * and the words the script needs reach it with the screen.
     */
    public function testWithoutTheScriptEveryDimensionFieldIsServedAndShown(): void
    {
        $screen = $this->screen(\sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $this->pageWith(null)->getId(), Lang::getDefaultLanguage()->getId()));
        $form = $screen->filter('[data-testid="cms-section-edit-picture"] form[data-cms-section-form]');

        foreach (['proportions', 'width_px', 'height_px', 'fit', 'focus_x', 'focus_y', 'zoom', 'caption', 'alt'] as $name) {
            self::assertGreaterThan(0, $form->filter('[name="cms_section_picture['.$name.']"]')->count(), $name.' is in the form.');
        }

        self::assertSame(0, $form->filter('[hidden]')->count(), 'Nothing is hidden before the script runs.');
        self::assertGreaterThan(0, $form->filter('[data-testid="cms-choice-proportions"] input[type="radio"]')->count(), 'The proportions are radios, which work with no script.');

        $labels = json_decode((string) $screen->filter('[data-cms-content]')->attr('data-cms-content-labels'), true, 512, \JSON_THROW_ON_ERROR);

        foreach (['ratios', 'imageRatio', 'kept'] as $key) {
            self::assertArrayHasKey($key, $labels);
        }

        self::assertStringContainsString('%zoom%', $labels['kept']);
    }

    /**
     * The dialog that crops an image needs the size of the file the picker
     * shows, and its words and script come with the screen.
     */
    public function testTheScreenCarriesTheCropDialogAndThePickerTheSizeOfTheFile(): void
    {
        $image = $this->image('Fauteuil');
        $screen = $this->screen(\sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $this->pageWith($image->getId())->getId(), Lang::getDefaultLanguage()->getId()));

        self::assertSame(1, $screen->filter('script[data-cms-image-crop-messages]')->count());
        self::assertSame(1, $screen->filter('script[src*="assets/image-crop.js?v="]')->count());

        $words = json_decode($screen->filter('script[data-cms-image-crop-messages]')->text('', false), true, 512, \JSON_THROW_ON_ERROR);

        foreach (['open', 'title', 'noImage', 'unknownSize', 'sharp', 'fair', 'soft', 'live'] as $key) {
            self::assertNotSame('', $words[$key] ?? '', $key);
        }

        $this->client->request('GET', '/admin/cms/media/picker?id='.$image->getId(), [], [], self::XHR);
        $item = json_decode((string) $this->client->getResponse()->getContent(), true)['items'][0];

        self::assertSame([800, 600], [$item['width'], $item['height']], 'The size of the file, as numbers.');
    }

    public function testTheSizeIsKeptAsTheEditorChoseIt(): void
    {
        $page = $this->pageWith(null);
        $form = $this->dialog($page)->filter('form[data-cms-section-form]')->form();
        $values = $form->getPhpValues();
        $values['cms_section_picture'] = ['image' => (string) $this->image('Fauteuil')->getId(), 'alt' => 'Une image', 'width' => 'custom', 'width_px' => '960', 'height_px' => '540', 'fit' => 'cover', 'focus_x' => '35', 'focus_y' => '20', 'zoom' => '150'] + $values['cms_section_picture'];

        self::assertSame(302, $this->send('POST', $form->getUri(), $values));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        $props = Document::fromJson($content?->getDraftDocument())?->section('picture')->props ?? [];

        self::assertSame(['custom', 960, 540, 'cover', 35, 20, 150], [$props['width'], $props['width_px'], $props['height_px'], $props['fit'], $props['focus_x'], $props['focus_y'], $props['zoom']]);
    }

    public function testTheProportionsAreChosenOnButtonsThatShowTheirShape(): void
    {
        $buttons = $this->dialog($this->pageWith(null))->filter('[data-testid="cms-choice-proportions"]');

        self::assertSame(['original', '16:9', '4:3', '3:2', '1:1', '3:4'], $buttons->filter('input[type="radio"].btn-check')->each(static fn (Crawler $radio): string => (string) $radio->attr('value')));
        self::assertSame('original', $buttons->filter('input:checked')->attr('value'), 'The picture as it is, the markup of before.');
        self::assertSame(5, $buttons->filter('label svg')->count(), 'Each ratio drawn as its shape.');
        self::assertSame(1, $buttons->filter('label i.bi-image')->count());
    }

    public function testTheProportionsAndTheKeptPartAreKeptAsTheEditorChoseThem(): void
    {
        $page = $this->pageWith(null);
        $form = $this->dialog($page)->filter('form[data-cms-section-form]')->form();
        $values = $form->getPhpValues();
        $values['cms_section_picture'] = ['image' => (string) $this->image('Fauteuil')->getId(), 'width' => 'column', 'proportions' => '16:9', 'focus_x' => '30', 'focus_y' => '70', 'zoom' => '150'] + $values['cms_section_picture'];

        self::assertSame(302, $this->send('POST', $form->getUri(), $values));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        $props = Document::fromJson($content?->getDraftDocument())?->section('picture')->props ?? [];

        self::assertSame(['column', '16:9', 30, 70, 150], [$props['width'], $props['proportions'], $props['focus_x'], $props['focus_y'], $props['zoom']]);
    }

    /**
     * The kept part of each picture of the columns is a field of its row, so
     * a row added from the prototype carries it too, and each row is saved
     * with its own.
     */
    public function testEachRowOfTheColumnsKeepsItsOwnPartOfItsPicture(): void
    {
        $page = $this->createPage('Colonnes', published: false);
        $this->client->request('GET', '/admin/cms/pages');
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), new Document([
            new Section('partners', 'cms:columns', ['columns' => [['text' => 'Un'], ['text' => 'Deux'], ['text' => 'Trois']]]),
        ]));

        $dialog = $this->screen(\sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), Lang::getDefaultLanguage()->getId()))->filter('[data-testid="cms-section-edit-partners"]');

        self::assertSame(7, $dialog->filter('[data-testid="cms-choice-proportions"] input[type="radio"]')->count(), 'Band, the image as it is, and the five ratios.');
        self::assertSame('band', $dialog->filter('[data-testid="cms-choice-proportions"] input:checked')->attr('value'));

        foreach (['focus_x', 'focus_y', 'zoom'] as $name) {
            self::assertSame(3, $dialog->filter('[name^="cms_section_partners[columns]"][name$="['.$name.']"]')->count(), $name.' in each row.');
            self::assertStringContainsString('['.$name.']', (string) $dialog->filter('[data-cms-items]')->attr('data-prototype'), 'And in the prototype of a new row.');
        }

        $form = $dialog->filter('form[data-cms-section-form]')->form();
        $values = $form->getPhpValues();
        $values['cms_section_partners']['proportions'] = '1:1';
        $values['cms_section_partners']['columns'][0] = ['focus_x' => '10', 'focus_y' => '90', 'zoom' => '200'] + $values['cms_section_partners']['columns'][0];
        $values['cms_section_partners']['columns'][2] = ['focus_x' => '80', 'focus_y' => '20', 'zoom' => '120'] + $values['cms_section_partners']['columns'][2];

        self::assertSame(302, $this->send('POST', $form->getUri(), $values));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        $props = Document::fromJson($content?->getDraftDocument())?->section('partners')->props ?? [];
        $columns = array_map(static fn (array $column): array => [$column['focus_x'], $column['focus_y'], $column['zoom']], $props['columns']);

        self::assertSame('1:1', $props['proportions']);
        self::assertSame([[10, 90, 200], [50, 50, 100], [80, 20, 120]], $columns);
    }

    /**
     * The pictures of a component the module does not draw (here one made from
     * a brief) take the proportions and the kept part too, each its own, in
     * the form and in the rows of its lists.
     */
    public function testEachPictureOfAComponentOfTheSiteKeepsItsOwnFraming(): void
    {
        $this->getService(SiteComponentRepository::class)->save((new SiteComponentValidator())->validate(new SiteComponent(
            'GalleryCard',
            'Gallery card',
            'A photo and a list of pictures.',
            [
                ['name' => 'photo', 'type' => 'image', 'label' => 'Photo'],
                ['name' => 'gallery', 'type' => 'items', 'label' => 'Gallery', 'min' => 1, 'max' => 4, 'items' => [['name' => 'picture', 'type' => 'image', 'label' => 'Picture']]],
            ],
            ['photo' => null, 'gallery' => [['picture' => null]]],
            '<div><img src="{{ props.photo }}" alt="">{% for row in props.gallery %}<img src="{{ row.picture }}" alt="">{% endfor %}</div>',
        )), SiteComponentRepository::STATUS_READY);

        $page = $this->createPage('Galerie', published: false);
        $this->client->request('GET', '/admin/cms/pages');
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), new Document([
            new Section('gallery', 'Site:GalleryCard', ['photo' => null, 'gallery' => [['picture' => null], ['picture' => null]]]),
        ]));

        $dialog = $this->screen(\sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), Lang::getDefaultLanguage()->getId()))->filter('[data-testid="cms-section-edit-gallery"]');

        self::assertSame(['original', '16:9', '4:3', '3:2', '1:1', '3:4'], $dialog->filter('[data-testid="cms-choice-photo__proportions"] input[type="radio"]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value')));
        self::assertSame('original', $dialog->filter('[data-testid="cms-choice-photo__proportions"] input:checked')->attr('value'));

        foreach (['photo__focus_x', 'photo__focus_y', 'photo__zoom'] as $name) {
            self::assertSame(1, $dialog->filter('[name="cms_section_gallery['.$name.']"]')->count(), $name);
        }

        foreach (['picture__proportions', 'picture__focus_x', 'picture__focus_y', 'picture__zoom'] as $name) {
            self::assertStringContainsString('['.$name.']', (string) $dialog->filter('[data-cms-items]')->attr('data-prototype'), $name.' in the prototype of a new row.');
        }

        $form = $dialog->filter('form[data-cms-section-form]')->form();
        $values = $form->getPhpValues();
        $values['cms_section_gallery']['photo'] = (string) $this->image('Fauteuil')->getId();
        $values['cms_section_gallery'] = ['photo__proportions' => '1:1', 'photo__focus_x' => '10', 'photo__focus_y' => '90', 'photo__zoom' => '200'] + $values['cms_section_gallery'];
        $values['cms_section_gallery']['gallery'][1] = ['picture__proportions' => '3:4', 'picture__focus_x' => '80', 'picture__focus_y' => '20', 'picture__zoom' => '120'] + $values['cms_section_gallery']['gallery'][1];

        self::assertSame(302, $this->send('POST', $form->getUri(), $values));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        $props = Document::fromJson($content?->getDraftDocument())?->section('gallery')->props ?? [];

        self::assertSame(['1:1', 10, 90, 200], [$props['photo__proportions'], $props['photo__focus_x'], $props['photo__focus_y'], $props['photo__zoom']]);
        self::assertSame(['original', 50, 50, 100], [$props['gallery'][0]['picture__proportions'], $props['gallery'][0]['picture__focus_x'], $props['gallery'][0]['picture__focus_y'], $props['gallery'][0]['picture__zoom']]);
        self::assertSame(['3:4', 80, 20, 120], [$props['gallery'][1]['picture__proportions'], $props['gallery'][1]['picture__focus_x'], $props['gallery'][1]['picture__focus_y'], $props['gallery'][1]['picture__zoom']]);
    }

    private function save(CmsPage $page, string $imageId): ?int
    {
        $form = $this->dialog($page)->filter('form[data-cms-section-form]')->form();
        $values = $form->getPhpValues();
        $values['cms_section_picture']['image'] = $imageId;
        $values['cms_section_picture']['alt'] = 'Une image';

        self::assertSame(302, $this->send('POST', $form->getUri(), $values));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        $image = Document::fromJson($content?->getDraftDocument())?->section('picture')?->props['image'] ?? null;

        return null === $image || 0 === $image ? null : (int) $image;
    }

    private function dialog(CmsPage $page): Crawler
    {
        return $this->screen(\sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), Lang::getDefaultLanguage()->getId()))
            ->filter('[data-testid="cms-section-edit-picture"]');
    }

    private function pageWith(?int $imageId): CmsPage
    {
        $page = $this->createPage('Image choisie', published: false);
        $this->client->request('GET', '/admin/cms/pages');
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), new Document([
            new Section('picture', 'cms:image', ['image' => $imageId, 'alt' => '', 'caption' => '', 'width' => 'narrow']),
        ]));

        return $page;
    }

    private function image(string $title, ?string $alt = null): LibraryImage
    {
        $image = (new LibraryImage())->setFileName(bin2hex(random_bytes(6)).'.jpg')->setWidth(800)->setHeight(600)->setDecorative(0);
        $image->setLocale($this->locale())->setTitle($title)->setAlt($alt);
        $image->save();

        $this->getService(CmsMediaLibrary::class)->tag($image);

        return $image;
    }
}
