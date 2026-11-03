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

namespace TheliaCMS\Documentation;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use TheliaCMS\Hook\CmsDocumentationLinkHook;
use TheliaCMS\Security\CmsResources;

/**
 * The address of the part of the documentation that explains a screen of the
 * CMS: drawn above most screens by CmsDocumentationLinkHook, and at the top
 * of its panel by the content screen, which replaces the layout the hook
 * draws in.
 */
final readonly class DocumentationLink
{
    public function __construct(
        private SecurityContext $securityContext,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Null when the screen gets no link: out of the CMS, the documentation
     * itself, or an administrator who may not read it, as it takes the right
     * to see the pages and a link to a 403 helps nobody.
     */
    public function forRoute(string $route): ?string
    {
        if (!str_starts_with($route, 'admin.cms.') || 'admin.cms.documentation' === $route) {
            return null;
        }

        if (!$this->securityContext->isGranted(['ADMIN'], [CmsResources::PAGE], [], [AccessManager::VIEW])) {
            return null;
        }

        $section = CmsDocumentationLinkHook::sectionOf($route);

        return $this->urls->generate('admin.cms.documentation').(null === $section ? '' : '#'.$section);
    }
}
