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

namespace TheliaCMS\Content\Site;

use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use TheliaCMS\Security\CmsResources;

/**
 * Who may make a component of the site, wherever it is made from: the
 * components screen, the content screen of a page, or the generator writing
 * a page. A component is markup and a stylesheet shown on every page that
 * holds it, so it takes the settings and the custom code permissions; asking
 * the generator for one is a paid call, so it takes the generator permission
 * too.
 */
final readonly class SiteComponentPermission
{
    public function __construct(
        private SecurityContext $securityContext,
    ) {
    }

    public function mayMake(): bool
    {
        return $this->securityContext->isGranted(['ADMIN'], [CmsResources::SETTINGS], [], [AccessManager::UPDATE])
            && $this->securityContext->isGranted(['ADMIN'], [CmsResources::CUSTOM_CODE], [], [AccessManager::UPDATE]);
    }

    /**
     * Whether the generator may be asked for a component, or for a change of
     * one. Keeping, copying or deleting one calls no model: mayMake() is
     * enough for those.
     */
    public function mayAsk(): bool
    {
        return $this->mayMake()
            && $this->securityContext->isGranted(['ADMIN'], [CmsResources::GENERATE], [], [AccessManager::UPDATE]);
    }
}
