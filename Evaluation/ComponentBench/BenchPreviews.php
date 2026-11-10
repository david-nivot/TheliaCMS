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

namespace TheliaCMS\Evaluation\ComponentBench;

use Thelia\Model\ConfigQuery;
use TheliaCMS\Preview\ComponentPreviewLink;

/**
 * The signed address of the preview of a candidate, on the address of the
 * shop, for the browser script that opens it. It expires after an hour
 * (ComponentPreviewLink::LIFETIME_IN_SECONDS).
 */
final readonly class BenchPreviews
{
    public function __construct(private ComponentPreviewLink $links)
    {
    }

    public function candidate(int $ledgerId, string $locale): string
    {
        $path = $this->links->candidate($ledgerId, $locale);

        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return rtrim((string) ConfigQuery::getConfiguredShopUrl(), '/').$path;
    }
}
