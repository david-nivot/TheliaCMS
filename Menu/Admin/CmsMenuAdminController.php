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

namespace TheliaCMS\Menu\Admin;

use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\Lang;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Menu\MenuTargetType;
use TheliaCMS\Model\CmsMenu;
use TheliaCMS\Model\CmsMenuItem;
use TheliaCMS\Model\CmsMenuQuery;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaCMS\Security\CmsResources;
use Twig\Environment;

/**
 * Menu back office: the list of menus, and one editing screen per menu holding
 * its tree and the form of a single entry.
 *
 * `{id}` is always the row being edited: the shared menu of a code, or the
 * menu of one language. The screen shows the row of the language being edited,
 * so a GET on another row of the same code is redirected to the right one
 * (that is what the language switcher and the list rely on). Writes act on the
 * row of the URL, and an entry that is not in that row is a 404.
 *
 * Like the page controller, this one resolves input, delegates every write to
 * CmsMenuWriter and redirects.
 */
#[Route('/admin/cms/menus', name: 'admin.cms.menus.')]
final readonly class CmsMenuAdminController
{
    private const string LIST_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/menus/list.html.twig';
    private const string CONFIRM_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/menus/confirm.html.twig';
    private const string EDIT_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/menus/edit.html.twig';

    /** What the `source` of a realignment says for the shared menu: a locale is never this. */
    private const string SHARED_SOURCE = 'shared';

    public function __construct(
        private Environment $twig,
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
        private AdminControllerSupport $support,
        private CmsMenuRepository $menus,
        private CmsMenuWriter $writer,
        private MenuTargetChoices $choices,
        private EditLanguage $languages,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $lang = $this->languages->resolve($request);

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'menus' => $this->menus->menus($lang->getLocale()),
            'languages' => $this->menus->languages(),
            'edit_locale' => $lang->getLocale(),
            'edit_language_id' => $lang->getId(),
        ]));
    }

    #[Route('/new', name: 'create', methods: ['GET', 'POST'], priority: 1)]
    public function create(Request $request): Response
    {
        $this->denyUnless(AccessManager::CREATE);

        $lang = $this->languages->resolve($request);
        $form = $this->forms->create(CmsMenuType::class, ['code' => null, 'title' => null, 'visible' => true], [
            'action' => $this->urls->generate('admin.cms.menus.create', [EditLanguage::PARAMETER => $lang->getId()]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $menu = new CmsMenu();

            if ($this->saveMenu($form, $menu, $lang)) {
                return $this->backToEdit((int) $menu->getId(), $lang);
            }
        }

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'menu' => null,
            'shared_menu' => null,
            'is_detached' => false,
            'languages' => [],
            'realign_sources' => [],
            'menu_form' => $form->createView(),
            'rows' => [],
            'item_form' => null,
            'edited_item' => null,
            'edit_locale' => $lang->getLocale(),
            'edit_language_id' => $lang->getId(),
            'may_write' => true,
            'may_replace' => false,
            'place_url_template' => null,
            'assets_version' => null,
        ]));
    }

    #[Route('/{id}', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $menu = $this->menuOrFail($id);
        $lang = $this->languages->resolve($request);

        if ($request->isMethod('GET') && ($served = $this->servedRow($menu, $lang)) !== $id) {
            return $this->backToEdit($served, $lang);
        }

        $form = $this->menuForm($menu, $lang);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->saveMenu($form, $menu, $lang)) {
            return $this->backToEdit($id, $lang);
        }

        return $this->screen($menu, $lang, $form);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $this->denyUnless(AccessManager::DELETE);

        $menu = $this->menuOrFail($id);

        if (CmsMenuQuery::SHARED !== $menu->getMenuLocale()) {
            // A language leaves its own menu through "back to the shared menu".
            throw new NotFoundHttpException();
        }

        $this->writer->deleteMenu($menu);

        return $this->support->redirectInLanguage('admin.cms.menus.list', $this->languages->resolve($request)->getId());
    }

    /**
     * Adds an entry, or saves the one being edited. Both live on the menu screen,
     * so both come back to it.
     */
    #[Route('/{id}/entries/{itemId}', name: 'entry_save', requirements: ['id' => '\d+', 'itemId' => '\d+|new'], methods: ['GET', 'POST'])]
    public function saveEntry(Request $request, int $id, string $itemId): Response
    {
        $menu = $this->menuOrFail($id);
        $lang = $this->languages->resolve($request);

        if ($request->isMethod('GET') && ($served = $this->servedRow($menu, $lang)) !== $id) {
            return $this->backToEdit($served, $lang);
        }

        $item = 'new' === $itemId ? null : $this->itemOrFail($menu, (int) $itemId);

        $form = $this->itemForm($menu, $lang, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->denyUnless(null === $item ? AccessManager::CREATE : AccessManager::UPDATE);

            try {
                $this->writer->saveItem($menu, $item, $lang->getLocale(), $this->itemData($form));

                return $this->backToEdit($id, $lang);
            } catch (\DomainException $exception) {
                $form->addError(new FormError($this->support->translate($exception->getMessage())));
            }
        }

        return $this->screen($menu, $lang, null, $form, $item);
    }

    #[Route('/{id}/entries/{itemId}/delete', name: 'entry_delete', requirements: ['id' => '\d+', 'itemId' => '\d+'], methods: ['POST'])]
    public function deleteEntry(Request $request, int $id, int $itemId): Response
    {
        $this->denyUnless(AccessManager::DELETE);

        $menu = $this->menuOrFail($id);
        $this->writer->deleteItem($menu, $this->itemOrFail($menu, $itemId));

        return $this->backToEdit($id, $this->languages->resolve($request));
    }

    #[Route('/{id}/entries/{itemId}/move/{direction}', name: 'entry_move', requirements: ['id' => '\d+', 'itemId' => '\d+', 'direction' => 'up|down'], methods: ['POST'])]
    public function moveEntry(Request $request, int $id, int $itemId, string $direction): Response
    {
        $this->denyUnless(AccessManager::UPDATE);

        $menu = $this->menuOrFail($id);
        $this->writer->move($menu, $this->itemOrFail($menu, $itemId), 'up' === $direction ? -1 : 1);

        return $this->backToEdit($id, $this->languages->resolve($request));
    }

    /**
     * Where a dragged row landed. Answers JSON because the editor calls it in
     * the background; the screen is reloaded by the browser afterwards, so the
     * server stays the only source of truth for the tree.
     */
    #[Route('/{id}/entries/{itemId}/place', name: 'entry_place', requirements: ['id' => '\d+', 'itemId' => '\d+'], methods: ['POST'])]
    public function placeEntry(Request $request, int $id, int $itemId): JsonResponse
    {
        $this->denyUnless(AccessManager::UPDATE);

        $menu = $this->menuOrFail($id);
        $item = $this->itemOrFail($menu, $itemId);

        try {
            $this->writer->place(
                $menu,
                $item,
                $request->request->getInt('parent'),
                $request->request->getInt('position', 1),
            );
        } catch (\DomainException $exception) {
            return new JsonResponse(['error' => $this->support->translate($exception->getMessage())], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Gives the language being edited a menu of its own, a copy of the shared one.
     */
    #[Route('/{id}/detach', name: 'detach', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function detach(Request $request, int $id): Response
    {
        $this->denyUnless(AccessManager::UPDATE);

        $menu = $this->menuOrFail($id);
        $lang = $this->languages->resolve($request);
        $target = $id;

        try {
            if (CmsMenuQuery::SHARED !== $menu->getMenuLocale()) {
                throw new \DomainException('This language already has a menu of its own.');
            }

            $target = (int) $this->writer->detach((string) $menu->getCode(), $lang->getLocale())->getId();
            $this->flash($request, 'success', 'This language has a menu of its own now, a copy of the shared one.');
        } catch (\DomainException $exception) {
            $this->flash($request, 'danger', $exception->getMessage());
        }

        return $this->backToEdit($target, $lang);
    }

    /**
     * Asks for confirmation, with what the realignment would replace, then
     * does it. `source` is a locale, or `shared`.
     */
    #[Route('/{id}/realign', name: 'realign', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function realign(Request $request, int $id): Response
    {
        $this->denyUnlessMayReplace();

        $menu = $this->menuOrFail($id);
        $lang = $this->languages->resolve($request);
        $code = (string) $menu->getCode();
        $targetLocale = (string) $menu->getMenuLocale();
        $source = $request->isMethod('POST') ? $request->request->getString('source') : $request->query->getString('source');
        $sourceLocale = self::SHARED_SOURCE === $source ? CmsMenuQuery::SHARED : $source;

        if ($request->isMethod('POST')) {
            try {
                if ('' === $source) {
                    throw new \DomainException('The menu to copy from does not exist.');
                }

                $this->writer->realign($code, $targetLocale, $sourceLocale);
                $this->flash($request, 'success', 'The menu was realigned.');
            } catch (\DomainException $exception) {
                $this->flash($request, 'danger', $exception->getMessage());
            }

            return $this->backToEdit($id, $lang);
        }

        try {
            if ('' === $source) {
                throw new \DomainException('The menu to copy from does not exist.');
            }

            $counts = $this->menus->realignCounts($code, $targetLocale, $sourceLocale);

            if ($targetLocale === $sourceLocale) {
                throw new \DomainException('A menu cannot be realigned on itself.');
            }
        } catch (\DomainException $exception) {
            $this->flash($request, 'danger', $exception->getMessage());

            return $this->backToEdit($id, $lang);
        }

        return $this->confirmation($menu, $lang, 'realign', [
            'target_name' => $this->menuName($targetLocale),
            'source_name' => $this->menuName($sourceLocale),
            'target_entries' => $counts['target'],
            'source_entries' => $counts['source'],
            'source' => '' === $sourceLocale ? self::SHARED_SOURCE : $sourceLocale,
        ]);
    }

    /**
     * Sends the language of a menu of its own back to the shared one, after a
     * confirmation: the entries of its menu are deleted.
     */
    #[Route('/{id}/reattach', name: 'reattach', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function reattach(Request $request, int $id): Response
    {
        $this->denyUnlessMayReplace();

        $menu = $this->menuOrFail($id);
        $lang = $this->languages->resolve($request);
        $locale = (string) $menu->getMenuLocale();
        $shared = $this->menus->findByCode((string) $menu->getCode());
        $back = null === $shared ? $id : (int) $shared->getId();

        if ($request->isMethod('POST')) {
            try {
                $this->writer->reattach((string) $menu->getCode(), $locale);
                $this->flash($request, 'success', 'This language reads the shared menu again.');
            } catch (\DomainException $exception) {
                $this->flash($request, 'danger', $exception->getMessage());

                return $this->backToEdit($id, $lang);
            }

            return $this->backToEdit($back, $lang);
        }

        if (CmsMenuQuery::SHARED === $locale) {
            $this->flash($request, 'danger', 'The shared menu cannot be sent back to itself.');

            return $this->backToEdit($id, $lang);
        }

        return $this->confirmation($menu, $lang, 'reattach', [
            'target_name' => $this->menuName($locale),
            'target_entries' => $this->menus->entryCount($menu),
        ]);
    }

    private function screen(
        CmsMenu $menu,
        Lang $lang,
        ?FormInterface $menuForm = null,
        ?FormInterface $itemForm = null,
        ?CmsMenuItem $editedItem = null,
    ): Response {
        $locale = $lang->getLocale();
        $code = (string) $menu->getCode();
        $menuLocale = (string) $menu->getMenuLocale();
        $shared = CmsMenuQuery::SHARED === $menuLocale ? $menu : $this->menus->findByCode($code);

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'menu' => $menu,
            'shared_menu' => $shared,
            'is_detached' => CmsMenuQuery::SHARED !== $menuLocale,
            'languages' => $this->menus->languages(),
            'realign_sources' => $this->menus->realignSources($code, $menuLocale),
            'menu_form' => ($menuForm ?? $this->menuForm($menu, $lang))->createView(),
            'rows' => $this->menus->rows($menu, $locale),
            'item_form' => ($itemForm ?? $this->itemForm($menu, $lang, null))->createView(),
            'edited_item' => $editedItem,
            'edit_locale' => $locale,
            'edit_language_id' => $lang->getId(),
            'may_write' => $this->support->isGranted(CmsResources::MENU, AccessManager::UPDATE),
            'may_replace' => $this->mayReplace(),
            'place_url_template' => $this->placeUrlTemplate($menu, $lang),
            'assets_version' => $this->assetsVersion(),
        ]));
    }

    private function menuForm(CmsMenu $menu, Lang $lang): FormInterface
    {
        return $this->forms->create(CmsMenuType::class, [
            'code' => $menu->getCode(),
            'title' => $menu->setLocale($lang->getLocale())->getTitle(),
            'visible' => 1 === (int) $menu->getVisible(),
        ], [
            'code_locked' => CmsMenuQuery::SHARED !== $menu->getMenuLocale(),
            // Explicit, because this form is also rendered on the screen that
            // edits a single entry, whose URL it must not post to.
            'action' => $this->urls->generate('admin.cms.menus.edit', [
                'id' => $menu->getId(),
                EditLanguage::PARAMETER => $lang->getId(),
            ]),
        ]);
    }

    /**
     * The address the editor posts a dragged row to, with a placeholder where
     * the entry identifier goes.
     *
     * The route requires digits, so the URL is generated for a valid identifier
     * and the segment is then swapped: generating it with `{id}` in place would
     * simply fail to match.
     */
    private function placeUrlTemplate(CmsMenu $menu, Lang $lang): string
    {
        $generated = $this->urls->generate('admin.cms.menus.entry_place', [
            'id' => $menu->getId(),
            'itemId' => 0,
            EditLanguage::PARAMETER => $lang->getId(),
        ]);

        return str_replace('/entries/0/place', '/entries/{id}/place', $generated);
    }

    /**
     * Changes whenever the editor script does: `module_asset()` returns a stable
     * URL, so without it a browser keeps running the copy it already holds.
     */
    private function assetsVersion(): string
    {
        $script = __DIR__.'/../../templates/backOffice/default-twig/assets/menu-editor.js';

        return substr(hash('xxh3', (string) @filemtime($script)), 0, 12);
    }

    private function itemForm(CmsMenu $menu, Lang $lang, ?CmsMenuItem $item): FormInterface
    {
        $locale = $lang->getLocale();
        $type = MenuTargetType::fromStorage($item?->getTargetType());
        $targetId = null === $item ? null : (int) $item->getTargetId();

        return $this->forms->create(CmsMenuItemType::class, [
            'targetType' => null === $item ? MenuTargetType::Page->value : $type->value,
            'pageId' => MenuTargetType::Page === $type ? $targetId : null,
            'contentId' => MenuTargetType::Content === $type ? $targetId : null,
            'folderId' => MenuTargetType::Folder === $type ? $targetId : null,
            'categoryId' => MenuTargetType::Category === $type ? $targetId : null,
            'productId' => MenuTargetType::Product === $type ? $targetId : null,
            'url' => $item?->getUrl(),
            'label' => null === $item ? null : $item->setLocale($locale)->getLabel(),
            'parent' => (int) $item?->getParent(),
            'openNewTab' => 1 === $item?->getOpenNewTab(),
            'visible' => null === $item || 1 === (int) $item->getVisible(),
        ], [
            'page_choices' => $this->choices->pages($locale),
            'content_choices' => $this->choices->contents($locale),
            'folder_choices' => $this->choices->folders($locale),
            'category_choices' => $this->choices->categories($locale),
            'product_choices' => $this->choices->products($locale),
            'parent_choices' => $this->menus->parentChoices($menu, $locale, null === $item ? null : (int) $item->getId()),
            'action' => $this->urls->generate('admin.cms.menus.entry_save', [
                'id' => $menu->getId(),
                'itemId' => $item?->getId() ?? 'new',
                EditLanguage::PARAMETER => $lang->getId(),
            ]),
        ]);
    }

    private function itemData(FormInterface $form): MenuItemData
    {
        $data = $form->getData();
        $type = MenuTargetType::fromStorage($data['targetType']);

        $targetId = match ($type) {
            MenuTargetType::Page => $data['pageId'],
            MenuTargetType::Content => $data['contentId'],
            MenuTargetType::Folder => $data['folderId'],
            MenuTargetType::Category => $data['categoryId'],
            MenuTargetType::Product => $data['productId'],
            default => null,
        };

        $label = trim((string) $data['label']);

        return new MenuItemData(
            type: $type,
            targetId: null === $targetId ? null : (int) $targetId,
            url: $data['url'],
            label: '' === $label ? null : $label,
            parent: (int) $data['parent'],
            openNewTab: (bool) $data['openNewTab'],
            visible: (bool) $data['visible'],
        );
    }

    private function saveMenu(FormInterface $form, CmsMenu $menu, Lang $lang): bool
    {
        $this->denyUnless($menu->isNew() ? AccessManager::CREATE : AccessManager::UPDATE);

        $data = $form->getData();

        try {
            $this->writer->saveMenu($menu, $lang->getLocale(), (string) $data['code'], (string) $data['title'], (bool) $data['visible']);
        } catch (\DomainException $exception) {
            $form->get('code')->addError(new FormError($this->support->translate($exception->getMessage())));

            return false;
        }

        return true;
    }

    private function menuOrFail(int $id): CmsMenu
    {
        $menu = $this->menus->find($id);

        if (!$menu instanceof CmsMenu) {
            throw new NotFoundHttpException();
        }

        return $menu;
    }

    private function itemOrFail(CmsMenu $menu, int $itemId): CmsMenuItem
    {
        $item = $this->menus->findItem($menu, $itemId);

        if (!$item instanceof CmsMenuItem) {
            throw new NotFoundHttpException();
        }

        return $item;
    }

    /**
     * The row of the code that serves the language: its own menu when it has
     * one, the shared menu otherwise.
     */
    private function servedRow(CmsMenu $menu, Lang $lang): int
    {
        $own = $this->menus->findForLocale((string) $menu->getCode(), $lang->getLocale());

        return (int) ($own ?? $this->menus->findByCode((string) $menu->getCode()) ?? $menu)->getId();
    }

    private function menuName(string $menuLocale): string
    {
        if (CmsMenuQuery::SHARED === $menuLocale) {
            return $this->support->translate('Shared menu');
        }

        return (string) ($this->menus->languages()[$menuLocale] ?? null)?->getTitle() ?: $menuLocale;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function confirmation(CmsMenu $menu, Lang $lang, string $action, array $context): Response
    {
        return new Response($this->twig->render(self::CONFIRM_TEMPLATE, [
            'menu' => $menu,
            'action' => $action,
            'edit_locale' => $lang->getLocale(),
            'edit_language_id' => $lang->getId(),
            ...$context,
        ]));
    }

    private function flash(Request $request, string $type, string $message): void
    {
        $this->support->flash($request, $type, $this->support->translate($message));
    }

    /**
     * Realigning and going back to the shared menu destroy entries and menus
     * the way deleting them does: both rights are needed.
     */
    private function mayReplace(): bool
    {
        return $this->support->isGranted(CmsResources::MENU, AccessManager::UPDATE)
            && $this->support->isGranted(CmsResources::MENU, AccessManager::DELETE);
    }

    private function denyUnlessMayReplace(): void
    {
        if (!$this->mayReplace()) {
            throw new AccessDeniedHttpException($this->support->translate('You are not allowed to change menus.'));
        }
    }

    private function denyUnless(string $access): void
    {
        $this->support->denyUnless(CmsResources::MENU, $access, 'You are not allowed to change menus.');
    }

    private function backToEdit(int $id, Lang $lang): RedirectResponse
    {
        return $this->support->redirectInLanguage('admin.cms.menus.edit', $lang->getId(), ['id' => $id]);
    }
}
