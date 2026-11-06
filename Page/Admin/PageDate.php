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

use TheliaCMS\Model\Map\CmsPageTableMap;

/**
 * The dates the page listing can keep a period of. The publication is the one
 * of the language listed; the dates a page goes online and offline on are
 * shared by every language.
 */
enum PageDate: string
{
    case Modified = 'modified';
    case Created = 'created';
    case Published = 'published';
    case PublishOn = 'publish_on';
    case UnpublishOn = 'unpublish_on';

    public function label(): string
    {
        return match ($this) {
            self::Modified => 'Last modified',
            self::Created => 'Creation date',
            self::Published => 'Publication date',
            self::PublishOn => 'Publish on',
            self::UnpublishOn => 'Unpublish on',
        };
    }

    /**
     * Whether the date is read on the content of the language listed.
     */
    public function readsTheContent(): bool
    {
        return self::Modified === $this || self::Published === $this;
    }

    /**
     * The column, or the expression, the period applies to. Written from the
     * enum only: nothing of the request reaches the SQL.
     */
    public function sql(): string
    {
        $page = '`'.CmsPageTableMap::TABLE_NAME.'`';
        $content = '`'.PageStatusCriteria::CONTENT_ALIAS.'`';

        return match ($this) {
            // The latest of the page and of its content, as the column shows it.
            self::Modified => "GREATEST(COALESCE({$page}.`updated_at`, {$content}.`updated_at`), COALESCE({$content}.`updated_at`, {$page}.`updated_at`))",
            self::Created => $page.'.`created_at`',
            self::Published => $content.'.`published_at`',
            self::PublishOn => $page.'.`publish_at`',
            self::UnpublishOn => $page.'.`unpublish_at`',
        };
    }
}
