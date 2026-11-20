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

namespace TheliaCMS\Page\Admin;

use TheliaCMS\Model\CmsPage;

/**
 * Where a page dropped on another one of the tree goes: just before it, just
 * after it, among the same siblings, or inside it, first of its pages.
 *
 * Named relative to a page of the tree rather than as a parent and a number:
 * the tree shows the first pages of a large branch only, and a position counted
 * on the rows an editor sees is not a position among all the siblings.
 */
enum PagePlacement: string
{
    case Before = 'before';
    case After = 'after';
    case Inside = 'inside';

    /**
     * The page the moved one goes under.
     */
    public function parentUnder(CmsPage $target): int
    {
        return self::Inside === $this ? (int) $target->getId() : (int) $target->getParent();
    }
}
