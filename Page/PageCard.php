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

namespace TheliaCMS\Page;

/**
 * What a list of pages shows about one of them: its name, its address, its
 * summary and its image, never its body. PublishedPage is one page being
 * served, body included; a card is one of many.
 */
final readonly class PageCard
{
    /**
     * @param string|null                               $tagColor     the `#rrggbb` code of the background of the tag, when it has a color (PageTagColor)
     * @param string|null                               $tagTextColor the `#rrggbb` code of the text of the tag, the one chosen or black or white by contrast, when it has a color
     * @param array<string, int|float|string|bool|null> $fields       the value of every field its type defines, by code, in this language
     */
    public function __construct(
        public int $id,
        public string $locale,
        public string $title,
        public string $url,
        public string $pageType,
        public ?string $chapo = null,
        public ?string $description = null,
        public ?PageImage $image = null,
        public ?string $tag = null,
        public ?string $tagColor = null,
        public ?\DateTimeInterface $publishedAt = null,
        public array $fields = [],
        public ?string $tagTextColor = null,
    ) {
    }
}
