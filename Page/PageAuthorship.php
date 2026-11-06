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
use Thelia\Core\Security\SecurityContext;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageQuery;

/**
 * Who created a page and who changed it last: the administrator acting, and
 * nobody when there is none (the command line, the install, the scheduler),
 * never a guessed id. Every write of the module that changes a page or its
 * content goes through here, so the list of pages can be filtered by author.
 */
final readonly class PageAuthorship
{
    public function __construct(
        private SecurityContext $securityContext,
    ) {
    }

    public function author(): ?int
    {
        $id = $this->securityContext->getAdminUser()?->getId();

        return null === $id ? null : (int) $id;
    }

    /**
     * On a page about to be saved: created by the administrator acting when
     * it is new, changed by them in any case.
     */
    public function stamp(CmsPage $page): CmsPage
    {
        $author = $this->author();

        if ($page->isNew()) {
            $page->setCreatedBy($author);
        }

        return $page->setUpdatedBy($author);
    }

    /**
     * For a write that does not save the page row (its content, the home
     * page): the column alone, so neither the events of a page save nor its
     * date follow. The date of a content change is the one of the content,
     * which the list already reads.
     *
     * @param list<int> $pageIds
     */
    public function sign(array $pageIds, ?ConnectionInterface $connection = null): void
    {
        $pageIds = array_values(array_filter($pageIds, static fn (int $id): bool => $id > 0));

        if ([] === $pageIds) {
            return;
        }

        CmsPageQuery::create()->filterById($pageIds)->update(['UpdatedBy' => $this->author()], $connection);
    }
}
