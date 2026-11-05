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

namespace TheliaCMS\Content\Editing;

/**
 * What identifies the content that is versioned: its family (`page`, or the
 * type of a catalogue item), its id and its language.
 */
final readonly class ContentKey
{
    public const string PAGE = 'page';

    public function __construct(
        public string $kind,
        public int $id,
        public string $locale,
    ) {
    }

    public static function page(int $id, string $locale): self
    {
        return new self(self::PAGE, $id, $locale);
    }
}
