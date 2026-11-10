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

namespace TheliaCMS\Front;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the script of the embedded videos, under an address that carries
 * the hash of the file.
 */
final readonly class EmbedScriptController
{
    public function __construct(
        private EmbedScript $script,
    ) {
    }

    #[Route('/cms/embed.js', name: 'cms.embed_script', methods: ['GET'])]
    public function __invoke(): Response
    {
        $path = $this->script->path();

        if (!is_file($path)) {
            throw new NotFoundHttpException('The script of the embedded videos is missing from the module.');
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'text/javascript; charset=utf-8');
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setImmutable();
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }
}
