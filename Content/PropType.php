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

namespace TheliaCMS\Content;

/**
 * What a prop of a section holds. The type decides how a value is checked on
 * the way in, how it is described to whoever writes a document, and how the
 * template may print it.
 */
enum PropType: string
{
    /** A line of plain text, printed escaped. */
    case Text = 'text';

    /** Markup, filtered by the writer on the rendered page. */
    case Html = 'html';

    case Integer = 'integer';

    case Boolean = 'boolean';

    /** One of a closed list of values. */
    case Choice = 'choice';

    /** The id of an image of the media library. */
    case Image = 'image';

    /** The id of a video of the media library, which the template receives as the address of the file. */
    case Video = 'video';

    /** An address: absolute, or a path of the site. */
    case Url = 'url';

    /** A list of objects, each shaped by the item props of the prop. */
    case Items = 'items';

    /** The id of an item of the shop or of the site: a page, a menu, a form, a product. */
    case Reference = 'reference';

    /** A value kept as the component documents it: a list or an object the section does not describe further. */
    case Json = 'json';
}
