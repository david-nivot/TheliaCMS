# "Edit this page" button on the site (N16)

Status: decided and implemented (2026-11-09). Branch `feat/edit-page-button`, from `main` at `8c647fa` (2.19.0).

## Goal

An administrator signed in to the back office, allowed to change pages, who browses the site sees a button that opens the content screen of what they are reading, in the language being read: a CMS page (the 404 page included), or the document of a product, a category or any catalogue item printed on its own page. A visitor sees nothing, and no page that shows the button can reach a shared cache.

## What the code already gives (read on `8c647fa`, core on the test host)

- **The front office knows the administrator.** `SecurityContext::getAdminUser()` reads `thelia.admin_user` from the session of the main request (`core/lib/Thelia/Core/Security/SecurityContext.php:51`, `core/lib/Thelia/Core/HttpFoundation/Session/Session.php:266`), and the shop has one session cookie for both sides (`core/lib/Thelia/Config/Resources/packages/framework.php:26`, no cookie path or name set). The module already relies on it in the front office: `Vitrine/MaintenanceListener.php:73` lets a signed-in administrator through the closed site. The administrator is reloaded from the database once per request (`SecurityContext.php:64`), so a deleted administrator loses the button at once.
- **Which page is served.** `CmsPageViewListener` reads the id from the query (`cmspage_id`, `Page/CmsPageViewListener.php:62`), on `_view = cmspage`. The home page goes the same way: `CmsHomeViewListener` swaps `_view` and writes the id into the query (`Page/CmsHomeViewListener.php`), so "/" needs no case of its own. The page shown on a missing address sets `_view`, the id and `STAND_IN_PAGE_ATTRIBUTE` (`Vitrine/NotFoundPageListener.php:137-142`) and answers 404.
- **The preview uses the same layout.** `cms.page.preview` renders the draft with `CmsPageRenderer::render()` (`Preview/CmsPagePreviewController.php:42,62`), so through `base.html.twig` and its hooks, inside the iframe of the content screen, with the administrator's session. Its `_view` is not `cmspage`: filtering on `_view` keeps the button out of the preview.
- **Where to draw it.** Flexy calls `theme_hook('layout.body.bottom')` as the last thing of `<body>` (`templates/frontOffice/flexy/base.html.twig:140` on the host), and the module's `cmspage.html.twig` extends `base.html.twig` (`templates/frontOffice/flexy/cmspage.html.twig:14`). The module already draws through `ThemeHookInterface` (`Consent/ConsentThemeHook.php`, `Script/ScriptThemeHook.php`), registered by autoconfiguration.
- **Where it leads.** `admin.cms.pages.content.edit` (`Generation/Admin/ContentController.php:76`), with `edit_language_id` (`Page/Admin/EditLanguage.php`), which the screen takes and stores in the session. Passing the id of the language read on the site opens the right translation.
- **Who may open it.** The guard asks VIEW on `admin.cms.page` for everything under `/admin/cms/pages` (`Security/CmsAdminGuard.php:123-126`, `Security/CmsResources.php:29`); the writes ask UPDATE in their controllers.
- **Cache: already refused twice.** An administrator always arrives with the session cookie. The module's policy answers `private, no-store` to a request with a previous session (`Http/PageCachePolicy.php:79-81`). The shared cache of the core refuses any request carrying a cookie (`core/lib/Thelia/Core/SharedCache/SharedCachePolicy.php:127`). Forcing `no-store` when the button is drawn is a third guard that does not depend on how a session is detected, the way a form already forces it (`Http/PageCacheListener.php:96-105`).
- **CSP.** The policy of the module sets `img-src`, `font-src` and `frame-ancestors` only (`Http/ContentSecurityPolicy.php:58-62`): an inline `<style>` and a plain link pass, and the button needs no script.
- **Texts.** Front texts are translated in PHP in the `theliacms` domain and handed to the template (`Vitrine/MaintenanceListener.php:148`), in the language of the site.
- **Catalogue documents.** Five item types have a document (`Catalogue/CatalogueItemType.php:26-30`: product, category, content, folder, dealer), edited on `admin.cms.catalogue.content.edit` (`Generation/Admin/ContentController.php:77`), which the guard files under the page resource (`Security/CmsAdminGuard.php`, `'catalogue' => CmsResources::PAGE`). Every print goes through `ItemContentExtension::itemContent()` (`Twig/ItemContentExtension.php`): the theme hook on `product.content` and `category.bottom` (`Catalogue/ItemContentThemeHook.php`) and any theme calling `cms_item_content()`. That one method knows whether a document was printed, and whether it was the draft.
- **The draft of an item is previewed on the real page.** A signed token in the query of the product or category address makes `itemContent()` print the draft (`Preview/ItemDraftRequest.php:39-50`, which sets `SERVED_ATTRIBUTE`), inside the iframe of the content screen, with `_view = product` and the administrator's session. Recording only a printed published document keeps the button out of that preview.

