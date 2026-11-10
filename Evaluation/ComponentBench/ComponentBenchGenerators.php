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

namespace TheliaCMS\Evaluation\ComponentBench;

use Psr\Log\LoggerInterface;
use Thelia\Domain\Ai\AiGatewayInterface;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentUsage;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Generation\Ai\GatewayFailures;
use TheliaCMS\Generation\Component\ComponentGenerator;
use TheliaCMS\Generation\Component\ComponentPrompt;
use TheliaCMS\Generation\GenerationLog;
use TheliaCMS\Generation\GeneratorSettings;

/**
 * Builds the generator of components of the bench around the gateway it is
 * given (a recording one), with the services of the normal generator and
 * nothing changed in them.
 */
final readonly class ComponentBenchGenerators
{
    public function __construct(
        private GeneratorSettings $settings,
        private ComponentPrompt $prompts,
        private SiteComponentValidator $validator,
        private SiteComponentRepository $components,
        private GenerationLog $log,
        private LoggerInterface $logger,
        private GatewayFailures $failures,
        private SiteComponentUsage $usage,
        private SectionCatalog $catalog,
    ) {
    }

    public function around(AiGatewayInterface $gateway): ComponentGenerator
    {
        return new ComponentGenerator(
            $gateway,
            $this->settings,
            $this->prompts,
            $this->validator,
            $this->components,
            $this->log,
            $this->logger,
            $this->failures,
            $this->usage,
            $this->catalog,
        );
    }
}
