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

namespace TheliaCMS\Generation\Admin;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Model\Lang;
use TheliaCMS\Catalogue\Admin\ContentTargets;
use TheliaCMS\Catalogue\Admin\EmptyItemContentException;
use TheliaCMS\Catalogue\Admin\ItemAccessibilityException;
use TheliaCMS\Catalogue\Admin\ItemContentStatus;
use TheliaCMS\Catalogue\Admin\ItemHeadingStructureException;
use TheliaCMS\Catalogue\CatalogueItem;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\DealerStores;
use TheliaCMS\Catalogue\ItemContentRepository;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentTarget;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Generation\GenerationTarget;
use TheliaCMS\Generation\GenerationTargets;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaCMS\Preview\ItemPreviewLink;

/**
 * A catalogue item on the content screen: no field of a page type, no
 * component to make, no proposal for search engines; the screen of the item
 * in the back office of the shop is its way back.
 */
final readonly class ItemScreenTarget implements ContentScreenTarget
{
    private const array ROUTES = [
        'generate' => 'admin.cms.catalogue.content.generate',
        'revise' => 'admin.cms.catalogue.content.revise',
        'section' => 'admin.cms.catalogue.content.section',
        'update' => 'admin.cms.catalogue.content.update',
        'remove' => 'admin.cms.catalogue.content.remove',
        'restore' => 'admin.cms.catalogue.content.restore',
        'move' => 'admin.cms.catalogue.content.move',
        'duplicate' => 'admin.cms.catalogue.content.duplicate',
        'add' => 'admin.cms.catalogue.content.add',
        'translate' => 'admin.cms.catalogue.content.translate',
        'publish' => 'admin.cms.catalogue.content.publish',
    ];

    public function __construct(
        private CatalogueItem $item,
        private Lang $lang,
        private UrlGeneratorInterface $urls,
        private AdminControllerSupport $support,
        private ItemContentRepository $contents,
        private ContentWriter $writer,
        private ContentTargets $contentTargets,
        private GenerationTargets $generationTargets,
        private ItemPreviewLink $previewLinks,
        private TranslationSources $translationSources,
    ) {
    }

    public function lang(): Lang
    {
        return $this->lang;
    }

    public function locale(): string
    {
        return $this->item->locale;
    }

    public function screenUrl(): string
    {
        return $this->urls->generate('admin.cms.catalogue.content.edit', $this->parameters());
    }

    public function subject(): ContentSubject
    {
        $item = $this->item;
        $status = ItemContentStatus::resolve($this->contents->find($item->type, $item->id, $item->locale));
        $parameters = $this->parameters();
        $routes = ['screen' => $this->screenUrl()];

        foreach (self::ROUTES as $action => $name) {
            $routes[$action] = $this->urls->generate($name, $parameters);
        }

        return new ContentSubject(
            target: $this->generationTarget(),
            locale: $item->locale,
            languageId: (int) $this->lang->getId(),
            statusLabel: $status->label(),
            statusBadgeClass: $status->badgeClass(),
            backUrl: $this->editUrl(),
            backLabel: $this->support->translate($item->type->backLabel()),
            draftPreviewUrl: $this->previewLinks->urlFor($item->type, $item->id, $item->locale),
            previewUrl: $item->url,
            document: $this->draftDocument(),
            publishHint: $this->support->translate('Publishing puts the draft online on the page of the item, under what the theme shows of it.'),
            routes: $routes,
            testId: 'cms-item-content',
            translationSources: $this->translationSources(),
            draftPagePreviewUrl: $this->previewLinks->pageUrlFor($item),
            previousDrafts: $this->writer->previousDrafts($this->contentTarget()),
        );
    }

    public function contentTarget(): ContentTarget
    {
        return $this->contentTargets->forItem($this->item);
    }

    public function generationTarget(): GenerationTarget
    {
        return $this->generationTargets->forItem($this->item);
    }

    public function draftDocument(): ?Document
    {
        return $this->contents->draftDocument($this->item->type, $this->item->id, $this->item->locale);
    }

    public function draftHtml(): ?string
    {
        return $this->contents->find($this->item->type, $this->item->id, $this->item->locale)?->getDraftHtml();
    }

    public function translationSources(): array
    {
        return $this->translationSources->forItem($this->item->type, $this->item->id, $this->item->locale);
    }

    public function translationSource(string $sourceLocale): ?Document
    {
        return $this->translationSources->itemDocument($this->item->type, $this->item->id, $sourceLocale);
    }

    public function prepare(GenerationRequest $generation): GenerationRequest
    {
        return $generation;
    }

    /** An item has no fields of a page type: whatever the answer writes besides the document is left out. */
    public function saveAlongside(?array $fields, array $seo): void
    {
    }

    public function nothingToChange(): string
    {
        return $this->support->translate('This content holds nothing to change yet: write it from a brief first.');
    }

    public function invalidWhenPublishing(): string
    {
        return $this->support->translate('The content was not published: it is no longer valid. Open it in the editor to see what to fix.');
    }

    public function online(): string
    {
        return $this->support->translate('The content is online on the page of the item.');
    }

    public function explainRefusal(\Throwable $refusal): ?array
    {
        return match (true) {
            $refusal instanceof ItemHeadingStructureException => [...$refusal->problems, $this->support->translate('The content was not published: fix the heading structure first.')],
            $refusal instanceof ItemAccessibilityException => [...$refusal->problems, $this->support->translate('The content was not published: fix the accessibility problems first.')],
            $refusal instanceof EmptyItemContentException => [$this->support->translate('This content is empty: write it before publishing it.')],
            default => null,
        };
    }

    /**
     * @return array<string, int|string|null>
     */
    private function parameters(): array
    {
        return ['type' => $this->item->type->value, 'id' => $this->item->id, EditLanguage::PARAMETER => $this->lang->getId()];
    }

    /**
     * The edit screen of the item in the back office of the shop, in the
     * language being edited.
     */
    private function editUrl(): string
    {
        $item = $this->item;
        $language = [EditLanguage::PARAMETER => $this->lang->getId()];

        return match ($item->type) {
            CatalogueItemType::Product => $this->urls->generate('admin.products.update', ['product_id' => $item->id] + $language),
            CatalogueItemType::Category => $this->urls->generate('admin.categories.update', ['category_id' => $item->id] + $language),
            CatalogueItemType::Content => $this->urls->generate('admin.content.update', ['content_id' => $item->id] + $language),
            CatalogueItemType::Folder => $this->urls->generate('admin.folders.update', ['folder_id' => $item->id] + $language),
            CatalogueItemType::Dealer => DealerStores::editUrl($this->urls, $item->id, $language),
        };
    }
}
