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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Thelia\Model\Lang;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Media\Admin\MediaUsageFinder;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Media\MediaKind;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\Mp4Videos;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\TheliaLibrary;

/**
 * The video of a section is chosen in the media library, filtered on the
 * videos: the form holds its id and the kind of file the picker asks for, no
 * address kept elsewhere is offered, and a file sent for it is an MP4 that
 * joins the library.
 */
final class SectionVideoPickerTest extends AdminScreenTestCase
{
    private const string FORM = 'cms_section_clips';

    protected function setUp(): void
    {
        parent::setUp();

        $this->getService(SiteComponentRepository::class)->save((new SiteComponentValidator())->validate(new SiteComponent(
            'ClipCard',
            'Clip card',
            'A video and a list of videos.',
            [
                ['name' => 'clip', 'type' => 'video', 'label' => 'Clip'],
                ['name' => 'gallery', 'type' => 'items', 'label' => 'Gallery', 'min' => 1, 'max' => 4, 'items' => [['name' => 'title', 'type' => 'text', 'label' => 'Title'], ['name' => 'video', 'type' => 'video', 'label' => 'Video']]],
            ],
            ['clip' => null, 'gallery' => [['title' => 'One', 'video' => null]]],
            '<div><video preload="none" src="{{ props.clip }}"></video>{% for row in props.gallery %}<a href="{{ row.video }}">{{ row.title }}</a>{% endfor %}</div>',
        )), SiteComponentRepository::STATUS_READY);
    }

