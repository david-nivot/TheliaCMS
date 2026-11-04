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

namespace TheliaCMS\ImportExport\Step;

use TheliaCMS\Content\ProvenanceMark;
use TheliaCMS\Content\Site\InvalidSiteComponentException;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\ImportReport;
use TheliaCMS\ImportExport\SiteDocument;

/**
 * The components a site file carries, and the ones its pages need.
 */
final readonly class ComponentsStep
{
    public function __construct(
        private ?SiteComponentRepository $components = null,
        private SiteComponentValidator $componentValidator = new SiteComponentValidator(),
    ) {
    }

    /**
     * The components the site made, checked as if the model had just written
     * them: a file is no reason to trust a template. One the site already has
     * under the same code is kept unless the import replaces, and then only
     * when the two differ.
     */
    public function import(SiteDocument $document, ImportOptions $options, ImportReport $report): void
    {
        if (null === $this->components) {
            return;
        }

        foreach ($document->components() as $row) {
            $code = \is_string($row['code'] ?? null) ? $row['code'] : '';

            try {
                $component = $this->componentValidator->validate(new SiteComponent(
                    $code,
                    \is_string($row['label'] ?? null) ? $row['label'] : '',
                    \is_string($row['description'] ?? null) ? $row['description'] : '',
                    \is_array($row['props'] ?? null) && array_is_list($row['props']) ? array_values(array_filter($row['props'], 'is_array')) : [],
                    \is_array($row['sample'] ?? null) ? $row['sample'] : [],
                    \is_string($row['template'] ?? null) ? $row['template'] : '',
                    \is_string($row['css'] ?? null) ? $row['css'] : '',
                    ProvenanceMark::normalize($row['provenance'] ?? null),
                ));
            } catch (InvalidSiteComponentException $exception) {
                $report->warn(\sprintf('The component "%s" was left out: %s', $code, implode(' ', $exception->problems)));

                continue;
            }

            // A file written before prototypes existed says nothing: what it
            // holds was kept for the whole site.
            $status = SiteComponentRepository::STATUS_DRAFT === ($row['status'] ?? null) ? SiteComponentRepository::STATUS_DRAFT : SiteComponentRepository::STATUS_READY;
            $existing = $this->components->find($component->code);

            if (null === $existing) {
                $this->components->save($component, $status);
                $report->created('components');

                continue;
            }

            if ($existing == $component || !$options->replace) {
                if ($existing != $component) {
                    $report->warn(\sprintf('The component "%s" of this site differs from the one of the file: the one of the site was kept.', $component->code));
                }

                $report->skipped('components');

                continue;
            }

            $this->components->save($component, $status, $component->code);
            $report->replaced('components');
        }
    }

    /**
     * A page of the file made of a component of a site the file does not
     * carry and this site does not have: it comes in with the HTML of the
     * file, without the styles of that component, and cannot be saved again
     * until such a component is made here.
     */
    public function reportMissing(SiteDocument $document, ImportReport $report): void
    {
        if (null === $this->components) {
            return;
        }

        $missing = [];

        foreach ($document->pages() as $page) {
            foreach (\is_array($page['contents'] ?? null) ? $page['contents'] : [] as $content) {
                foreach (['draft_document', 'published_document'] as $column) {
                    $decoded = \is_array($content) && \is_string($content[$column] ?? null) ? json_decode($content[$column], true) : null;

                    foreach (\is_array($decoded) && \is_array($decoded['sections'] ?? null) ? $decoded['sections'] : [] as $section) {
                        $name = \is_array($section) && \is_string($section['component'] ?? null) ? $section['component'] : '';

                        if (str_starts_with($name, SiteComponent::PREFIX) && null === $this->components->find(substr($name, \strlen(SiteComponent::PREFIX)))) {
                            $missing[$name] = true;
                        }
                    }
                }
            }
        }

        foreach (array_keys($missing) as $name) {
            $report->warn(\sprintf('Pages of the file are made of the component "%s", which neither the file nor this site has: they keep the HTML of the file, without its styles, and cannot be saved until it is made here.', $name));
        }
    }
}
