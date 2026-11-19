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
use TheliaCMS\Locale\ShopLanguages;
use TheliaCMS\Page\Admin\CmsPageAdminRepository;
use TheliaCMS\Partial\Catalogue\PickableCategories;
use TheliaCMS\Partial\Catalogue\PickableContents;
use TheliaCMS\Partial\Catalogue\PickableFolders;
use TheliaCMS\Partial\Catalogue\PickableItem;
use TheliaCMS\Partial\Catalogue\PickablePages;
use TheliaCMS\Partial\Catalogue\PickableProducts;
use TheliaCMS\TheliaCMS;

/**
 * The searched sources of the "Product" and "Category" blocks, of the page a
 * list of pages is set under, and of the target of a menu entry, too long to
 * list at once: `?q=` answers what matches the text an editor typed, `?id=`
 * the item already chosen, online or not. Both answer the list of
 * `{id, name}` every reference source answers, with `label` and `path` for a
 * list that shows where each item sits; a search also says in
 * `X-Total-Count` how many items it matched, so the editor can tell the list
 * is only the start of them.
 *
 * A block shows what it points at, so its products and categories are the
 * online ones; a menu may be prepared before its target goes online, so it
 * asks the offline ones too (`?offline=1`), named as such.
 *
 * Under `/admin/cms`, so the guard of the module checks the administrator.
 */
final readonly class CatalogueSourceController
{
    /** How many items matched the search, of which the answer lists the first. */
    public const string TOTAL_HEADER = 'X-Total-Count';

    public function __construct(
        private PickableProducts $products,
        private PickableCategories $categories,
        private PickablePages $pages,
        private PickableContents $contents,
        private PickableFolders $folders,
        private CmsPageAdminRepository $pageTree,
        private ShopLanguages $languages,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/cms/partials/sources/products', name: 'admin.cms.partials.sources.products', methods: ['GET'])]
    public function products(Request $request): Response
    {
        $withOffline = $request->query->getBoolean('offline');

        return $this->answer(
            $request,
            $this->products->find(...),
            fn (string $text, string $locale): array => $this->products->search($text, $locale, $withOffline),
            fn (string $text, string $locale): int => $this->products->total($text, $locale, $withOffline),
        );
    }

    #[Route('/admin/cms/partials/sources/categories', name: 'admin.cms.partials.sources.categories', methods: ['GET'])]
    public function categories(Request $request): Response
    {
        $withOffline = $request->query->getBoolean('offline');

        return $this->answer(
            $request,
            $this->categories->find(...),
            fn (string $text, string $locale): array => $this->categories->search($text, $locale, $withOffline),
            fn (string $text, string $locale): int => $this->categories->total($text, $locale, $withOffline),
        );
    }

    /**
     * The pages of the CMS, searched the same way: a site holds hundreds of
     * them. `?except=` leaves out a page and the pages under it, which cannot
     * become its parent.
     */
    #[Route('/admin/cms/partials/sources/pages', name: 'admin.cms.partials.sources.pages', methods: ['GET'])]
    public function pages(Request $request): Response
    {
        $except = $request->query->getInt('except');
        // Read once, and only for a search: `?id=` names the page held,
        // whatever its place.
        $branch = null;
        $leftOut = function () use ($except, &$branch): array {
            return $branch ??= $except > 0 ? [$except, ...array_values($this->pageTree->descendantIds($except))] : [];
        };

        return $this->answer(
            $request,
            $this->pages->find(...),
            fn (string $text, string $locale): array => $this->pages->search($text, $locale, $leftOut()),
            fn (string $text, string $locale): int => $this->pages->total($text, $locale, $leftOut()),
        );
    }

    /** The contents of the shop, online or not, which a menu may point at. */
    #[Route('/admin/cms/partials/sources/contents', name: 'admin.cms.partials.sources.contents', methods: ['GET'])]
    public function contents(Request $request): Response
    {
        return $this->answer($request, $this->contents->find(...), $this->contents->search(...), $this->contents->total(...));
    }

    /**
     * The folders of the shop, online or not, searched: beside the list of the
     * online ones a block of the theme reads whole.
     */
    #[Route('/admin/cms/partials/sources/folders/search', name: 'admin.cms.partials.sources.folders.search', methods: ['GET'])]
    public function folders(Request $request): Response
    {
        return $this->answer($request, $this->folders->find(...), $this->folders->search(...), $this->folders->total(...));
    }

    /**
     * Without a text, the first items: the list an editor opens before typing,
     * which the total tells is only the start when there are more.
     *
     * @param \Closure(int, string): ?PickableItem         $find
     * @param \Closure(string, string): list<PickableItem> $search
     * @param \Closure(string, string): int                $total
     */
    private function answer(Request $request, \Closure $find, \Closure $search, \Closure $total): Response
    {
        $locale = $this->locale($request);

        if ($request->query->has('id')) {
            $item = $find($request->query->getInt('id'), $locale);

            return new JsonResponse(null === $item ? [] : [$this->option($item, $locale)]);
        }

        $text = trim($request->query->getString('q'));
        $items = array_map(fn (PickableItem $item): array => $this->option($item, $locale), $search($text, $locale));

        return new JsonResponse($items, Response::HTTP_OK, [self::TOTAL_HEADER => (string) $total($text, $locale)]);
    }

    /**
     * The language the records are named in: the one of the screen asking
     * (`?locale=`, the language being edited, which the screen sees in its
     * other fields), if the shop has it; the one of the back office otherwise.
     */
    private function locale(Request $request): string
    {
        $asked = $request->query->getString('locale');

        return '' !== $asked && $this->languages->exists($asked) ? $asked : $request->getLocale();
    }

    /**
     * `name` is what a field shows once the item is chosen; a list shows
     * `label` with `path` under it, where the item sits. A source that has
     * neither leaves them out, and a reader that knows only `name` reads it.
     *
     * @return array{id: int, name: string, label?: string, path?: string}
     */
    private function option(PickableItem $item, string $locale): array
    {
        $message = match (true) {
            $item->binned => 'In the bin: %name%',
            !$item->online => 'Offline, not shown on the page: %name%',
            default => null,
        };
        $said = fn (string $name): string => null === $message
            ? $name
            : $this->translator->trans($message, ['%name%' => $name], TheliaCMS::DOMAIN_NAME, $locale);

        return array_filter([
            'id' => $item->id,
            'name' => $said($item->name),
            'label' => null === $item->label ? null : $said($item->label),
            'path' => $item->path,
        ], static fn (mixed $value): bool => null !== $value);
    }
}
