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

namespace TheliaCMS\Media\Admin;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use TheliaCMS\Media\Icon\IconCatalogue;

/**
 * The icons an editor may pick for an `icon` prop: `{name, label, group,
 * preview}`, the name being the value the prop stores. A source of pickers like
 * the ones of the partials, under `/admin/cms`, where the guard of the module
 * asks for the right to see the pages.
 */
final readonly class IconSourceController
{
    public function __construct(private IconCatalogue $icons)
    {
    }

    #[Route('/admin/cms/partials/sources/icons', name: 'admin.cms.partials.sources.icons', methods: ['GET'])]
    public function icons(): JsonResponse
    {
        return new JsonResponse($this->icons->rows());
    }
}
