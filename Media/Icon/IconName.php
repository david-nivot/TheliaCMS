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

use TheliaCMS\Locale\TranslatableText;

/**
 * The name an SVG of the library answers to as an icon: `document-valide` is
 * drawn by `cms:document-valide`.
 *
 * Stored without the prefix. The rule is the one of a slug of ux-icons
 * (`Icon::isValidName`), so a name that passes is a name the icon chain accepts.
 */
final class IconName
{
    /** Prefix of the icon set of the module, as written in `ux_icon()`. */
    public const string PREFIX = 'cms:';

    public const int MAXIMUM_LENGTH = 64;

    /** A name as `ux_icon()` reads it: `cms:` or an icon set, then a slug. */
    public const int MAXIMUM_REFERENCE_LENGTH = 128;

    private const string PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/D';

    /** The rule of `Symfony\UX\Icons\Icon::isValidName()`, which the module does not require. */
    private const string REFERENCE_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*(:[a-z0-9]+(-[a-z0-9]+)*)*$/D';

    public static function isValid(string $name): bool
    {
        return \strlen($name) <= self::MAXIMUM_LENGTH && 1 === preg_match(self::PATTERN, $name);
    }

    /** What an editor is told of a name that is not valid. */
    public static function invalidMessage(): TranslatableText
    {
        return TranslatableText::of('The icon name is made of lowercase letters and digits, separated by single hyphens, %d characters at most.', self::MAXIMUM_LENGTH);
    }

    /**
     * Whether a value stored by an icon prop is shaped like a name `ux_icon()`
     * accepts: `cms:document-valide`, or the name of an icon of the theme. The
     * shape only: whether the icon exists is not asked, a document keeps its
     * icon when the icon is taken away.
     */
    public static function isReference(string $reference): bool
    {
        return \strlen($reference) <= self::MAXIMUM_REFERENCE_LENGTH && 1 === preg_match(self::REFERENCE_PATTERN, $reference);
    }

    /**
     * The name an editor typed, as it is stored: trimmed, without the `cms:`
     * prefix the editor may have typed. The case is kept, so a name with
     * capitals is refused by the rule, not changed without the editor seeing it.
     */
    public static function normalize(string $typed): string
    {
        $typed = trim($typed);

        return str_starts_with($typed, self::PREFIX) ? substr($typed, \strlen(self::PREFIX)) : $typed;
    }

    /**
     * Whether a text writes the icon between quotes, single or double:
     * `cms:arrow` is not found in `"cms:arrow-2"`.
     */
    public static function isQuotedIn(string $text, string $name): bool
    {
        $prefixed = self::prefixed($name);

        return str_contains($text, '"'.$prefixed.'"') || str_contains($text, "'".$prefixed."'");
    }

    /** The name as `ux_icon()` writes it. */
    public static function prefixed(string $name): string
    {
        return self::PREFIX.$name;
    }
}
