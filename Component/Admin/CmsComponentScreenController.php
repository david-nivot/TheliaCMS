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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use TheliaCMS\Content\Admin\SectionUsageFinder;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\SectionAvailability;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\SiteComponentCallers;
use TheliaCMS\Content\Site\SiteComponentPermission;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentSection;
use TheliaCMS\Content\Site\SitePropRows;
use TheliaCMS\Generation\Admin\PreviewWidths;
use TheliaCMS\Generation\Admin\SectionFormBuilder;
use TheliaCMS\Generation\Admin\ToolkitLink;
use TheliaCMS\Generation\Component\ComponentCandidates;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Locale\ShopLanguages;
use TheliaCMS\Preview\ComponentPreviewLink;
use TheliaCMS\Security\CmsResources;
use Twig\Environment;

/**
 * The page of one component of the catalogue, whatever its origin: what it
 * is, how many pages use it, the props it takes. A component made on this
 * site adds what its editor does with it: asking a change, copying, keeping
 * a prototype for the whole site, deleting, its history and its code.
 *
 * The name is the one of the catalogue (`cms:richtext`, `Partial:cms-form`,
 * `Layouts:Subheader:Base`, `Site:IconCards`), in the path as it is: it holds
 * no slash.
 */
#[Route('/admin/cms/components', name: 'admin.cms.components.')]
final readonly class CmsComponentScreenController
{
    public function __construct(
        private Environment $twig,
        private SectionCatalog $catalog,
        private SectionAvailability $availability,
        private SectionUsageFinder $usage,
        private SitePropRows $propRows,
        private ComponentPreviewLink $previews,
        private SiteComponentRepository $components,
        private ComponentCandidates $candidates,
        private SiteComponentCallers $callers,
        private SiteComponentPermission $permission,
        private SectionFormBuilder $forms,
        private AdminControllerSupport $support,
        private ShopLanguages $languages,
        private PreviewWidths $previewWidths,
        private ToolkitLink $toolkit,
    ) {
    }

    #[Route('/{name}', name: 'show', requirements: ['name' => '[A-Za-z0-9:_-]+'], methods: ['GET'])]
    public function show(string $name, Request $request): Response
    {
        $definition = $this->catalog->find($name) ?? throw new NotFoundHttpException();
        $pages = $this->usage->pageCounts()[$name] ?? 0;
        $script = __DIR__.'/../../templates/backOffice/default-twig/assets/component-generator.js';
        $scripts = __DIR__.'/../../templates/backOffice/default-twig/assets/';
        $locale = $request->getLocale();

        // The controls of the preview: the props typed here draw the frame and
        // are kept nowhere. A sample the component itself refuses leaves the
        // page without them, and the frame says why.
        try {
            $controls = $this->forms->forComponent($definition, $this->previews->section($name, $locale))->createView();
        } catch (InvalidDocumentException) {
            $controls = null;
        }

        $offeredToPages = isset($this->catalog->offered()[$name]);
        $languages = $this->languages->choices();
        // The language of the screen when the site has it, else the first.
        $default = array_values(array_filter($languages, static fn (array $language): bool => $language['locale'] === $locale))[0] ?? $languages[0] ?? null;

        $parameters = [
            'name' => $name,
            'label' => $definition->label(),
            'description' => $definition->description(),
            'props' => $this->propRows->fromSchema($definition->schema()),
            'pages' => $pages,
            'component' => null,
            'prototype' => false,
            'offered' => $this->availability->isOffered($name) || !$this->availability->canBeDisabled($name),
            'preview_url' => $this->previews->section($name, $locale),
            'preview_widths' => $this->previewWidths->widths(),
            'controls' => $controls,
            'history' => [],
            'in_place' => null,
            'callers' => [],
            'may_make' => false,
            'may_ask' => false,
            // A page takes the component when the site offers it to the pages and
            // the administrator may change pages, as on the content screen.
            'may_add_to_page' => null !== $controls && $offeredToPages && $this->support->isGranted(CmsResources::PAGE, AccessManager::UPDATE),
            // Its section on the page of the toolkit, which draws the components offered to pages only.
            'toolkit_url' => $offeredToPages ? $this->toolkit->component($name) : null,
            'languages' => $languages,
            'default_language' => $default['id'] ?? null,
            'assets_version' => substr(hash('xxh3', (string) @filemtime($script).@filemtime($scripts.'component-preview.js').@filemtime($scripts.'section-form.js').@filemtime($scripts.'content-editor.css')), 0, 12),
        ];

        if ($definition instanceof SiteComponentSection) {
            $code = $definition->component->code;
            // A prototype is offered to nobody until it is kept for the whole site.
            $prototype = SiteComponentRepository::STATUS_DRAFT === $this->components->status($code);

            $parameters = [
                'component' => $definition->component,
                'prototype' => $prototype,
                'offered' => !$prototype && $this->availability->isOffered($name),
                'history' => $this->candidates->history($this->components->idOf($code)),
                // The call whose version is in place, marked in the history.
                'in_place' => $this->components->generationOf($code),
                'callers' => $this->callers->of($code),
                'may_make' => $this->permission->mayMake(),
                'may_ask' => $this->permission->mayAsk(),
            ] + $parameters;
        }

        return new Response($this->twig->render('@TheliaCMSModule/backOffice/default-twig/components/show.html.twig', $parameters));
    }
}
