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
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Media\Admin\CmsMediaType;
use TheliaCMS\Media\Admin\MediaUploadRefusal;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\CeilingImages;
use TheliaCMS\TheliaCMS;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\TheliaLibrary;

/**
 * Sending images from the list: each file on its own, the refused ones said
 * with why, the stored ones described next.
 */
final class MediaUploadScreenTest extends AdminScreenTestCase
{
    private const string LIST = '/admin/cms/media';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->getService(CmsMediaLibrary::class)->images() as $image) {
            $this->files[] = TheliaLibrary::getImageDirectory().$image->getFileName();
        }

        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testSeveralFilesAreStoredAndTheRefusedOnesSaidWithoutCostingTheOthers(): void
    {
        $this->upload([$this->png('fauteuil.png', 3), $this->gif('anime.gif'), $this->notAnImage('faux.jpg'), $this->truncatedJpeg('tronque.jpg'), $this->png('chaise.png', 4)]);

        $response = $this->client->getResponse();
        self::assertSame(302, $response->getStatusCode());

        $stored = $this->getService(CmsMediaLibrary::class)->images();
        self::assertSame(['chaise.png', 'fauteuil.png'], $this->titles($stored), 'The two images are stored, the GIF and the false JPEG are not.');

        $location = (string) $response->headers->get('Location');
        self::assertStringContainsString('/admin/cms/media/describe', $location, 'Several images go to the describe queue.');
        self::assertStringContainsString('images=', $location);

        $queue = $this->screen($location)->html();
        self::assertStringContainsString('anime.gif', $queue, 'The screen says which files were not added.');
        self::assertStringContainsString('faux.jpg', $queue);
        self::assertStringContainsString('tronque.jpg', $queue, 'A file the library itself refuses is said too.');
    }

    public function testAnImageAboveTheCeilingIsRefusedWithItsReasonAndTheOthersAreStored(): void
    {
        if (UploadedFile::getMaxFilesize() <= CmsMediaType::MAX_IMAGE_BYTES) {
            self::markTestSkipped('upload_max_filesize is below the ceiling of an image: PHP refuses the heavy file first.');
        }

        $this->upload([CeilingImages::small('petite.png'), CeilingImages::declaringTooManyPixels('immense.png'), CeilingImages::heavy('lourde.png')], json: true);
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        $translator = $this->getService(TranslatorInterface::class);

        self::assertSame(['petite.png'], $this->titles($this->getService(CmsMediaLibrary::class)->images()), 'Only the small image is stored.');
        self::assertCount(1, $answer['stored']);
        self::assertSame(
            ['immense.png' => $translator->trans(MediaUploadRefusal::TooManyPixels->message(), [], TheliaCMS::DOMAIN_NAME), 'lourde.png' => $translator->trans(MediaUploadRefusal::TooHeavy->message(), [], TheliaCMS::DOMAIN_NAME)],
            array_column($answer['refused'], 'reason', 'name'),
        );
    }

    public function testTheListAnnouncesTheSmallerOfTheCeilingAndTheServerLimit(): void
    {
        $announced = (int) $this->screen(self::LIST)->filter('form[data-cms-media-upload]')->attr('data-max-size');

        self::assertSame(min(CmsMediaType::MAX_IMAGE_BYTES, (int) UploadedFile::getMaxFilesize()), $announced);
    }

    public function testAFileTheScreenRefusesBeforeSendingIsToldTheLimitThatStopsIt(): void
    {
        $messages = json_decode((string) $this->screen(self::LIST)->filter('form[data-cms-media-upload]')->attr('data-messages'), true, flags: \JSON_THROW_ON_ERROR);
        $refusal = CmsMediaType::MAX_IMAGE_BYTES <= (int) UploadedFile::getMaxFilesize() ? MediaUploadRefusal::TooHeavy : MediaUploadRefusal::TooLarge;

        self::assertSame($this->getService(TranslatorInterface::class)->trans($refusal->message(), [], TheliaCMS::DOMAIN_NAME), $messages['tooLarge']);
    }

    /**
     * The script picks the form of a message by the count it knows once the
     * files are sent: it receives both forms, and what each button leaves.
     */
    public function testTheButtonsAfterARefusalReceiveBothFormsOfTheirMessage(): void
    {
        $messages = json_decode((string) $this->screen(self::LIST)->filter('form[data-cms-media-upload]')->attr('data-messages'), true, flags: \JSON_THROW_ON_ERROR);
        $translator = $this->getService(TranslatorInterface::class);

        foreach (['prepare' => 'Prepare the SVG file|Prepare the %count% SVG files', 'thenDescribe' => ', then describe the image|, then describe the %count% images', 'dropSvg' => 'Do not add this SVG file|Do not add these SVG files', 'describe' => 'Describe the image added|Describe the %count% images added'] as $key => $english) {
            self::assertSame($translator->trans($english, [], TheliaCMS::DOMAIN_NAME), $messages[$key], $key);
            self::assertCount(2, explode('|', $messages[$key]), $key.': one form for one item, one for more.');
        }

        self::assertSame($translator->trans('Describe later', [], TheliaCMS::DOMAIN_NAME), $messages['describeLater']);
        self::assertArrayNotHasKey('stay', $messages, '"Stay on the list" said nothing of what it left.');
    }

    public function testTheQueueOfASendingHoldsTheImagesOfThatSendingOnly(): void
    {
        $older = (new LibraryImage())->setFileName('plus-ancienne.jpg');
        $older->setLocale($this->locale())->setTitle('Plus ancienne');
        $older->save();
        $this->getService(CmsMediaLibrary::class)->tag($older);

        $this->upload([$this->png('neuve-1.png', 5), $this->png('neuve-2.png', 6)]);
        $queue = $this->screen((string) $this->client->getResponse()->headers->get('Location'));

        self::assertStringContainsString('2', $queue->filter('[data-testid="cms-media-describe-position"]')->text(), 'Two images to describe: the older one is not of this sending.');
        self::assertStringNotContainsString(\sprintf('/admin/cms/media/%d?', $older->getId()), $queue->html());
    }

    /**
     * The images of a sending are the queue, not the list: once they are
     * described, or straight from the queue, the editor is back on the whole
     * library.
     */
    public function testLeavingTheQueueOfASendingLeadsBackToTheWholeList(): void
    {
        $older = (new LibraryImage())->setFileName('deja-la.jpg');
        $older->setLocale($this->locale())->setTitle('Déjà là');
        $older->save();
        $this->getService(CmsMediaLibrary::class)->tag($older);

        $this->upload([$this->png('neuve-1.png', 9), $this->png('neuve-2.png', 10)]);
        $queue = $this->screen((string) $this->client->getResponse()->headers->get('Location'));

        self::assertStringNotContainsString('images=', (string) $queue->filter('[data-testid="cms-media-back"]')->attr('href'));

        for ($answered = 0; $answered < 2; ++$answered) {
            $form = $queue->filter('[data-testid="cms-media-describe-form"]');
            $values = $form->form()->getPhpValues();
            $values['media_description']['decorative'] = '1';
            self::assertSame(302, $this->send('POST', (string) $form->attr('action'), $values));

            $this->client->request('GET', (string) $this->client->getResponse()->headers->get('Location'));
            $queue = $this->client->getCrawler();
        }

        $list = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/admin/cms/media?', $list);
        self::assertStringNotContainsString('images=', $list, 'Done, the queue leads back to the whole list.');
        self::assertCount(3, $this->screen($list.'&images='.$older->getId())->filter('[data-testid^="cms-media-card-"]'), 'The list is never narrowed to a sending.');
    }

    public function testTheScriptIsAnsweredInJsonFileByFile(): void
    {
        $this->upload([$this->png('vase.png', 7)], json: true);
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('vase.png', $answer['stored'][0]['title']);
        self::assertSame([], $answer['refused']);

        $this->upload([$this->gif('anime.gif')], json: true);
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame([], $answer['stored']);
        self::assertSame('anime.gif', $answer['refused'][0]['name']);
        self::assertNotSame('', $answer['refused'][0]['reason'], 'The script says why.');
    }

    public function testASingleImageOpensItsSheet(): void
    {
        $this->upload([$this->png('seule.png', 8)]);

        $stored = $this->getService(CmsMediaLibrary::class)->images();
        self::assertCount(1, $stored);
        self::assertStringEndsWith(\sprintf('/admin/cms/media/%d?edit_language_id=%d', $stored[0]->getId(), $this->languageId()), (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheListIsAPlaceToDropFilesOn(): void
    {
        $form = $this->screen(self::LIST)->filter('form[data-cms-media-upload]');

        self::assertSame(1, $form->count());
        self::assertSame('image/jpeg,image/png,image/webp,image/svg+xml,.svg,video/mp4', $form->attr('data-accepted'));
        self::assertGreaterThan(0, (int) $form->attr('data-max-size'));
        self::assertStringContainsString('/admin/cms/media/describe', (string) $form->attr('data-describe-url'));
    }

    /**
     * @param list<UploadedFile> $files
     */
    private function upload(array $files, bool $json = false): void
    {
        $token = $this->tokenOfTheForm($this->screen(self::LIST), self::LIST);
        $server = $json ? [...self::XHR, 'HTTP_ACCEPT' => 'application/json'] : self::SAME_SITE;

        $this->client->request('POST', self::LIST.'?edit_language_id='.$this->languageId(), ['_token' => $token], ['files' => $files], $server);
    }

    private function languageId(): int
    {
        return (int) \Thelia\Model\LangQuery::create()->findOneByLocale($this->locale())?->getId();
    }

    /**
     * @param list<LibraryImage> $images
     *
     * @return list<string>
     */
    private function titles(array $images): array
    {
        $titles = array_map(fn (LibraryImage $image): string => (string) $image->setLocale($this->locale())->getTitle(), $images);
        sort($titles);

        return $titles;
    }

    private function png(string $name, int $width): UploadedFile
    {
        $path = $this->temporary();
        imagepng(imagecreatetruecolor($width, 2), $path);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function gif(string $name): UploadedFile
    {
        $path = $this->temporary();
        imagegif(imagecreatetruecolor(2, 2), $path);

        return new UploadedFile($path, $name, 'image/gif', null, true);
    }

    private function notAnImage(string $name): UploadedFile
    {
        $path = $this->temporary();
        file_put_contents($path, 'not an image');

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    /**
     * Begins as a JPEG, so its type reads as one, and ends there: the library
     * refuses it when it decodes it.
     */
    private function truncatedJpeg(string $name): UploadedFile
    {
        $path = $this->temporary();
        file_put_contents($path, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00");

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    private function temporary(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cms-upload-');
        $this->files[] = $path;

        return $path;
    }
}
