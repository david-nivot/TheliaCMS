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

use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * The name proposed for an icon, drawn from the name of the file it came in:
 * lowercase, accents removed, anything else a hyphen, no doubled or edge
 * hyphens, 64 characters at most.
 *
 * A file whose name holds no letter or digit (`***.svg`)
 * gets no proposal: the editor types one.
 */
final readonly class IconNameSuggestion
{
    public static function fromFileName(string $fileName): string
    {
        $stem = pathinfo($fileName, \PATHINFO_FILENAME);
        $name = (new AsciiSlugger())->slug($stem)->lower()->toString();
        $name = (string) preg_replace('/[^a-z0-9]+/', '-', $name);

        return trim(substr(trim($name, '-'), 0, IconName::MAXIMUM_LENGTH), '-');
    }
}
