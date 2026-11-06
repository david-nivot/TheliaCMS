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

namespace TheliaCMS\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validation;
use TheliaCMS\Media\Admin\CmsMediaType;
use TheliaCMS\Tests\Support\CeilingImages;
use TheliaCMS\Tests\Support\Mp4Videos;

/**
 * Replacing an image goes through this form: the ceiling is held on its file
 * field, as on the image of a page.
 */
final class CmsMediaTypeTest extends TestCase
{
    public function testAnImageUnderTheCeilingIsAccepted(): void
    {
        self::assertSame('', $this->errorOf(CeilingImages::small()));
    }

    public function testAnImageDeclaringTooManyPixelsIsRefused(): void
    {
        self::assertStringContainsString('too many pixels', $this->errorOf(CeilingImages::declaringTooManyPixels()));
    }

    public function testAnImageHeavierThanTheCeilingIsRefused(): void
    {
        self::assertStringContainsString('too heavy', $this->errorOf(CeilingImages::heavy()));
    }

    public function testTheCeilingIsTenMegabytesAsTheScreenCountsThem(): void
    {
        self::assertSame(10_485_760, CmsMediaType::MAX_IMAGE_BYTES);
        self::assertSame('', $this->errorOf(CeilingImages::weighing(10_000_001)), 'A file over ten million bytes is under ten MB.');
        self::assertSame('', $this->errorOf(CeilingImages::weighing(CmsMediaType::MAX_IMAGE_BYTES)), 'The ceiling itself is accepted.');
        self::assertStringContainsString('(12 MiB). It may weigh 10 MiB', $this->errorOf(CeilingImages::weighing(12 * 1024 * 1024)), 'The validator counts in MiB, like the ceiling.');
    }

    public function testTheCeilingOfAVideoIsFiftyMegabytesAndTheImageOneIsUntouched(): void
    {
        self::assertSame(52_428_800, CmsMediaType::MAX_VIDEO_BYTES);
        self::assertSame(10_485_760, CmsMediaType::MAX_IMAGE_BYTES);
        self::assertSame('', $this->videoErrorOf(Mp4Videos::weighing(CmsMediaType::MAX_VIDEO_BYTES)), 'The ceiling itself is accepted.');
        self::assertStringContainsString('too heavy', $this->videoErrorOf(Mp4Videos::weighing(CmsMediaType::MAX_VIDEO_BYTES + 1)), 'One byte above is refused.');
        self::assertStringContainsString('(51 MiB). It may weigh 50 MiB', $this->videoErrorOf(Mp4Videos::weighing(51 * 1024 * 1024)), 'The validator counts in MiB, like the ceiling.');
        self::assertSame('', $this->videoErrorOf(Mp4Videos::weighing(30 * 1024 * 1024)), 'A video of 30 MB goes where an image of 30 MB does not.');
        self::assertStringContainsString('too heavy', $this->errorOf(CeilingImages::weighing(30 * 1024 * 1024)));
    }

    public function testAVideoIsOnlyReplacedByAnMp4(): void
    {
        self::assertStringContainsString('MP4', $this->videoErrorOf(CeilingImages::small()), 'An image does not replace a video.');
        self::assertStringContainsString('MP4', $this->videoErrorOf(Mp4Videos::textNamedAsVideo()), 'The type is read in the content, not in the name.');
    }

    public function testAnImageIsNotReplacedByAVideo(): void
    {
        self::assertNotSame('', $this->errorOf(Mp4Videos::small()));
    }

    public function testTheFormOfAVideoHasNeitherAlternativeTextNorDecorativeFlag(): void
    {
        $image = $this->form(false);
        $video = $this->form(true);

        self::assertTrue($image->has('alt') && $image->has('decorative'));
        self::assertFalse($video->has('alt') || $video->has('decorative'));
        self::assertTrue($video->has('caption') && $video->has('title') && $video->has('tags'));
    }

    public function testAVideoIsSavedWithoutAlternativeTextAndAnImageIsNot(): void
    {
        $video = $this->form(true);
        $video->submit(['title' => 'Clip']);
        self::assertTrue($video->isValid());

        $image = $this->form(false);
        $image->submit(['title' => 'Photo']);
        self::assertFalse($image->isValid(), 'The rule of the alternative text still holds for an image.');
    }

    private function form(bool $video): \Symfony\Component\Form\FormInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addExtension(new HttpFoundationExtension())
            ->getFormFactory()
            ->createNamed('', CmsMediaType::class, null, ['video' => $video]);
    }

    private function videoErrorOf(UploadedFile $file): string
    {
        return CeilingImages::errorOf(CmsMediaType::class, 'file', $file, ['video' => true]);
    }

    private function errorOf(UploadedFile $file): string
    {
        return CeilingImages::errorOf(CmsMediaType::class, 'file', $file);
    }
}
