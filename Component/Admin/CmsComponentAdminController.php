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

namespace TheliaCMS\Component\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use TheliaCMS\Content\Admin\SectionUsageFinder;
use TheliaCMS\Content\Partial\PartialSection;
use TheliaCMS\Content\SectionAvailability;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\SectionDefinitionInterface;
use TheliaCMS\Content\Site\SiteComponentPermission;
use TheliaCMS\Content\Site\SiteComponentSection;
use TheliaCMS\Content\Theme\ThemeComponentSection;
use TheliaCMS\Generation\Admin\ToolkitLink;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Security\CmsResources;
use Twig\Environment;

/**
 * The components a page may be made of, and which of them the generator is
 * offered.
 *
 * Under /admin/cms/components, which CmsAdminGuard holds to the settings
 * permission: putting a component away steers every page the generator
 * writes, the way the skills do.
 */
#[Route('/admin/cms/components', name: 'admin.cms.components.')]
final readonly class CmsComponentAdminController
{
    /** The origins in the order of the filter buttons and of the rows: the components made on this site first. */
    private const array ORIGINS = ['site', 'theme', 'module', 'partial'];

    private const string LIST_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/components/list.html.twig';

    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private AdminControllerSupport $support,
        private SectionCatalog $catalog,
        private SectionAvailability $availability,
        private SectionUsageFinder $usage,
        private SiteComponentPermission $permission,
        private ToolkitLink $toolkit,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        $pageCounts = $this->usage->pageCounts();
        $components = [];

        foreach ($this->catalog->all() as $name => $definition) {
            // A prototype is offered to nobody until it is kept for the whole
            // site: switching it on or off has no meaning yet.
            $prototype = $definition instanceof SiteComponentSection && $definition->prototype;

            $components[] = [
                'name' => $name,
                'label' => $definition->label(),
                'description' => $definition->description(),
                'origin' => self::origin($definition),
                'prototype' => $prototype,
                'pages' => $pageCounts[$name] ?? 0,
                'offered' => !$prototype && ($this->availability->isOffered($name) || !$this->availability->canBeDisabled($name)),
                'may_disable' => !$prototype && $this->availability->canBeDisabled($name),
                'show_url' => $this->urls->generate('admin.cms.components.show', ['name' => $name]),
            ];
        }

        // The rows follow the order of the filter buttons, origin by origin; the
        // sort is stable, so each origin keeps the order of the catalogue.
        $rank = array_flip(self::ORIGINS);
        usort($components, static fn (array $left, array $right): int => $rank[$left['origin']] <=> $rank[$right['origin']]);

        $counts = array_count_values(array_column($components, 'origin'));
        $originCounts = ['all' => \count($components)];

        foreach (self::ORIGINS as $origin) {
            $originCounts[$origin] = $counts[$origin] ?? 0;
        }

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'components' => $components,
            // A prototype counts with the components of the site.
            'origin_counts' => $originCounts,
            'may_update' => $this->support->isGranted(CmsResources::SETTINGS, AccessManager::UPDATE),
            'may_make' => $this->permission->mayMake(),
            'may_ask' => $this->permission->mayAsk(),
            // The page of the toolkit that draws every component offered, where the toolkit answers.
            'toolkit_url' => $this->toolkit->url(),
        ]));
    }

    /**
     * The name travels in the body rather than in the path: a component of
     * the theme is called `Layouts:CrossSelling:Base`, which a path segment
     * would have to escape.
     */
    #[Route('/availability', name: 'availability', methods: ['POST'])]
    public function availability(Request $request): Response
    {
        $this->support->denyUnless(CmsResources::SETTINGS, AccessManager::UPDATE, 'You are not allowed to change the components offered to the generator.');

        $name = (string) $request->request->get('component', '');
        $definition = $this->catalog->find($name);

        if (null === $definition) {
            throw new NotFoundHttpException();
        }

        if (!$this->availability->canBeDisabled($name)) {
            throw new BadRequestHttpException($this->support->translate('Free text is always offered to the generator.'));
        }

        $offered = '1' === (string) $request->request->get('offered', '');
        $this->availability->setOffered($name, $offered);

        $this->support->flash($request, 'success', $this->support->translate(
            $offered ? '"%label%" is offered to the generator again.' : '"%label%" is no longer offered to the generator. The pages that use it are left as they are.',
            ['%label%' => $definition->label()],
        ));

        return $this->support->redirect('admin.cms.components.list');
    }

    private static function origin(SectionDefinitionInterface $definition): string
    {
        return match (true) {
            $definition instanceof ThemeComponentSection => 'theme',
            $definition instanceof PartialSection => 'partial',
            $definition instanceof SiteComponentSection => 'site',
            default => 'module',
        };
    }
}
