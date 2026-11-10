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

namespace TheliaCMS\Generation\Component;

use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Media\MediaKind;
use TheliaLibrary\Model\LibraryImage;

/**
 * The media of the library the sample of a component may show: the most
 * recent pictures and videos the CMS offers its editors, few of them, so the
 * message stays short. Nothing when the library is empty.
 */
final readonly class SampleMedia
{
    public const int MAX_IMAGES = 12;

    public const int MAX_VIDEOS = 3;

    public function __construct(
        private CmsMediaLibrary $library,
    ) {
    }

    /**
     * Pictures first, then videos, each most recent first.
     *
     * @return list<SampleMediaItem>
     */
    public function offered(string $locale): array
    {
        $items = [];

        foreach ([[MediaKind::Image, self::MAX_IMAGES], [MediaKind::Video, self::MAX_VIDEOS]] as [$kind, $limit]) {
            foreach ($this->library->images(limit: $limit, kind: $kind) as $media) {
                $items[] = new SampleMediaItem(
                    (int) $media->getId(),
                    MediaKind::Video === $kind,
                    $this->textOf($media, $locale),
                    $media->getWidth(),
                    $media->getHeight(),
                );
            }
        }

        return $items;
    }

    /**
     * The alternative text, else the title, in the language of the sample and
     * then in the default language of the shop.
     */
    private function textOf(LibraryImage $media, string $locale): string
    {
        $video = MediaKind::of($media) === MediaKind::Video;

        foreach (array_unique([$locale, $this->library->locale()]) as $language) {
            $media->setLocale($language);
            $text = trim(($video ? '' : (string) $media->getAlt()) ?: (string) $media->getTitle());

            if ('' !== $text) {
                return $text;
            }
        }

        return '';
    }
}
