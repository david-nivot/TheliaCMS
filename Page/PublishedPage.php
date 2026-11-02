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
 * What the front office is allowed to see of a CMS page: published columns
 * only, never a draft. Also the payload handed to the `cmspage.*` theme hooks,
 * so third-party modules get a stable contract instead of a Propel object.
 */
final readonly class PublishedPage
{
    /**
     * @param string                                    $pageType     the code of the type of the page, always a valid one
     * @param string|null                               $tag          the name of the tag of the page, when it has one
     * @param string|null                               $tagColor     the `#rrggbb` code of the background of that tag, when it has a color (PageTagColor)
     * @param array<string, int|float|string|bool|null> $fields       the value of every field its type defines, by code, in this language
     * @param string|null                               $tagTextColor the `#rrggbb` code of the text of that tag, the one chosen or black or white by contrast, when it has a color
     * @param string|null                               $provenance   the JSON of the mark of the core when the document was generated (AI Act, article 50(2)); the template prints it, the sanitizer would remove it from the HTML
     */
    public function __construct(
        public int $id,
        public string $locale,
        public string $title,
        public string $pageType,
        public string $html,
        public ?string $chapo = null,
        public ?string $description = null,
        public ?PageImage $image = null,
        public ?string $metaTitle = null,
        public ?string $metaDescription = null,
        public bool $noindex = false,
        public bool $nofollow = false,
        public ?\DateTimeInterface $publishedAt = null,
        public ?string $tag = null,
        public ?string $tagColor = null,
        public array $fields = [],
        public ?string $provenance = null,
        public ?string $tagTextColor = null,
    ) {
    }

    /**
     * The same page with its dynamic blocks resolved. Nothing else may change:
     * this is the object the theme hooks receive.
     */
    public function withHtml(string $html): self
    {
        return new self(
            id: $this->id,
            locale: $this->locale,
            title: $this->title,
            pageType: $this->pageType,
            html: $html,
            chapo: $this->chapo,
            description: $this->description,
            image: $this->image,
            metaTitle: $this->metaTitle,
            metaDescription: $this->metaDescription,
            noindex: $this->noindex,
            nofollow: $this->nofollow,
            publishedAt: $this->publishedAt,
            tag: $this->tag,
            tagColor: $this->tagColor,
            fields: $this->fields,
            provenance: $this->provenance,
            tagTextColor: $this->tagTextColor,
        );
    }
}
