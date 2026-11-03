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

namespace TheliaCMS\Tests\Support;

use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validation;
use TheliaCMS\Media\Admin\CmsMediaType;

/**
 * Images that are small on disk and the way a hostile one is: a header that
 * declares a huge picture, a file stretched past the weight ceiling. Nothing
 * large is written: the first is a few bytes, the second a sparse file.
 */
final class CeilingImages
{
    /**
     * What the form says about the file of `$field` once the file is sent as a
     * request sends it, an empty string when it takes it.
     *
     * @param class-string         $formType
     * @param array<string, mixed> $options
     * @param array<string, mixed> $values   the other fields sent with the file
     */
    public static function errorOf(string $formType, string $field, UploadedFile $file, array $options = [], array $values = []): string
    {
        $form = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addExtension(new HttpFoundationExtension())
            ->getFormFactory()
            ->createNamed('', $formType, null, $options);
        $form->handleRequest(Request::create('/', 'POST', $values, [], [$field => $file]));
        $errors = $form->get($field)->getErrors();

        return 0 === \count($errors) ? '' : $errors[0]->getMessage();
    }

    /** A 2 x 2 PNG: under every ceiling. */
    public static function small(string $name = 'small.png'): UploadedFile
    {
        $path = self::temporary();
        imagepng(imagecreatetruecolor(2, 2), $path);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    /** A PNG whose header declares more pixels than the ceiling allows. */
    public static function declaringTooManyPixels(string $name = 'huge.png'): UploadedFile
    {
        $side = (int) ceil(sqrt(CmsMediaType::MAX_IMAGE_PIXELS)) + 1;
        $header = pack('NN', $side, $side)."\x08\x02\x00\x00\x00";
        $path = self::temporary();
        file_put_contents($path, "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$header.pack('N', crc32('IHDR'.$header)));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    /** A small PNG stretched past the weight ceiling, by one byte. */
    public static function heavy(string $name = 'heavy.png'): UploadedFile
    {
        return self::weighing(CmsMediaType::MAX_IMAGE_BYTES + 1, $name);
    }

    /** A small PNG stretched to the given weight (sparse, nothing written). */
    public static function weighing(int $bytes, string $name = 'weighing.png'): UploadedFile
    {
        $path = self::temporary();
        imagepng(imagecreatetruecolor(2, 2), $path);
        $handle = fopen($path, 'a');
        ftruncate($handle, $bytes);
        fclose($handle);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private static function temporary(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cms-ceiling-');
        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        return $path;
    }
}
