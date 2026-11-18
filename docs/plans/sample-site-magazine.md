# Plan: a richer magazine sample site, and a showcase site that sells nothing

Status: lot 1 merged on 2026-11-18 (#210; a `scope` prop was added: right
under the page or the whole branch, "Actualités" holding its news under a page
per year). Lot 2 in progress, lot 3 not started. Decisions D1 to D3 taken by
the maintainer on 2026-11-18: D1 (a), D2 revised to (d) the same day, D3 (a).

## What is asked

1. The listing pages of the magazine (Actualités, each year, Brèves, the
   rubrics of Magazine and Guides) show the articles filed under them.
2. The articles carry more than one block of text: an image, several kinds of
   sections, the components of the site.
3. `Tests/Browser/sample-site/seed.php` makes all of it on every run.
4. In showcase mode, the site shows no currency selector, no cart and no price,
   anywhere.

## What is there today

- The "Pages of a type" block (`Partial/Definition/PageListPartial.php`,
  stored as `Partial:cms-page-list`) lists the pages **of a page template**,
  never the pages under a page. Its props: `type`, `title`, heading, `sort`,
  `field`, `order`, `limit` (1 to 48). A block is rendered from its props and
  the language only (`PartialDefinitionInterface::context(array $props,
  string $locale)`): it does not know the page that shows it.
- `PublishedPageRepository::ofType()` is the only query behind it.
- The seed writes one `cms:richtext` section per page (`Document::richText`),
  80 % of the pages have a card image, none has a section image.
- Sections a page can hold: `cms:heading`, `cms:richtext`, `cms:image`,
  `cms:columns`, `cms:highlights`, the blocks `Partial:cms-page-list` and
  `Partial:cms-form`, 14 components of Flexy 1.3.2 (`config/theliacms.yaml`:
  ArticleCard, VideoPlayer, ReviewCard, CrossSelling…) and the components of
  the site. The dev database holds 9 of those (Quote, ImageText, KeyFigures,
  Steps, Carousel, Faq, BikeSpecSheet, RouteCard, RideCalendar); the seed makes
  none.
- `SiteImporter` (`ImportExport/`) brings components in from a site export
  (`ComponentsStep`).
- Showcase mode today: `/cart`, `/order`, `/checkout` answer 404
  (`Vitrine/ShopRoutesListener.php`), search and the dashboard change. Flexy
  draws `Organisms:CurrencySelect` and `Organisms:CartButton` in
  `Organisms/HeaderNav/Base.html.twig` with no condition and no hook around
  them. Prices are printed by 15 components of Flexy (ProductCard, its Search
  variant, PseSelector with the add to cart button, the price RangeSlider of
  the filters, Summary, CartItem, QuickOrderTable…). SEOne prints the price in
  the structured data of a product (`Service/ProductStructuredData.php`).
- Flexy offers no switch for any of this; the only conditional of the header
  is `hasCustomerAccount()` (`src/Twig/FlexyBundleExtension.php`).

## Decisions taken (2026-11-18)

- **D1. Listing by parent.** (a, recommended) a `parent` prop on the existing
  "Pages of a type" block, with the page picker the menus already use, and an
  "any template" choice for `type`; the block keeps one query and one cache
  key per set of props. (b) a new block "Pages under this page" that reads the
  page showing it: needs the page in the context of every block, a change of
  `PartialDefinitionInterface`, and a cache per page.
- **D2. How Flexy learns the shop sells nothing.** (a, recommended) a
  configuration variable of the core (`shop_sells_online`, 1 by default) that
  Flexy reads through one Twig function of its extension, and that TheliaCMS
  writes when the mode changes; no package depends on another. (b) Flexy calls
  a function of TheliaCMS: Flexy would break on a site without the module.
  (c) TheliaCMS overrides the templates of Flexy: breaks at each release of
  the theme. CSS alone is out: the prices stay in the HTML and the
  structured data.
- **D2 revised (2026-11-18): (d) the variable the core already has.** The core
  `main` (3.4 to come, commit 1a7ed17b0 of 2026-11-12, in no 3.3 tag) stores
  `price_display_mode` (`visible`, `logged_in`, `on_request`); in `on_request`
  its front API leaves the prices out and the cart refuses the add, Flexy
  `main` prints "Request the price", SEOne 1.7.0 and later drop the `offers`.
  So: TheliaCMS writes `price_display_mode = on_request` in showcase mode and
  puts the former value back when the mode leaves showcase; Flexy `1.3` reads
  that same variable (no price, no add to cart, no price filter or sort, no
  currency, no cart); Flexy `main` only leaves the currency selector and the
  cart button out of the header; SEOne needs nothing on the core 3.4 (a
  fallback reading the variable on the core 3.3 only if wanted).
  `shop_sells_online` is dropped.