    protected function tearDown(): void
    {
        foreach ($this->getService(CmsMediaLibrary::class)->images() as $image) {
            $file = TheliaLibrary::getImageDirectory().$image->getFileName();

            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testTheVideoOfASectionIsAnIdTheScreenDrawsAndPicksAmongTheVideos(): void
    {
        $dialog = $this->dialog($this->pageWith(null));
        $field = $dialog->filter('input[name="'.self::FORM.'[clip]"]');

        self::assertSame(1, $field->count());
        self::assertSame('number', $field->attr('type'));
        self::assertStringEndsWith('/admin/cms/media/picker', (string) $field->attr('data-cms-video-picker'));
        self::assertSame('video', $field->attr('data-cms-media-kind'), 'The field tells the picker which kind of file to ask for.');
        self::assertNull($field->attr('data-cms-image-picker'), 'Not an image field: the framing and the crop leave it alone.');
        self::assertStringEndsWith('/admin/cms/media', (string) $field->attr('data-cms-image-upload'), 'Whoever may add to the library sends a video from the picker.');

        $file = $dialog->filter('input[name="'.self::FORM.'[clip__file]"]');

        self::assertSame('file', $file->attr('type'));
        self::assertSame('video/mp4', $file->attr('accept'), 'Only an MP4 is offered.');
        self::assertSame(0, $dialog->filter('[name="'.self::FORM.'[clip__url]"]')->count(), 'No address of a video kept elsewhere.');
        self::assertSame(1, $dialog->filter('input[data-cms-media-kind="video"][name^="'.self::FORM.'[gallery]"]')->count(), 'A video of a row is the same field.');
        self::assertStringContainsString('data-cms-media-kind', (string) $dialog->filter('[data-cms-items]')->attr('data-prototype'), 'And in the prototype of a new row.');
    }

    public function testThePickerListsTheKindItIsAskedFor(): void
    {
        $library = $this->getService(CmsMediaLibrary::class);
        $image = $this->image('Fauteuil');
        $video = Mp4Videos::row($library, $this->locale(), 'Clip A');
        $other = Mp4Videos::row($library, $this->locale(), 'Clip B');
        $ids = fn (string $query): array => array_column($this->picker($query)['items'], 'id');

        self::assertSame([(int) $image->getId()], $ids(''), 'An image by default, as before.');
        self::assertSame([(int) $image->getId()], $ids('?kind=image'));
        self::assertSame([(int) $image->getId()], $ids('?kind=film'), 'Any other kind is an image.');
        self::assertEqualsCanonicalizing([(int) $video->getId(), (int) $other->getId()], $ids('?kind=video'));

        $listed = $this->picker('?kind=video&search=Clip+A');

        self::assertSame([(int) $video->getId()], array_column($listed['items'], 'id'));
        self::assertTrue($listed['items'][0]['video']);
        self::assertSame('/video-library/'.$video->getId().'.mp4', $listed['items'][0]['url']);
        self::assertNull($listed['items'][0]['thumbnail'], 'The screen plays the file: no thumbnail is made.');
        self::assertSame(2, $this->picker('?kind=video')['total']);

        self::assertArrayHasKey('tags', $this->picker('?kind=video'));
        $this->client->request('GET', '/admin/cms/media/picker?kind=video&id='.$video->getId(), [], [], self::XHR);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/admin/cms/media/picker?kind=video&id='.$image->getId(), [], [], self::XHR);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'The picker of videos refuses the id of an image.');
        $this->client->request('GET', '/admin/cms/media/picker?id='.$video->getId(), [], [], self::XHR);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'And the picker of images the id of a video.');
    }

    /**
     * Sabotage expected to turn it red: read `all` as an image in
     * `CmsMediaAdminController::picker()` (the picker can no longer list both).
     */
    public function testThePickerCanListBothKindsAndItsTagsFollowTheKindShown(): void
    {
        $library = $this->getService(CmsMediaLibrary::class);
        $image = $this->image('Fauteuil');
        $video = Mp4Videos::row($library, $this->locale(), 'Clip A');
        $library->retag($video, ['Films']);
        $ids = fn (string $query): array => array_column($this->picker($query)['items'], 'id');

        self::assertEqualsCanonicalizing([(int) $image->getId(), (int) $video->getId()], $ids('?kind=all'));
        self::assertSame(2, $this->picker('?kind=all')['total']);
        self::assertSame([(int) $video->getId()], $ids('?kind=all&tag=Films'), 'A tag narrows both kinds.');
        self::assertSame([], $ids('?kind=image&tag=Films'), 'And the images alone have no such video.');
        self::assertSame(['Films' => 1], $this->picker('?kind=video')['tags']);
        self::assertSame([], $this->picker('?kind=image')['tags'], 'The tags offered are those of the kind shown.');
        self::assertSame(['Films' => 1], $this->picker('?kind=all')['tags']);
    }

    /**
     * Sabotage expected to turn it red: take `data-cms-media-kind` off the
     * video field in `SectionFormBuilder` (the picker would open on images),
     * or drop the `type` words from `media/_picker.html.twig`.
     */
    public function testAVideoFieldOpensThePickerOnTheVideosAndThePickerSpeaksOfTheTypes(): void
    {
        $page = $this->pageWith(null);
        $field = $this->dialog($page)->filter('input[name="'.self::FORM.'[clip]"]');

        self::assertSame('video', $field->attr('data-cms-media-kind'));

        $source = (string) $this->client->getResponse()->getContent();

        foreach (['kindAll', 'kindImage', 'kindVideo', 'noTags'] as $word) {
            self::assertStringContainsString('"'.$word.'"', $source, 'The words of the type filter ship with the picker.');
        }
    }

    public function testAVideoOfTheLibraryIsKeptAndAnImageOrAForeignFileIsNot(): void
    {
        $page = $this->pageWith(null);
        $video = Mp4Videos::row($this->getService(CmsMediaLibrary::class), $this->locale());
        $image = $this->image('Fauteuil');
        $foreign = (new LibraryImage())->setFileName('produit.mp4')->setMimeType('video/mp4');
        $foreign->setLocale($this->locale())->setTitle('Video of a product');
        $foreign->save();

        self::assertSame($video->getId(), $this->save($page, (string) $video->getId()));
        self::assertNull($this->save($page, (string) $image->getId()), 'An image is no video of a section.');
        self::assertNull($this->save($page, (string) $foreign->getId()), 'A video the CMS does not own is no video of a section.');
        self::assertNull($this->save($page, ''), 'No choice is no video.');
    }

    public function testAFileSentForTheVideoJoinsTheLibraryAndIsTheOneChosen(): void
    {
        $page = $this->pageWith(null);
        $form = $this->dialog($page)->filter('form[data-cms-section-form]')->form();
        $values = $form->getPhpValues();

        $this->client->request('POST', $form->getUri(), $values, [self::FORM => ['clip__file' => Mp4Videos::small('nouveau.mp4')]], self::SAME_SITE);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $id = $this->draftProps($page)['clip'] ?? null;
        $stored = $this->getService(CmsMediaLibrary::class)->ownedImage((int) $id, MediaKind::Video);

        self::assertNotNull($stored, 'The MP4 is a video of the library of the CMS, and the section holds its id.');
        self::assertSame('video/mp4', $stored->getMimeType());
    }

    public function testAnImageSentForTheVideoIsRefusedAndNothingJoinsTheLibrary(): void
    {
        $page = $this->pageWith(null);
        $form = $this->dialog($page)->filter('form[data-cms-section-form]')->form();
        $path = (string) tempnam(sys_get_temp_dir(), 'cms-png-');
        file_put_contents($path, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));

        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), [self::FORM => ['clip__file' => new UploadedFile($path, 'photo.png', 'image/png', null, true)]], self::SAME_SITE);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->client->followRedirect();
        self::assertStringContainsString('A video can only be replaced by an MP4 video', (string) $this->client->getResponse()->getContent(), 'The editor is told why.');
        self::assertSame([], $this->getService(CmsMediaLibrary::class)->images(), 'Nothing joined the library.');
        self::assertNull($this->draftProps($page)['clip'] ?? null);
        @unlink($path);
    }

    public function testTheVideoOfARowIsKeptWithItsRow(): void
    {
        $page = $this->pageWith(null);
        $video = Mp4Videos::row($this->getService(CmsMediaLibrary::class), $this->locale());
        $form = $this->dialog($page)->filter('form[data-cms-section-form]')->form();
        $values = $form->getPhpValues();
        $values[self::FORM]['gallery'][0]['video'] = (string) $video->getId();

        self::assertSame(302, $this->send('POST', $form->getUri(), $values));
        self::assertSame($video->getId(), $this->draftProps($page)['gallery'][0]['video']);

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();

        self::assertStringContainsString('<a href="/video-library/'.$video->getId().'.mp4">One</a>', (string) $content?->getDraftHtml(), 'The template reads the address in the rows of a list too.');
    }

    public function testTheTemplateIsGivenTheAddressOfTheFileOnceTheVideoIsChosen(): void
    {
        $page = $this->pageWith(null);
        $video = Mp4Videos::row($this->getService(CmsMediaLibrary::class), $this->locale());
        $this->save($page, (string) $video->getId());

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();

        self::assertStringContainsString('<video preload="none" src="/video-library/'.$video->getId().'.mp4">', (string) $content?->getDraftHtml());
        self::assertSame([(int) $video->getId() => 1], $this->getService(MediaUsageFinder::class)->countsFor([(int) $video->getId()]), 'The sheet of the video says where it plays.');
    }

    /**
     * @return array<string, mixed>
     */
    private function picker(string $query): array
    {
        $this->client->request('GET', '/admin/cms/media/picker'.$query, [], [], self::XHR);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function save(CmsPage $page, string $videoId): ?int
    {
        $form = $this->dialog($page)->filter('form[data-cms-section-form]')->form();
        $values = $form->getPhpValues();
        $values[self::FORM]['clip'] = $videoId;

        self::assertSame(302, $this->send('POST', $form->getUri(), $values));

        $clip = $this->draftProps($page)['clip'] ?? null;

        return null === $clip || 0 === $clip ? null : (int) $clip;
    }

    /**
     * @return array<string, mixed>
     */
    private function draftProps(CmsPage $page): array
    {
        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();

        return Document::fromJson($content?->getDraftDocument())?->section('clips')->props ?? [];
    }

    private function dialog(CmsPage $page): Crawler
    {
        return $this->screen(\sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), Lang::getDefaultLanguage()->getId()))
            ->filter('[data-testid="cms-section-edit-clips"]');
    }

    private function pageWith(?int $videoId): CmsPage
    {
        $page = $this->createPage('Video choisie', published: false);
        $this->client->request('GET', '/admin/cms/pages');
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), new Document([
            new Section('clips', 'Site:ClipCard', ['clip' => $videoId, 'gallery' => [['title' => 'One', 'video' => null]]]),
        ]));

        return $page;
    }

    private function image(string $title): LibraryImage
    {
        $image = (new LibraryImage())->setFileName(bin2hex(random_bytes(6)).'.jpg')->setWidth(800)->setHeight(600)->setDecorative(0);
        $image->setLocale($this->locale())->setTitle($title);
        $image->save();

        $this->getService(CmsMediaLibrary::class)->tag($image);

        return $image;
    }
}
