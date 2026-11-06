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

namespace TheliaCMS\Zone\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Zone\HeaderZone;
use Twig\Environment;

/**
 * The way into the header zone: a page of the CMS that is hidden, written on
 * the content screen of a page.
 *
 * Looking is a read; making the page the first time is a write, so it takes a
 * button that posts, never a link that creates.
 */
#[Route('/admin/cms/zones/header', name: 'admin.cms.zones.header', methods: ['GET', 'POST'])]
final readonly class HeaderZoneController
{
    private const string TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/zones/header.html.twig';

    public function __construct(
        private Environment $twig,
        private AdminControllerSupport $support,
        private EditLanguage $languages,
        private HeaderZone $zones,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $lang = $this->languages->resolve($request);

        if ($request->isMethod('POST')) {
            $this->support->denyUnless(CmsResources::PAGE, AccessManager::UPDATE, 'You are not allowed to change CMS pages.');

            return $this->open($this->zones->ensure(), (int) $lang->getId());
        }

        $this->support->denyUnless(CmsResources::PAGE, AccessManager::VIEW, 'You are not allowed to see CMS pages.');
        $zone = $this->zones->find();

        if (null !== $zone) {
            return $this->open($zone, (int) $lang->getId());
        }

        return new Response($this->twig->render(self::TEMPLATE, [
            'edit_language_id' => $lang->getId(),
            'may_create' => $this->support->isGranted(CmsResources::PAGE, AccessManager::UPDATE),
        ]));
    }

    private function open(CmsPage $zone, int $languageId): Response
    {
        return $this->support->redirectInLanguage('admin.cms.pages.content.edit', $languageId, ['id' => $zone->getId()]);
    }
}
