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

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Thelia\Model\LangQuery;
use TheliaCMS\Media\Admin\CmsMediaType;
use TheliaCMS\Media\Admin\CmsMediaWriter;
use TheliaCMS\Media\Admin\MediaUploadRefusal;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\CeilingImages;
use TheliaCMS\Tests\Support\Mp4Videos;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\TheliaLibrary;

/**
 * An MP4 in the media library: stored by its content, shown as a video, never
 * asked for an alternative text, and replaced by a video only.
 */
final class MediaVideoScreenTest extends AdminScreenTestCase
{
    private const string LIST = '/admin/cms/media';

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

    public function testAnMp4IsStoredAsAVideoWithItsOwnAddress(): void
    {
        $video = $this->store();

        self::assertSame('video/mp4', $video->getMimeType());
        self::assertTrue($video->isVideo());
        self::assertNull($video->getWidth());
        self::assertNull($video->getHeight());
        self::assertSame(\sprintf('/video-library/%d.mp4', $video->getId()), $this->getService(CmsMediaLibrary::class)->publicUrl($video));
    }

    public function testATextRenamedMp4IsRefusedWhateverItsNameAndDeclaredType(): void
    {
        $this->upload([Mp4Videos::textNamedAsVideo('faux.mp4')], json: true);
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame([], $answer['stored']);
        self::assertSame('faux.mp4', $answer['refused'][0]['name']);
        self::assertSame($this->translated(MediaUploadRefusal::Format), $answer['refused'][0]['reason']);
        self::assertSame([], $this->getService(CmsMediaLibrary::class)->images());
    }

    public function testTheJsonAnswerSaysWhichStoredFileIsAVideo(): void
    {
        $this->upload([Mp4Videos::small()], json: true);
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertTrue($answer['stored'][0]['video']);
    }

    public function testAVideoOverTheCeilingIsRefusedWhateverTheLimitOfPhp(): void
    {
        $writer = $this->getService(CmsMediaWriter::class);

        $upload = $writer->add([Mp4Videos::weighing(CmsMediaType::MAX_VIDEO_BYTES + 1, 'lourde.mp4')], $this->locale());

        self::assertSame([], $upload->stored);
        self::assertSame(MediaUploadRefusal::VideoTooHeavy, $upload->refused[0]['refusal']);
    }

    public function testTheListShowsAVideoElementAndNeverAMissingAlternativeText(): void
    {
        $video = $this->store();
        $image = $this->image('Sans description');

        $list = $this->screen(self::LIST);
        $card = $list->filter(\sprintf('[data-testid="cms-media-card-%d"]', $video->getId()));

        self::assertSame(1, $card->filter('video[preload="metadata"][muted][playsinline]')->count());
        self::assertSame(\sprintf('/video-library/%d.mp4', $video->getId()), $card->filter('video')->attr('src'));
        self::assertSame(0, $card->filter('video[controls]')->count(), 'No controls in the grid.');
        self::assertSame(0, $card->filter('img')->count());
        self::assertStringNotContainsString('No alternative text', $card->text());
        self::assertStringNotContainsString('Sans texte alternatif', $card->text());

        $imageCard = $list->filter(\sprintf('[data-testid="cms-media-card-%d"]', $image->getId()));
        self::assertSame(1, $imageCard->filter('img')->count());
        self::assertStringContainsString('alternative text', strtolower($imageCard->text()), 'An image still says nothing is described.');
    }

    public function testTheCountsAndTheFilterOfTheImagesToDescribeLeaveTheVideoOut(): void
    {
        $video = $this->store();
        $image = $this->image('Sans description');
        $library = $this->getService(CmsMediaLibrary::class);

        self::assertSame(['total' => 2, 'undescribed' => 1], $library->counts($this->locale()));
        self::assertSame([(int) $image->getId()], $library->selection($this->locale(), null, \TheliaCMS\Media\MediaFilter::ToDescribe));
        self::assertNotContains((int) $video->getId(), $library->selection($this->locale(), null, \TheliaCMS\Media\MediaFilter::Decorative));
    }

    public function testTheListCountsTheVideosApartFromTheImages(): void
    {
        $this->store();
        $this->image('Sans description');

        $count = $this->flatten(trim($this->screen('/admin/cms/media')->filter('[data-testid="cms-media-count"]')->text()));

        self::assertMatchesRegularExpression('/^\d+ image\(s\) · 1 video\(s\) · \d+ still to describe/', $count, 'A video is not counted as an image.');
        $library = $this->getService(CmsMediaLibrary::class);
        self::assertStringStartsWith(($library->counts($this->locale())['total'] - 1).' image(s)', $count);
    }

    public function testTheDeleteDialogOfAVideoSpeaksOfAVideo(): void
    {
        $video = $this->store();

        $dialog = $this->screen('/admin/cms/media/'.$video->getId().'?lang=fr_FR')->filter('[data-cms-media-delete-dialog]');

        self::assertSame('Supprimer définitivement cette vidéo ?', trim($dialog->filter('#cms-media-delete-title')->text()));
        self::assertSame('Supprimer cette vidéo', trim($dialog->filter('[data-cms-media-delete-confirm]')->text()));
    }

