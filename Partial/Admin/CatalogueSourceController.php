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

namespace TheliaCMS\Partial\Admin;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Partial\Catalogue\PickableCategories;
use TheliaCMS\Partial\Catalogue\PickableItem;
use TheliaCMS\Partial\Catalogue\PickablePages;
use TheliaCMS\Partial\Catalogue\PickableProducts;
use TheliaCMS\TheliaCMS;

/**
 * The searched sources of the "Product" and "Category" blocks, and of the page
 * a list of pages is set under, too long to list at once: `?q=` answers what matches the text an editor typed, `?id=`
 * the item already chosen, online or not. Both answer the list of
 * `{id, name}` every reference source answers.
 *
 * Under `/admin/cms`, so the guard of the module checks the administrator.
 */
final readonly class CatalogueSourceController
{
    /** Below this, a search would answer half the catalogue. */
    private const int MIN_SEARCH_LENGTH = 2;

    public function __construct(
        private PickableProducts $products,
        private PickableCategories $categories,
        private PickablePages $pages,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/cms/partials/sources/products', name: 'admin.cms.partials.sources.products', methods: ['GET'])]
    public function products(Request $request): Response
    {
        return $this->answer($request, $this->products->find(...), $this->products->search(...));
    }

    #[Route('/admin/cms/partials/sources/categories', name: 'admin.cms.partials.sources.categories', methods: ['GET'])]
    public function categories(Request $request): Response
    {
        return $this->answer($request, $this->categories->find(...), $this->categories->search(...));
    }

    /** The pages of the CMS, searched the same way: a site holds hundreds of them. */
    #[Route('/admin/cms/partials/sources/pages', name: 'admin.cms.partials.sources.pages', methods: ['GET'])]
    public function pages(Request $request): Response
    {
        return $this->answer($request, $this->pages->find(...), $this->pages->search(...));
    }

    /**
     * @param \Closure(int, string): ?PickableItem         $find
     * @param \Closure(string, string): list<PickableItem> $search
     */
    private function answer(Request $request, \Closure $find, \Closure $search): Response
    {
        $locale = $request->getLocale();

        if ($request->query->has('id')) {
            $item = $find($request->query->getInt('id'), $locale);

            return new JsonResponse(null === $item ? [] : [$this->option($item, $locale)]);
        }

        $text = trim($request->query->getString('q'));

        if (mb_strlen($text) < self::MIN_SEARCH_LENGTH) {
            return new JsonResponse([]);
        }

        return new JsonResponse(array_map(
            fn (PickableItem $item): array => $this->option($item, $locale),
            $search($text, $locale),
        ));
    }

    /**
     * @return array{id: int, name: string}
     */
    private function option(PickableItem $item, string $locale): array
    {
        return [
            'id' => $item->id,
            'name' => $item->online
                ? $item->name
                : $this->translator->trans('Offline, not shown on the page: %name%', ['%name%' => $item->name], TheliaCMS::DOMAIN_NAME, $locale),
        ];
    }
}
