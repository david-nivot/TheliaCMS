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
use TheliaCMS\Model\CmsPageContent;

/**
 * Publication state of a page *for one locale* — a page is routinely published
 * in French and still a draft in English.
 */
enum PageStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case ModifiedSincePublish = 'modified';
    case Unpublished = 'unpublished';

    public static function resolve(CmsPage $page, ?CmsPageContent $content, ?\DateTimeInterface $now = null): self
    {
        $now ??= new \DateTimeImmutable();

        if (null === $content?->getPublishedAt()) {
            return self::Draft;
        }

        if (1 !== $page->getVisible()) {
            return self::Unpublished;
        }

        $unpublishAt = $page->getUnpublishAt();
        if (null !== $unpublishAt && $unpublishAt <= $now) {
            return self::Unpublished;
        }

        $publishAt = $page->getPublishAt();
        if (null !== $publishAt && $publishAt > $now) {
            return self::Scheduled;
        }

        // The draft was touched after the snapshot went live: what visitors see
        // is no longer what the editor last saved.
        $draftUpdatedAt = $content->getUpdatedAt();
        if (null !== $draftUpdatedAt && $draftUpdatedAt > $content->getPublishedAt()) {
            return self::ModifiedSincePublish;
        }

        return self::Published;
    }

    /**
     * What visitors read in this language: published, or published and
     * changed since, the version online being the published one.
     */
    public function isOnline(): bool
    {
        return self::Published === $this || self::ModifiedSincePublish === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Published => 'Published',
            self::ModifiedSincePublish => 'Modified since publication',
            self::Unpublished => 'Unpublished',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-secondary',
            // `bg-info` puts white on cyan, which measures 1.95 to 1 and is the
            // worst piece of text on the screen. The paired class picks the
            // foreground that goes with the background.
            self::Scheduled => 'text-bg-info',
            self::Published => 'bg-success',
            self::ModifiedSincePublish => 'bg-warning text-dark',
            self::Unpublished => 'bg-dark',
        };
    }
}