    public function testTheDescribeQueueDoesNotOfferAVideo(): void
    {
        $video = $this->store();
        $image = $this->image('Sans description');

        $queue = $this->screen(\sprintf('/admin/cms/media/describe?images=%d,%d', $video->getId(), $image->getId()));

        self::assertSame('Image 1 of 1 to describe', $this->flatten(trim($queue->filter('[data-testid="cms-media-describe-position"]')->text())), 'One image to describe: the video is not counted.');
        self::assertSame(1, $queue->filter('form[data-testid="cms-media-describe-form"]')->count());
        self::assertStringContainsString((string) $image->getId(), (string) $queue->filter('form[data-testid="cms-media-describe-form"]')->attr('action'));
    }

    public function testSeveralVideosGoBackToTheListNotToAnEmptyQueue(): void
    {
        $this->upload([Mp4Videos::small('un.mp4'), Mp4Videos::small('deux.mp4')]);

        $location = (string) $this->client->getResponse()->headers->get('Location');

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('/describe', $location);
        self::assertCount(2, $this->getService(CmsMediaLibrary::class)->images());
    }

    public function testAVideoAndAnImageTogetherOnlyQueueTheImage(): void
    {
        $this->upload([Mp4Videos::small('un.mp4'), $this->png('une.png'), $this->png('deux.png')]);

        $location = (string) $this->client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);

