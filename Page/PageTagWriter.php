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

use Propel\Runtime\Connection\ConnectionInterface;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsTag;
use TheliaCMS\Page\Admin\CmsActivityLog;

/**
 * Every change to the tags goes through here.
 *
 * A tag shows on the pages that carry it and on the lists of pages, so
 * renaming, recoloring or deleting one drops them from the caches: the page itself did not
 * change, the caches have no other way to know.
 */
final readonly class PageTagWriter
{
    public function __construct(
        private PageTagRepository $tags,
        private CmsActivityLog $activityLog,
        private PageInvalidator $invalidator,
    ) {
    }

    /**
     * The tag of this name, created unless the site has it already under any
     * spelling of its case. The color goes to a tag this call creates; a tag
     * the site has already keeps its own.
     *
     * @throws \InvalidArgumentException when the name is empty or too long
     */
    public function add(string $name, ?PageTagColor $color = null, ?ConnectionInterface $connection = null): CmsTag
    {
        $name = PageTagName::normalize($name);

        if (!PageTagName::isValid($name)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid tag name.', $name));
        }

        $existing = $this->tags->findByName($name, $connection);

        if ($existing instanceof CmsTag) {
            return $existing;
        }

        $tag = (new CmsTag())->setName($name)->setColor($color?->background)->setTextColor($color?->text);
        $tag->save($connection);
        $this->activityLog->record('CREATE', (int) $tag->getId(), \sprintf('Tag "%s" added', $name));

        return $tag;
    }

    /**
     * @throws \InvalidArgumentException when the name is empty or too long
     * @throws PageTagExistsException    when another tag has this name already
     */
    public function rename(CmsTag $tag, string $name): void
    {
        $name = PageTagName::normalize($name);

        if (!PageTagName::isValid($name)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid tag name.', $name));
        }

        $other = $this->tags->findByName($name);

        if ($other instanceof CmsTag && (int) $other->getId() !== (int) $tag->getId()) {
            throw new PageTagExistsException($name);
        }

        $former = (string) $tag->getName();
        $tag->setName($name)->save();

        $this->purgePagesOf($tag);
        $this->activityLog->record('UPDATE', (int) $tag->getId(), \sprintf('Tag "%s" renamed to "%s"', $former, $name));
    }

    public function recolor(CmsTag $tag, ?PageTagColor $color): void
    {
        $former = PageTagColor::fromStored($tag->getColor(), $tag->getTextColor());

        if (null === $color ? null === $former : $color->equals($former)) {
            return;
        }

        $tag->setColor($color?->background)->setTextColor($color?->text)->save();

        $this->purgePagesOf($tag);
        $this->activityLog->record('UPDATE', (int) $tag->getId(), \sprintf('Tag "%s" recolored from %s to %s', (string) $tag->getName(), $former?->describe() ?? 'none', $color?->describe() ?? 'none'));
    }

    /**
     * Deletes the tag; the pages that carried it, binned or not, carry none.
     *
     * @return int how many pages were detached
     */
    public function delete(CmsTag $tag): int
    {
        $pages = $this->tags->pagesOf($tag);
        $name = (string) $tag->getName();
        $id = (int) $tag->getId();

        // The constraint of the column detaches the pages; done here as well
        // so an instance of a page this process still holds agrees with it.
        $tag->delete();

        foreach ($pages as $page) {
            $page->setTagId(null);
        }

        $this->purge($pages);
        $this->activityLog->record('DELETE', $id, \sprintf('Tag "%s" deleted, %d page(s) detached', $name, \count($pages)));

        return \count($pages);
    }

    private function purgePagesOf(CmsTag $tag): void
    {
        $this->purge($this->tags->pagesOf($tag));
    }

    /**
     * @param list<CmsPage> $pages
     */
    private function purge(array $pages): void
    {
        if ([] === $pages) {
            return;
        }

        $this->invalidator->contentChanged(...array_map(static fn (CmsPage $page): int => (int) $page->getId(), $pages));
    }
}
