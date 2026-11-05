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

use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Model\CmsPage;

/**
 * What a publication of a page says once it went through: the content is
 * published, and the page is online only when its settings let it be.
 */
final readonly class PageOnlineMessage
{
    public function __construct(
        private AdminControllerSupport $support,
        private CmsPageAdminRepository $pages,
    ) {
    }

    public function say(CmsPage $page, string $locale): string
    {
        return match ($this->pages->statusOf($page, $locale)) {
            PageStatus::Unpublished => $this->support->translate('The content is published, but the page is hidden: show it to put it online.'),
            PageStatus::Scheduled => $this->support->translate('The content is published: the page goes online on %date%.', [
                '%date%' => $page->getPublishAt()?->format('Y-m-d H:i') ?? '',
            ]),
            default => $this->support->translate('The page is online.'),
        };
    }
}