## Design

1. **What the page shows.** `ItemContentExtension::itemContent()` records, on the main request, each item whose **published** document it printed (type and id), never a draft. A small request-scoped reader (`Catalogue/PrintedDocuments.php`) gives the hook the item of the page: the recorded item whose type is the `_view` of the request and whose id is `<view>_id` of the query. A document printed in a list (a category showing its products' documents) gets no button: only the item the page is about.
2. **Theme hook** `Front/EditButtonThemeHook.php`, `final readonly`, `supports('layout.body.bottom')`. It looks for a target first, read from the request, then asks UPDATE on `CmsResources::PAGE` (which only an administrator in the session holds):
   - the page id that `CmsPageViewListener` or `NotFoundPageListener` set before rendering (`TheliaCMS::SERVED_PAGE_ATTRIBUTE`; home on "/" and the 404 page included, never the preview): `admin.cms.pages.content.edit`;
   - otherwise the item of the page from step 1: `admin.cms.catalogue.content.edit` with its type and id.
   Both links carry `edit_language_id` = the current front language. `layout.body.bottom` is the last call of `<body>`, after the content that prints the documents; the browser check on Flexy proves the order (product and category pages).
3. **Template** `templates/front/edit-button.html.twig`: an `<a class="cms-edit-button">` with an inline `<style>` scoped to that class, fixed at the bottom right, above the theme (`z-index`), hidden in print, visible focus, contrast checked. No script.
4. **Cache**: the hook sets a request attribute (`PageCacheListener::PERSONAL_ATTRIBUTE`); `PageCacheListener` answers `keepPrivate()` when it is set, on any page (a product page has no tags of the module), whether the module's policy or the core's shared cache is in charge.
5. **Texts**: "Edit this page" and "Edit the content of this page", in `I18n/fr_FR.php` and `I18n/en_US.php`.
6. **Docs**: CHANGELOG (Added), README "Edit button" (the hook, a theme without `layout.body.bottom` shows no button, the proxy limit below), `docs/developing.md` (drawing for an administrator on the site).

## Tests (integration, each seen red by sabotage)

- Visitor on a CMS page: no button; headers as today.
- Administrator with UPDATE on the pages: the button, its `href` (page id, `edit_language_id` of the language read), `Cache-Control: private, no-store`.
- Administrator with VIEW only: no button.
- Home page on "/": the button points to `home_page_id`.
- Page shown on a missing address: the button points to that page; the answer stays a 404.
- Preview of a page draft (`cms.page.preview`) with an administrator signed in: no button.
- Product with a published document: the button points to `admin.cms.catalogue.content.edit` (product, id). Category: same, through `category.bottom`.
- Product without a document: no button.
- Product page opened with the draft token (item preview): no button.
- Category page printing the documents of other items: no button for them.
- Core shared cache on: a page with the button is not made public.

Browser proof at 1440 px on Flexy: visitor, then administrator; the button does not cover the cookie banner or the footer links at the bottom right.

## Limits (written in the README)

- Behind a proxy told to drop the session cookie on page addresses (what `PageCachePolicy` asks to cache anything), the administrator is not recognised and gets the cached page, without the button.
- A theme that does not call `layout.body.bottom` shows no button.
- The button opens the content screen, which edits the draft: the site keeps showing the published version until it is published again.

## Hypotheses

- The label is in the language of the site, not the language the administrator chose for the back office.
- The link opens in the same tab: the back office brings the administrator back with its own navigation.

## Decided (2026-11-09)

- **Form**: a floating button at the bottom right, one action, no shift of the page (a top bar would push or cover the sticky header of Flexy).
- **Right**: UPDATE on the CMS pages. An administrator who may only read them sees no button, though the guard would let them open the screen.
- **Page on a missing address**: the button, which edits that 404 page.
- **Catalogue documents**: in this lot. The button also opens the content screen of the item the page is about, when its published document is printed. The lot grows from S to M (steps 1 and the item tests).
