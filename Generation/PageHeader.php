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

namespace TheliaCMS\Generation;

use TheliaCMS\Content\DocumentSchema;
use TheliaCMS\Model\CmsPageI18n;

/**
 * The header of a page in one language: what a list, a card or a theme shows
 * of it before its content. Read from the page in the language a translation
 * is written from, and read back from the answer for the language it is
 * written in.
 *
 * The summary and the description are HTML, like the editor writes them; the
 * other three are plain text.
 */
final readonly class PageHeader
{
    /** The columns of the header, in the order they are written and told. */
    public const array COLUMNS = ['title', 'chapo', 'description', 'og_title', 'og_description'];

    /** The longest title the page holds (its column is 255 characters): a longer one is ignored. */
    public const int TITLE_LENGTH = DocumentSchema::HEADER_TITLE_LENGTH;

    /** The longest text of the header: a longer one is ignored, a summary being a short text column. */
    public const int TEXT_LENGTH = 10000;

    public function __construct(
        public ?string $title = null,
        public ?string $chapo = null,
        public ?string $description = null,
        public ?string $ogTitle = null,
        public ?string $ogDescription = null,
    ) {
    }

    public static function of(CmsPageI18n $held): self
    {
        return new self($held->getTitle(), $held->getChapo(), $held->getDescription(), $held->getOgTitle(), $held->getOgDescription());
    }

    /**
     * @param array<string, string|null> $words by column
     */
    public static function fromWords(array $words): self
    {
        return new self($words['title'] ?? null, $words['chapo'] ?? null, $words['description'] ?? null, $words['og_title'] ?? null, $words['og_description'] ?? null);
    }

    public function isEmpty(): bool
    {
        return [] === $this->words();
    }

    /**
     * What the header says, by column: the columns that hold something,
     * trimmed.
     *
     * @return array<string, string>
     */
    public function words(): array
    {
        $words = [];

        foreach (self::COLUMNS as $column) {
            $text = trim((string) $this->value($column));

            if ('' !== $text) {
                $words[$column] = $text;
            }
        }

        return $words;
    }

    /**
     * Every column with what the header holds in it, as it is, blank or not.
     *
     * @return array<string, string|null>
     */
    public function byColumn(): array
    {
        return array_combine(self::COLUMNS, array_map($this->value(...), self::COLUMNS));
    }

    /**
     * What the header holds in a column, as it is.
     */
    public function value(string $column): ?string
    {
        return match ($column) {
            'title' => $this->title,
            'chapo' => $this->chapo,
            'description' => $this->description,
            'og_title' => $this->ogTitle,
            'og_description' => $this->ogDescription,
            default => throw new \InvalidArgumentException(\sprintf('"%s" is not a column of the header of a page.', $column)),
        };
    }
}