        self::assertStringContainsString('/describe', $location);
        self::assertCount(2, explode(',', (string) $query['images']));
    }

    public function testTheSheetOfAVideoHasNoAlternativeTextNorDecorativeNorCropAndPlaysTheFile(): void
    {
        $video = $this->store();
        $screen = $this->screen(\sprintf('/admin/cms/media/%d', $video->getId()));

        self::assertSame(0, $screen->filter('input[name="cms_media[alt]"]')->count());
        self::assertSame(0, $screen->filter('input[name="cms_media[decorative]"]')->count());
        self::assertSame(0, $screen->filter('[data-testid="cms-media-descriptions"]')->count());
        self::assertSame(1, $screen->filter('.card video[controls][preload="metadata"]')->count());
        self::assertSame(0, $screen->filter('.badge:contains("alternative")')->count());
        self::assertSame(1, $screen->filter('input[name="cms_media[caption]"], textarea[name="cms_media[caption]"]')->count(), 'The caption stays.');
        self::assertSame(1, $screen->filter('input[name="cms_media[title]"]')->count(), 'The title stays.');
    }

    public function testTheSheetOfAVideoIsSavedWithoutAlternativeText(): void
    {
        $video = $this->store();
        $url = \sprintf('/admin/cms/media/%d', $video->getId());
        $values = $this->sheetValues($url);
        $values['cms_media'] = ['title' => 'Visite', 'caption' => 'La ferme', 'tags' => 'Vidéos'] + $values['cms_media'];

        $this->client->request('POST', $url, $values, [], self::SAME_SITE);

        self::assertSame(302, $this->client->getResponse()->getStatusCode(), 'Saved, not shown again with an error.');
        $reloaded = \TheliaLibrary\Model\LibraryImageQuery::create()->findPk($video->getId());
        self::assertNotNull($reloaded);
        self::assertSame('Visite', $reloaded->setLocale($this->locale())->getTitle());
        self::assertSame('La ferme', $reloaded->getCaption());
        self::assertSame(0, $reloaded->getDecorative());
        self::assertSame(['Vidéos'], $this->getService(CmsMediaLibrary::class)->tagsOf($reloaded));
    }

    public function testAnImageIsNotReplacedByAnMp4(): void
    {
        $image = $this->image('Photo');
        $url = \sprintf('/admin/cms/media/%d', $image->getId());
        $token = (string) $this->screen($url)->filter('form:not([data-cms-media-delete]) input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url, ['_token' => $token, 'cms_media' => ['alt' => 'Une photo']], ['cms_media' => ['file' => Mp4Videos::small()]], self::SAME_SITE);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'The sheet is shown again, not saved.');
        self::assertStringContainsString('Only JPEG, PNG and WebP images can be uploaded.', html_entity_decode((string) $this->client->getResponse()->getContent()));
        self::assertNotSame('video/mp4', \TheliaLibrary\Model\LibraryImageQuery::create()->findPk($image->getId())?->getMimeType(), 'The image is still an image.');
    }

    public function testAVideoIsNotReplacedByAnImageButByAnotherVideo(): void
    {
        $video = $this->store();
        $url = \sprintf('/admin/cms/media/%d', $video->getId());
        $values = $this->sheetValues($url);

        $this->client->request('POST', $url, $values, ['cms_media' => ['file' => $this->png('image.png')]], self::SAME_SITE);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('A video can only be replaced by an MP4 video.', html_entity_decode((string) $this->client->getResponse()->getContent()));
        self::assertTrue(\TheliaLibrary\Model\LibraryImageQuery::create()->findPk($video->getId())?->isVideo());

        $this->client->request('POST', $url, $values, ['cms_media' => ['file' => Mp4Videos::small('autre.mp4')]], self::SAME_SITE);

        self::assertSame(302, $this->client->getResponse()->getStatusCode(), 'A video replaces a video.');
        $id = (int) $video->getId();
        $video->reload();
        self::assertTrue($video->isVideo());
        self::assertSame($id, (int) $video->getId(), 'The row, and so the address, is kept.');
    }

    public function testAnImageAboveTenMegabytesIsStillRefusedWhileAVideoOfThirtyIsTaken(): void
    {
        $writer = $this->getService(CmsMediaWriter::class);

        $upload = $writer->add([CeilingImages::weighing(30 * 1024 * 1024, 'grosse.png'), Mp4Videos::weighing(30 * 1024 * 1024, 'moyenne.mp4')], $this->locale());

        self::assertSame(MediaUploadRefusal::TooHeavy, $upload->refused[0]['refusal']);
        self::assertSame('grosse.png', $upload->refused[0]['name']);
        self::assertCount(1, $upload->stored);
        self::assertTrue($upload->stored[0]->isVideo());
    }

    public function testTheListAnnouncesTwoCeilingsEachBoundedByTheLimitOfPhp(): void
    {
        $form = $this->screen(self::LIST)->filter('form[data-cms-media-upload]');
        $php = (int) UploadedFile::getMaxFilesize();

        self::assertSame(min(CmsMediaType::MAX_IMAGE_BYTES, $php), (int) $form->attr('data-max-size'));
        self::assertSame(min(CmsMediaType::MAX_VIDEO_BYTES, $php), (int) $form->attr('data-max-video-size'));
        self::assertSame('video/mp4', $form->attr('data-video-accepted'));
        self::assertStringContainsString('video/mp4', (string) $form->attr('data-accepted'));
        self::assertStringContainsString('image/png', (string) $form->attr('data-accepted'));

        $messages = json_decode((string) $form->attr('data-messages'), true, flags: \JSON_THROW_ON_ERROR);
        $refusal = CmsMediaType::MAX_VIDEO_BYTES <= $php ? MediaUploadRefusal::VideoTooHeavy : MediaUploadRefusal::TooLarge;
        self::assertSame($this->translated($refusal), $messages['tooLargeVideo']);
    }

    public function testTheBulkDecorativeAnswerLeavesAVideoAsItIs(): void
    {
        $video = $this->store();
        $writer = $this->getService(CmsMediaWriter::class);

        $writer->setDecorative($video, true);

        self::assertSame(0, \TheliaLibrary\Model\LibraryImageQuery::create()->findPk($video->getId())?->getDecorative());
    }

    /**
     * The fields of the sheet as the screen sends them, token included.
     *
     * @return array<string, mixed>
     */
    private function sheetValues(string $url): array
    {
        $form = $this->screen($url)->filter('form[name="cms_media"]')->form();
        $values = $form->getPhpValues();
        unset($values['cms_media']['file']);

        return $values;
    }

    private function store(): LibraryImage
    {
        $upload = $this->getService(CmsMediaWriter::class)->add([Mp4Videos::small()], $this->locale());
        self::assertCount(1, $upload->stored, 'The MP4 is stored.');

        return $upload->stored[0];
    }

    private function translated(MediaUploadRefusal $refusal): string
    {
        return $this->getService(\Symfony\Contracts\Translation\TranslatorInterface::class)->trans($refusal->message(), [], \TheliaCMS\TheliaCMS::DOMAIN_NAME);
    }

    private function flatten(string $text): string
    {
        return (string) preg_replace('/\s+/', ' ', $text);
    }

    private function png(string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cms-upload-');
        imagepng(imagecreatetruecolor(3, 2), $path);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function image(string $title): LibraryImage
    {
        $image = (new LibraryImage())->setFileName(bin2hex(random_bytes(6)).'.jpg')->setWidth(800)->setHeight(600)->setDecorative(0);
        $image->setLocale($this->locale())->setTitle($title);
        $image->save();

        $this->getService(CmsMediaLibrary::class)->tag($image);

        return $image;
    }

    /**
     * @param list<UploadedFile> $files
     */
    private function upload(array $files, bool $json = false): void
    {
        $token = $this->tokenOfTheForm($this->screen(self::LIST), self::LIST);
        $server = $json ? [...self::XHR, 'HTTP_ACCEPT' => 'application/json'] : self::SAME_SITE;
        $language = (int) LangQuery::create()->findOneByLocale($this->locale())?->getId();

        $this->client->request('POST', self::LIST.'?edit_language_id='.$language, ['_token' => $token], ['files' => $files], $server);
    }
}
