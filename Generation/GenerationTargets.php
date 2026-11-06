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

namespace TheliaCMS\Generation;

use TheliaCMS\Catalogue\CatalogueItem;
use TheliaCMS\Content\Document;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Page\Field\PageFields;
use TheliaCMS\Page\PageTypeRepository;

/**
 * The target of a generation for a page, its type read as a template: what
 * the screens and the command hand the generator.
 */
final readonly class GenerationTargets
{
    public function __construct(
        private PageTypeRepository $types,
        private PageFields $fields,
    ) {
    }

    public function forPage(CmsPage $page, string $locale): GenerationTarget
    {
        $details = $this->types->details((string) $page->getPageType());

        return GenerationTarget::page($page, $locale, $details, $this->fields->valuesOf($page, $locale, $details->fields()), $this->modelDocument($page, $locale));
    }

    public function forItem(CatalogueItem $item): GenerationTarget
    {
        return GenerationTarget::item($item);
    }

    /**
     * The demo of the type, in this language: what a page of the type
     * written from a brief follows the structure of.
     */
    private function modelDocument(CmsPage $page, string $locale): ?Document
    {
        // The model of a type is the demo others follow, the header zone follows none.
        if (0 !== $page->getModel()) {
            return null;
        }

        $model = $this->types->modelOf((string) $page->getPageType());

        if (null === $model) {
            return null;
        }

        $document = Document::fromJson(CmsPageContentQuery::create()->filterByPageId($model->getId())->filterByLocale($locale)->findOne()?->getDraftDocument());

        return null === $document || $document->isEmpty() ? null : $document;
    }
}
