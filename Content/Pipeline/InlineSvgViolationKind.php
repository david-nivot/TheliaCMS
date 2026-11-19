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

namespace TheliaCMS\Content\Pipeline;

enum InlineSvgViolationKind: string
{
    /** The file is not an XML document the policy can read. */
    case Unreadable = 'unreadable';

    /** An element a page drops, with all it holds. */
    case Element = 'element';

    /** An attribute a page drops. */
    case Attribute = 'attribute';

    /** A value its attribute does not take, which a page drops. */
    case Value = 'value';

    /** A reference to an id the drawing does not hold, which a page drops. */
    case Reference = 'reference';

    /** A mask or a clipping path asked for inside another one, which a page drops. */
    case NestedCut = 'nested_cut';

    /** Declarations of a `style` attribute a page drops. */
    case Style = 'style';

    /** Text written in the drawing, which a page would print as text. */
    case Text = 'text';
}
