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

namespace TheliaCMS\Twig;

use Thelia\Domain\Ai\Twig\AiTransparencyExtension;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\Lang;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\ItemContentRepository;
use TheliaCMS\Catalogue\PrintedDocuments;
use TheliaCMS\Partial\PartialRenderer;
use TheliaCMS\Preview\ItemDraftRequest;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The document of a catalogue item, where the theme prints it:
 * `{{ cms_item_content('product', product.id) }}`.
 *
 * Markup, unlike the rest of the template API: the document is markup, the
 * one the editor wrote and the publication filtered. Its live content (a
 * form, a menu) is drawn when the page is served, as on a page of the CMS.
 */
final class ItemContentExtension extends AbstractExtension
{
    public function __construct(
        private readonly ItemContentRepository $contents,
        private readonly PartialRenderer $partials,
        private readonly LangService $langService,
        private readonly ItemDraftRequest $draftRequest,
        private readonly AiTransparencyExtension $transparency,
        private readonly PrintedDocuments $printed,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cms_item_content', $this->itemContent(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * The published document of the item in the language being served, or
     * an empty string: an unknown type, an item without a document, or one
     * whose document is offline all print nothing, so a theme calls it
     * without testing first.
     *
     * The page opened from the signed link to the draft of this item, in
     * this language, prints the draft instead: the editor reads it in the
     * page it will go online on.
     */
    public function itemContent(string $type, int|string|null $id, ?string $locale = null): string
    {
        $itemType = CatalogueItemType::tryFrom($type);
        $itemId = (int) $id;

        if (null === $itemType || $itemId <= 0) {
            return '';
        }

        $locale ??= $this->langService->getLang()?->getLocale() ?? Lang::getDefaultLanguage()->getLocale();
        $draft = $this->draftRequest->opensDraftOf($itemType, $itemId, $locale);
        $content = $this->contents->find($itemType, $itemId, $locale);
        $html = $draft ? $content?->getDraftHtml() : $this->contents->publishedHtml($itemType, $itemId, $locale);

        if (null === $html) {
            return '';
        }

        if (!$draft) {
            $this->printed->record($itemType, $itemId);
        }

        $html = (string) $this->partials->substitute($html, $locale);

        // A document the model wrote carries the mark of the core (AI Act,
        // article 50(2)) on an element of its own: the theme prints markup
        // it does not know, so the element is added here, and only for a
        // marked document. The mark is stored apart from the HTML, whose
        // sanitizer removes every `data-*` attribute it does not know.
        $attributes = $this->transparency->provenanceAttributes($draft ? $content?->getDraftProvenance() : $content?->getPublishedProvenance());

        return '' === $attributes ? $html : '<div class="cms-item-content" '.$attributes.'>'.$html.'</div>';
    }
}
