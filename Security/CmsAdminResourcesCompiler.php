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

namespace TheliaCMS\Security;

use Thelia\Module\AbstractAdminResourcesCompiler;

final class CmsAdminResourcesCompiler extends AbstractAdminResourcesCompiler
{
    public function getResources(): array
    {
        return [
            'PAGE' => CmsResources::PAGE,
            'MENU' => CmsResources::MENU,
            'FORM' => CmsResources::FORM,
            'FORM_ANSWERS' => CmsResources::FORM_ANSWERS,
            'FORM_ANSWERS_EXPORT' => CmsResources::FORM_ANSWERS_EXPORT,
            'MEDIA' => CmsResources::MEDIA,
            'SETTINGS' => CmsResources::SETTINGS,
            'CUSTOM_CODE' => CmsResources::CUSTOM_CODE,
            'GENERATE' => CmsResources::GENERATE,
        ];
    }

    public function getModuleCode(): string
    {
        return 'TheliaCMS';
    }
}