- **D3. Language of the magazine.** (a, recommended) the seed makes French the
  default language of the site, so the front reads "Magazine" rather than
  "EN · Magazine". (b) keep English by default.

## Lots

Each lot is one PR. Lot 3 needs lot 1 merged; lots 1 and 2 are independent.

### Lot 1: the block lists the pages under a page (module)

- `PageListPartial`: `parent` (a CMS page, optional) and `type` taking
  "any template"; `PublishedPageRepository` gets the query of the children of
  a page (online, published in the language, not models, not in the bin),
  with the same sorts and limit.
- The cache of the block is emptied when a child of the parent is published,
  unpublished, moved or deleted (check what `PartialCache` listens to today).
- The generator is told about the new prop (catalogue description of the
  block); the form of the block offers the picker.
- Tests: integration (children only, order, limit, other language left out,
  bin left out, cache emptied on publish), screen test of the form.
- CHANGELOG (Added), developing.md if a trap shows.

Acceptance (dev): a page holding the block with `parent` = itself lists its
online children, newest first, and the list changes at the next publication
of a child. Acceptance (maintainer): on "Actualités", the block shows the news
with their image and title, and a news published from the back office appears
in it without touching the listing page.

### Lot 2: a showcase site shows no price, no cart, no currency (core config, Flexy, module, SEOne)

- Flexy, branch `1.3` (core 3.3; `main` waits for the core 3.4): a
  `sellsOnline()` Twig function reading the core variable; `HeaderNav` leaves
  out `CurrencySelect` and `CartButton`, the product components leave out the
  price, the add to cart button and the quantity, the filters leave out the
  price range and the sort by price, when it answers false. Version 1.3.3:
  PR of version, tag on its merge commit.
- TheliaCMS: the settings screen writes `shop_sells_online` (0 in showcase,
  1 otherwise); the module update writes it once from the stored mode, so a
  site already in showcase mode gets it.
- SEOne: no `offers` in the structured data of a product when the variable is
  0 (PR with its version bump, as its CI requires).
- Tests: Flexy suite (each component with the variable at 0 and 1), module
  integration test of the settings screen, SEOne unit test.

Acceptance: in showcase mode, the home page, a category, a product, the search
results and the mini cart area show no price, no currency selector and no
cart, and the page source of a product holds no `offers`; switching back to
"A shop with content pages" brings all of them back.

### Lot 3: the seed makes a magazine worth reading (module, test data)

- Components: a versioned export of the 9 components of the site
  (`Tests/Browser/sample-site/components.json`, made with `thelia_cms:export`),
  brought in by the seed through `SiteImporter` before the pages.
- Listing pages: Magazine, its rubrics, Actualités, each year, Brèves, Guides,
  Itinéraires and its four regions get an introduction and a "Pages of a
  type" block with `parent` = the page (limit 48; Brèves holds 30).
- Articles, by kind, built from a handful of recipes (no extra draw of the
  random generator, so the tree, the dates and the statuses stay as today):
  - gear test: heading, chapo, `cms:image`, text, BikeSpecSheet, verdict;
  - advice: heading, text, Steps, Faq;
  - portrait: ImageText, Quote, text;
  - route: RouteCard, Carousel of library images, text;
  - news (event): chapo, `cms:image`, text, RideCalendar on the autumn
    programme; short news: one paragraph and an image one in two.
  The images are the 20 of the library the seed already makes.
- Home page: the magazine's own (latest news through the block on
  Actualités, the three rubrics in `cms:columns`), in place of the shop
  welcome of the module.
- Site: French by default (D3), modules Page and TheliaBlocks deactivated.
- docs/developing.md ("A sample site to check the page list on") rewritten.

Acceptance (dev): `seed.php` on a fresh database ends without error and the
summary line counts the components imported; every published page renders
(`thelia_cms:render-published --all --dry-run` without error). Acceptance
(maintainer): at 1440 and 375 px, Actualités lists its news with images, a gear
test shows its spec sheet, a route its card and carousel, the home page is the
magazine's, the front reads in French.

## Estimate

Hours of development, review and tests included.

| Lot | Hours |
|---|---|
| 1. Block by parent | 3 h 30 |
| 2. Showcase sells nothing (Flexy 2 h 30, module 1 h, SEOne 1 h) | 4 h 30 |
| 3. Richer seed | 4 h |
| Contingency (lot 2 crosses three repositories and two release paths) | 2 h |
| **Total** | **14 h** |

## Unclear points

- The cache of the block: whether `PartialCache` is emptied by a page
  publication today, or only by its TTL.
- Flexy: which components really print a price on a showcase site (the 15
  found by `grep` include the checkout ones, unreachable there); the list is
  settled when lot 2 starts.
- The core variable: a name the core may later take for its own catalogue
  mode; to ask the core maintainers before the Flexy PR.
