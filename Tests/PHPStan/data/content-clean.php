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

namespace TheliaCMS\Content\Probe;

use TheliaCMS\Page\Admin\CmsPageWriter;

final class Clean
{
    public function page(): string
    {
        return CmsPageWriter::class;
    }
}
