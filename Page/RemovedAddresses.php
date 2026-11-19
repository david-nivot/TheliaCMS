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

namespace TheliaCMS\Page;

/**
 * What `PageAddressRemover` deleted.
 */
final readonly class RemovedAddresses
{
    /**
     * @param int $total        rows of `rewriting_url` deleted
     * @param int $ofOtherViews among them, the rows of a view other than a page: addresses of a content, a product, a folder... that redirected to an address of a page
     */
    public function __construct(
        public int $total,
        public int $ofOtherViews,
    ) {
    }

    /**
     * The sentence the activity log appends, empty when no address of another view went.
     */
    public function logNote(): string
    {
        return 0 === $this->ofOtherViews
            ? ''
            : \sprintf(' (%d address(es) of other views that redirected to them also removed)', $this->ofOtherViews);
    }
}
