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

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Media\Admin\CmsMediaWriter;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\TheliaCMS;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\TheliaLibrary;

/**
 * An icon is replaced by an SVG only, which is judged on the preparation
 * screen; a raster image is refused by the server, an ordinary image still takes one.
 */
final class IconReplacementTest extends AdminScreenTestCase
{
    private const string CLEAN = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>';

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

    public function testAnIconIsNotReplacedByARasterImage(): void
    {
        $icon = $this->getService(CmsMediaWriter::class)->addPreparedSvg(self::CLEAN, 'raster-refused.svg', $this->locale(), 'raster-refused');
        $before = md5_file(TheliaLibrary::getImageDirectory().$icon->getFileName());

        $this->sendPng($icon);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'The sheet is shown again, not saved.');
        self::assertStringContainsString($this->said('An icon can only be replaced by an SVG file.'), html_entity_decode((string) $this->client->getResponse()->getContent()));
        $icon->reload();
        self::assertStringEndsWith('.svg', $icon->getFileName());
        self::assertSame($before, md5_file(TheliaLibrary::getImageDirectory().$icon->getFileName()), 'The file is unchanged.');
    }

    public function testAnOrdinaryImageStillTakesARasterImage(): void
    {
        $image = $this->getService(CmsMediaWriter::class)->add([$this->png('first.png')], $this->locale())->stored[0];

        $this->sendPng($image);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
    }

    private function sendPng(LibraryImage $image): void
    {
        $sheet = \sprintf('/admin/cms/media/%d', $image->getId());
        $form = $this->screen($sheet)->filter('form:not([data-cms-media-delete])')->reduce(
            static fn (Crawler $candidate): bool => $candidate->filter('[name="cms_media[title]"]')->count() > 0,
        )->first()->form();
        $values = $form->getPhpValues();
        // An ordinary image asks an alternative text; an icon asks none.
        if (\array_key_exists('alt', $values['cms_media'])) {
            $values['cms_media']['alt'] = 'A drawing';
        }

        $this->client->request('POST', $sheet, $values, ['cms_media' => ['file' => $this->png('second.png')]], self::SAME_SITE);
    }

    private function said(string $english): string
    {
        return $this->getService(TranslatorInterface::class)->trans($english, [], TheliaCMS::DOMAIN_NAME, $this->locale());
    }

    private function png(string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cms-icon-');
        $this->files[] = $path;
        imagepng(imagecreatetruecolor(3, 2), $path);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
