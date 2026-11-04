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

namespace TheliaCMS\Content\Admin;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use TheliaCMS\Content\Partial\PartialSection;
use TheliaCMS\Content\SectionAvailability;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\SectionDefinitionInterface;
use TheliaCMS\Content\Site\SiteComponentPermission;
use TheliaCMS\Content\Site\SiteComponentSection;
use TheliaCMS\Content\Theme\ThemeComponentSection;
use TheliaCMS\Generation\Admin\ToolkitLink;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\TheliaCMS;
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
    private const string LIST_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/components/list.html.twig';

    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private SecurityContext $securityContext,
        private TranslatorInterface $translator,
        private SectionCatalog $catalog,
        private SectionAvailability $availability,
        private SectionUsageFinder $usage,
        private ToolkitLink $toolkit,
        private SiteComponentPermission $permission,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        $pageCounts = $this->usage->pageCounts();
        $toolkitUrl = $this->toolkit->url();
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
                'toolkit_url' => null === $toolkitUrl ? null : $toolkitUrl.'#'.self::toolkitAnchor($name),
                'site_url' => $definition instanceof SiteComponentSection ? $this->urls->generate('admin.cms.components.site.show', ['component' => $definition->component->code]) : null,
                'promote_url' => $prototype ? $this->urls->generate('admin.cms.components.site.promote', ['component' => $definition->component->code]) : null,
            ];
        }

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'components' => $components,
            'may_update' => $this->isGranted(AccessManager::UPDATE),
            'may_make' => $this->permission->mayMake(),
            'may_ask' => $this->permission->mayAsk(),
            'toolkit_url' => $toolkitUrl,
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
        if (!$this->isGranted(AccessManager::UPDATE)) {
            throw new AccessDeniedHttpException($this->translate('You are not allowed to change the components offered to the generator.'));
        }

        $name = (string) $request->request->get('component', '');
        $definition = $this->catalog->find($name);

        if (null === $definition) {
            throw new NotFoundHttpException();
        }

        if (!$this->availability->canBeDisabled($name)) {
            throw new BadRequestHttpException($this->translate('Free text is always offered to the generator.'));
        }

        $offered = '1' === (string) $request->request->get('offered', '');
        $this->availability->setOffered($name, $offered);

        $this->flash($request, $this->translator->trans(
            $offered ? '"%label%" is offered to the generator again.' : '"%label%" is no longer offered to the generator. The pages that use it are left as they are.',
            ['%label%' => $definition->label()],
            TheliaCMS::DOMAIN_NAME,
        ));

        return new RedirectResponse($this->urls->generate('admin.cms.components.list'));
    }

    /**
     * The id the toolkit page gives the section of a component, spelled the
     * way `templates/front/sections/toolkit/all.html.twig` writes it.
     */
    private static function toolkitAnchor(string $name): string
    {
        return str_replace(':', '-', mb_strtolower($name));
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

    private function flash(Request $request, string $message): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();

        if (method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add('success', $message);
        }
    }

    private function translate(string $message): string
    {
        return $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME);
    }

    private function isGranted(string $access): bool
    {
        return $this->securityContext->isGranted(['ADMIN'], [CmsResources::SETTINGS], [], [$access]);
    }
}
