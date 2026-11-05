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

namespace TheliaCMS\Content\Editing;

use Propel\Runtime\Connection\ConnectionInterface;

/**
 * One content in one language, seen by the shared writer: what is written, how
 * it is versioned, what refuses a publication and what follows a write. The
 * writer does the same work for every family; each family answers here for
 * what is its own, so the writer never names a page or an item.
 *
 * A target is built per call (`ContentTargets`) and is not a service.
 */
interface ContentTarget
{
    public function key(): ContentKey;

    /** The row of the content, found or new. */
    public function row(): ContentRow;

    public function revisions(): RevisionStore;

    /**
     * Refuses a publication before anything is rendered.
     *
     * @throws \Throwable the family's own refusal
     */
    public function refuseBeforeRendering(): void;

    /**
     * Refuses a publication once the HTML is known.
     *
     * @throws \Throwable the family's own refusal
     */
    public function refuseRendered(string $html): void;

    /** The exception for a draft that would put nothing online. */
    public function emptyContent(): \Throwable;

    /** The exception for a revision that is not a kept draft of this content in this language. */
    public function notAKeptDraft(int $revisionId): \InvalidArgumentException;

    /**
     * @param list<string> $problems
     */
    public function brokenHeadings(array $problems): \Throwable;

    /**
     * @param list<string> $problems
     */
    public function inaccessible(array $problems): \Throwable;

    /** Work that belongs to the transaction of a publication, once the row is written. */
    public function duringPublication(string $html, ConnectionInterface $connection): void;

    public function afterSaved(): void;

    /** After the commit of a publication. */
    public function afterPublished(bool $wasOnline, bool $withCustomCode): void;

    public function afterRenderedAgain(): void;

    public function afterUnpublished(bool $wasOnline): void;
}