- The components of the site carry props written for the bench; some recipes
  may need a prop the component does not have (then a recipe changes, not the
  component).

## Resuming (written 2026-11-18, end of lot 1)

### State checked on 2026-11-18

- Module clone `local/modules/TheliaCMS`: branch `feat/page-list-by-parent`
  from `origin/main` 964e8bf, lot 1 **committed locally** as 4ed2c9e (not
  pushed, no PR). Lot 1 files:
  `CHANGELOG.md`, `Generation/Admin/SectionChoices.php`, `I18n/en_US.php`,
  `I18n/fr_FR.php`, `Page/PublishedPageRepository.php`,
  `Partial/Admin/CatalogueSourceController.php`,
  `Partial/Catalogue/PickablePages.php`, `Partial/Definition/PageListPartial.php`,
  `README.md`, `Tests/Integration/Page/PageListUnderPageTest.php`,
  `Tests/Integration/Security/WriteRoutesTest.php`,
  `docs/evaluation/components/README.md`,
  `templates/backOffice/default-twig/documentation/_{en_US,fr_FR}.html.twig`.
  Gates green (unit 1985, integration 1712 then `WriteRoutesTest` again after
  the route was declared, PHPStan, style). Browser proof done and its page
  deleted.
- Also uncommitted in the same working tree, **not part of lot 1**: the
  magazine seed (`Tests/Browser/sample-site/seed.php`, `docs/developing.md`)
  and this plan. They belong with lot 3 (or a `test/` branch of their own).
- Host dev database: magazine v2 (showcase mode, news template, menus),
  components 1 to 6 and 9 to 11, modules Page and TheliaBlocks deactivated.
  Saved in `var/theliacms-sample-site-v2/` (taken before the deactivation).
- Flexy clone `~/workzone/thelias/flexy-templates`: on `main`, up to date;
  `origin/1.3` = 263eca7 (1.3.2). The host runs Flexy 1.3.2 from Composer
  (`templates/frontOffice/flexy`, ignored by git: never edit it, a change goes
  through the clone).
- SEOne clone `~/workzone/thelias/SEOne`: `main`, 7 commits behind
  `origin/main` (local 1.6.0, released 1.8.0): fast-forward before work.

### Lot 2, verified starting points

- Flexy: `components/Organisms/HeaderNav/Base.html.twig` draws
  `<twig:Organisms:CurrencySelect:Base />` (l. 6) and
  `<twig:Organisms:CartButton:Base />` (l. 41) with no condition; Twig
  functions live in `src/Twig/FlexyBundleExtension.php` (`hasCustomerAccount()`
  is the only header conditional). Price printers found by grep: ProductCard
  (Base, Search, Order), PseSelector, RangeSlider, Summary (Mini, Checkout,
  Order), CartItem, CartButton, QuickOrderTable, OrderCard, Payment,
  DeliveryMode, GiftWrapping; settle which are reachable on a showcase site
  (cart, order and checkout answer 404 there: `Vitrine/ShopRoutesListener.php`).
- Module: `Settings/CmsSettings.php` `save()` writes the mode (`SITE_MODE`);
  `Settings/Admin/CmsSettingsController.php` `save()`; module `update()` in
  `TheliaCMS.php` to write the core variable once from the stored mode.
- SEOne: `Service/ProductStructuredData.php` prints the `offers`.
- Release paths: Flexy fixes for the 3.3 core go to branch `1.3` (PR, CI of
  the theme), then a PR raising `<version>` of `template.xml`, then the tag
  `1.3.3` on its merge commit; SEOne's CI refuses a PR without a version
  bump. Push, PR, merge, tag, release: one explicit order of the maintainer
  per gesture.
- Open before the Flexy PR: the name of the core variable
  (`shop_sells_online` proposed) to put to the core maintainers.

### Prompt to resume on a blank context

« Reprends TheliaCMS sur l'hôte thelia-cms-ai, lot 2 du plan
`local/modules/TheliaCMS/docs/plans/sample-site-magazine.md` (vitrine sans
prix, panier ni devise). Lis le plan EN ENTIER, dont « Resuming ». Décisions
gelées : D1 (a), D2 (a) variable de configuration du core lue par Flexy,
D3 (a). Vérifie l'état réel : `git status -sb` et `git log --oneline -1` du
clone du module, de `~/workzone/thelias/flexy-templates` (branche `1.3`) et de
`~/workzone/thelias/SEOne` ; signale tout écart avec le plan. Le lot 1 et le
seed du magazine sont non commités dans le clone du module : ne les mélange
pas au lot 2 (demande-moi d'abord comment les ranger si ce n'est pas fait).
Commence par l'inventaire des composants de Flexy qui affichent un prix sur un
site vitrine, puis code Flexy (branche depuis `origin/1.3`), le module et SEOne,
tests vus rouges. Push, PR, fusion, tag, release : un ordre par geste. »
