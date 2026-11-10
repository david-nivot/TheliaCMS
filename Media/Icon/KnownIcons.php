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

namespace TheliaCMS\Media\Icon;

/**
 * The icons a page may draw right now: the icons of the active front theme
 * and the live icons of the media library (`cms:<name>`).
 *
 * One definition of "known" for everyone who asks: the sandbox of the
 * components (a name taken away since the page was written draws nothing), and
 * the generator (a name the model invents is sent back). It is never asked of
 * a stored document: an icon taken away invalidates nothing.
 */
final readonly class KnownIcons
{
    /**
     * How many names are listed to a model. A name costs a few tokens and the
     * list sits in the prefix of every prompt: past this, the list would
     * weigh more than the catalogue of components. The icons of the library
     * come first, they are the ones the editors chose; the theme fills what
     * is left.
     */
    public const int LISTED_LIMIT = 200;

    public function __construct(
        private IconFileFinder $library,
        private ThemeIcons $theme,
    ) {
    }

    public function isKnown(string $reference): bool
    {
        if (!IconName::isReference($reference)) {
            return false;
        }

        if (str_starts_with($reference, IconName::PREFIX)) {
            $file = $this->library->fileOf(substr($reference, \strlen(IconName::PREFIX)));

            return null !== $file && is_file($file);
        }

        return $this->theme->has($reference);
    }

    /**
     * The names written as a prop holds them, in backticks: the icons of the
     * library (`cms:<name>`) then those of the theme, each group alphabetical,
     * cut at LISTED_LIMIT with the number left out said; null when the site
     * has no icon at all.
     */
    public function listing(): ?string
    {
        $names = [...array_map(IconName::prefixed(...), $this->library->names()), ...$this->theme->names()];

        if ([] === $names) {
            return null;
        }

        $listed = \array_slice($names, 0, self::LISTED_LIMIT);
        $text = implode(', ', array_map(static fn (string $name): string => '`'.$name.'`', $listed));

        if (\count($names) > \count($listed)) {
            $text .= \sprintf(' (and %d more, not listed: use none of them)', \count($names) - \count($listed));
        }

        return $text;
    }
}
