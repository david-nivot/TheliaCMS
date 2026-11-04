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
