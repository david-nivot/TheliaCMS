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

namespace TheliaCMS\Tests\Integration;

use TheliaCMS\Catalogue\Admin\ContentTargets;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Editing\ContentTarget;

/**
 * The target of a catalogue item for the shared writer, from the container.
 * A test that spies on purges overrides it with the spy's own `ContentTargets`.
 */
trait BuildsItemTargets
{
    protected function itemTarget(CatalogueItemType $type, int $id, string $locale): ContentTarget
    {
        return $this->getService(ContentTargets::class)->forItemKey($type, $id, $locale);
    }
}
