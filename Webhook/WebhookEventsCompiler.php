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

namespace TheliaCMS\Webhook;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Adds the events of the module to `thelia.webhook.events`.
 *
 * Added, never set: a parameter set by two modules keeps the value of the
 * last one, and the events of the other would leave the catalog without a
 * word. Run once every configuration is loaded, so whatever the core or
 * another module put there is already in.
 */
final class WebhookEventsCompiler implements CompilerPassInterface
{
    public const string PARAMETER = 'thelia.webhook.events';

    public function process(ContainerBuilder $container): void
    {
        $declared = $container->hasParameter(self::PARAMETER) ? $container->getParameter(self::PARAMETER) : [];

        $container->setParameter(self::PARAMETER, array_merge(\is_array($declared) ? $declared : [], WebhookEvents::definitions()));
    }
}
