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

namespace TheliaCMS\Preview;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use TheliaCMS\Catalogue\CatalogueItem;
use TheliaCMS\Catalogue\CatalogueItems;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\ItemContentRepository;
use TheliaCMS\Front\ThemeTemplateRenderer;
use TheliaCMS\Partial\PartialRenderer;

/**
 * Shows the draft of the document of a catalogue item to whoever holds a
 * signed link, on a page of its own: the document alone, in the layout of
 * the theme. The page of the item shows the draft in its place from a link of
 * its own (ItemPreviewLink::pageUrlFor()), and the published document
 * otherwise.
 */
final readonly class ItemPreviewController
{
    /** What a theme ships to draw the preview its own way. */
    public const string THEME_TEMPLATE = 'cms-item-preview';

    public const string MODULE_TEMPLATE = '@TheliaCMSModule/front/cms-item-preview.html.twig';

    public function __construct(
        private ItemPreviewLink $links,
        private CatalogueItems $items,
        private ItemContentRepository $contents,
        private PartialRenderer $partials,
        private ThemeTemplateRenderer $templates,
    ) {
    }

    #[Route(
        '/cms-preview/item/{type}/{id}/{locale}/{expires}/{signature}',
        name: 'cms.item.preview',
        requirements: ['type' => CatalogueItemType::ROUTE_REQUIREMENT, 'id' => '\d+', 'locale' => '[a-z]{2}_[A-Z]{2}', 'expires' => '\d+', 'signature' => '[a-f0-9]{64}'],
        methods: ['GET'],
    )]
    public function __invoke(string $type, int $id, string $locale, int $expires, string $signature): Response
    {
        $itemType = CatalogueItemType::from($type);

        if (!$this->links->isValid($itemType, $id, $locale, $expires, $signature)) {
            throw new NotFoundHttpException();
        }

        $item = $this->items->find($itemType, $id, $locale);
        $content = $this->contents->find($itemType, $id, $locale);
        $html = $content?->getDraftHtml();

        if (!$item instanceof CatalogueItem || null === $html) {
            throw new NotFoundHttpException();
        }

        $response = new Response($this->templates->render(self::THEME_TEMPLATE, self::MODULE_TEMPLATE, [
            'cms_item' => $item,
            'cms_item_html' => (string) $this->partials->substitute($html, $locale),
            'cms_item_provenance' => $content?->getDraftProvenance(),
        ]));

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }
}
