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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use Twig\Environment;

/**
 * The pages behind a number of the back office: the one route every drawer
 * "used by N pages" asks, whatever the element. It only reads.
 *
 * CmsAdminGuard holds `/admin/cms/linked-pages/{kind}` to the resource of the
 * screen the number is on (see LinkedPageKind); the controller asks the same
 * question again, so the route stays closed if the guard is ever changed.
 */
#[Route('/admin/cms/linked-pages', name: 'admin.cms.linked_pages')]
final readonly class LinkedPagesController
{
    private const string TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/linked-pages/_list.html.twig';

    public function __construct(
        private Environment $twig,
        private AdminControllerSupport $support,
        private EditLanguage $languages,
        private LinkedPagesFinder $finder,
    ) {
    }

    #[Route('/{kind}', name: '', requirements: ['kind' => 'component|tag|page-type|media'], methods: ['GET'])]
    public function __invoke(Request $request, string $kind): Response
    {
        $kind = LinkedPageKind::from($kind);
        $this->support->denyUnless($kind->resource(), AccessManager::VIEW, 'You are not allowed to access the CMS section.');

        $lang = $this->languages->resolve($request);
        $linked = $this->finder->find($kind, (string) $request->query->get('key', ''), $lang->getLocale(), $lang->getId()) ?? throw new NotFoundHttpException();

        return new Response($this->twig->render(self::TEMPLATE, [
            'linked' => $linked,
            'edit_language_id' => $lang->getId(),
        ]));
    }
}
