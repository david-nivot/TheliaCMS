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

namespace TheliaCMS\Media\Svg;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Tells an SVG from an image of another format among the files sent.
 *
 * The type read from the content is `image/svg+xml` only when the root element
 * opens the file: after a comment or a stylesheet instruction it reads as
 * plain text or XML, so those count too when the name says `.svg`.
 */
final readonly class SvgUpload
{
    private const array CANDIDATE_MIME_TYPES = ['text/plain', 'text/xml', 'application/xml'];

    public static function isSvg(UploadedFile $file): bool
    {
        $mimeType = $file->getMimeType();

        if ('image/svg+xml' === $mimeType) {
            return true;
        }

        return \in_array($mimeType, self::CANDIDATE_MIME_TYPES, true)
            && 'svg' === strtolower(pathinfo($file->getClientOriginalName(), \PATHINFO_EXTENSION));
    }
}
