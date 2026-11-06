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

namespace TheliaCMS\ImportExport;

/**
 * The images a piece of published content points at.
 *
 * An export carries no image file. What it carries is the name of each file,
 * and the id it had on the site that wrote it — the two together are enough for
 * the importing site to point the content at its own copy of the same picture,
 * uploaded beforehand through the media library.
 *
 * Content refers to an image through the URL of the image route,
 * `/image-library/{id}/full/{size}/0/default.{format}`, in `src`, in `srcset`
 * and in the CSS of a page. A video is served from a route of its own,
 * `/video-library/{id}.mp4`, in the `src` of a `<video>` or in a `data-`
 * attribute of the theme. Rewriting is therefore done on the id inside those
 * paths, and on nothing else: a bare number in the text of a page is not a
 * media reference.
 */
final readonly class MediaReferences
{
    private const string PATTERN = '#/image-library/(\d+)/#';

    private const string VIDEO_PATTERN = '#/video-library/(\d+)\.mp4#';

    /**
     * Every library id the given fragments point at.
     *
     * @param list<string|null> $fragments
     *
     * @return list<int>
     */
    public function collect(array $fragments): array
    {
        $ids = [];

        foreach ($fragments as $fragment) {
            if (null === $fragment || '' === $fragment) {
                continue;
            }

            foreach ([self::PATTERN, self::VIDEO_PATTERN] as $pattern) {
                if (preg_match_all($pattern, $fragment, $matches)) {
                    foreach ($matches[1] as $id) {
                        $ids[(int) $id] = true;
                    }
                }
            }
        }

        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * Points the fragment at the ids of the importing site.
     *
     * An id absent from the map is left as it is: the content then points at
     * whatever that id happens to be here, which is visible in the page, rather
     * than at nothing at all, which is a broken image nobody notices.
     *
     * @param array<int, int> $map id in the file, id here
     */
    public function remap(?string $fragment, array $map): ?string
    {
        if (null === $fragment || '' === $fragment || [] === $map) {
            return $fragment;
        }

        $remapped = preg_replace_callback(
            self::PATTERN,
            static function (array $matches) use ($map): string {
                $id = (int) $matches[1];

                return '/image-library/'.($map[$id] ?? $id).'/';
            },
            $fragment,
        ) ?? $fragment;

        return preg_replace_callback(
            self::VIDEO_PATTERN,
            static function (array $matches) use ($map): string {
                $id = (int) $matches[1];

                return '/video-library/'.($map[$id] ?? $id).'.mp4';
            },
            $remapped,
        ) ?? $remapped;
    }
}
