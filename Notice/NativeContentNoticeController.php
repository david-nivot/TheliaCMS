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

namespace TheliaCMS\Notice;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use Thelia\Tools\TokenProvider;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Security\CmsResources;

/**
 * Closes the native content notice of the Pages screen, for the whole shop.
 *
 * Under `/admin/cms/pages`, so CmsAdminGuard asks for the page permission
 * first; closing it changes what every editor sees, so it takes the right to
 * change pages, and the token of the back office.
 */
final readonly class NativeContentNoticeController
{
    public function __construct(
        private NativeContentNotice $notice,
        private AdminControllerSupport $support,
        private TokenProvider $tokens,
    ) {
    }

    #[Route('/admin/cms/pages/native-content-notice/dismiss', name: 'admin.cms.pages.native_content_notice.dismiss', methods: ['POST'])]
    public function dismiss(Request $request): Response
    {
        if (!$this->support->isGranted(CmsResources::PAGE, AccessManager::UPDATE)) {
            throw new AccessDeniedHttpException();
        }

        // Throws TokenAuthenticationException, which the back-office theme
        // answers with a 403.
        $this->tokens->checkRequestToken($request);

        $this->notice->dismiss();

        return $this->support->redirect('admin.cms.pages.list');
    }
}
