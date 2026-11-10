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

namespace TheliaCMS\Content\Site;

use Psr\Log\LoggerInterface;
use TheliaCMS\Content\EmbedFacade;
use TheliaCMS\Content\ImageFraming;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\SectionProviderInterface;

/**
 * The components the site made in the back office, as sections of the
 * catalogue, under `Site:<code>`: the ones kept for the whole site and the
 * prototypes, which the catalogue holds (the pages that hold one render it)
 * but does not offer.
 *
 * A row whose props no longer read is logged and skipped rather than fatal,
 * the way a component the theme no longer ships is.
 */
final readonly class SiteComponentSections implements SectionProviderInterface
{
    public function __construct(
        private SiteComponentRepository $components,
        private SiteTemplateRenderer $renderer,
        private SitePropReader $props,
        private LoggerInterface $logger,
        private ?ImageFraming $framing = null,
        private EmbedFacade $facade = new EmbedFacade(),
    ) {
    }

    public function sections(): iterable
    {
        $prototypes = $this->components->prototypes();

        foreach ($this->components->all() as $component) {
            try {
                $schema = $this->props->read($component->props);
            } catch (InvalidSiteComponentException $exception) {
                $this->logger->warning('TheliaCMS: the component {name} of the site cannot be read: {message}', ['name' => $component->name(), 'message' => $exception->getMessage()]);

                continue;
            }

            yield new SiteComponentSection($component, null === $this->framing ? $schema : new PropSchema($this->framing->extend($schema->props)), $this->renderer, $this->logger, prototype: \in_array($component->code, $prototypes, true), facade: $this->facade);
        }
    }
}
