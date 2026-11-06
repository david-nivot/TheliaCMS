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

namespace TheliaCMS\Media\Svg\Color;

/**
 * What becomes of the colors of a drawing: a color that follows the text is
 * written `currentColor`, and takes the color of the text around the drawing
 * once it is placed in a page.
 */
enum SvgColorPreset: string
{
    /** The colors stay as they are. */
    case Keep = 'keep';

    /** Every color follows the text. */
    case Monochrome = 'monochrome';

    /** The main color follows the text; the others stay, or follow it lighter. */
    case Main = 'main';

    /** Translation key of the theliacms domain. */
    public function label(): string
    {
        return match ($this) {
            self::Keep => 'Keep the colors',
            self::Monochrome => 'Monochrome',
            self::Main => 'Main color follows the text',
        };
    }

    /** Translation key of the theliacms domain. */
    public function help(): string
    {
        return match ($this) {
            self::Keep => 'The drawing keeps the colors it was made with.',
            self::Monochrome => 'Every color takes the color of the text around the drawing.',
            self::Main => 'The main color takes the color of the text; the others stay as they are, or follow it lighter.',
        };
    }
}
