# Plan: a richer magazine sample site, and a showcase site that sells nothing

Status (2026-11-18): lots 1, 2 and 3 are done. Lot 1 merged (#210; a `scope`
prop was added: right under the page or the whole branch, "Actualités" holding
its news under a page per year). Lot 2 delivered: the showcase site is
`price_display_mode` = `on_request` (D2 (d)), Flexy 1.3.3. Lot 3 is in the
working tree of the module (`seed.php`, `components.json`, `docs/developing.md`),
not committed. Decisions D1 to D3 taken by the maintainer on 2026-11-18: D1 (a),
D2 revised to (d) the same day, D3 (a).

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
  - gear test: chapo, `cms:image`, text, BikeSpecSheet, verdict;
  - advice: text, Steps, Faq;
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

## Resuming (rewritten 2026-11-18, lot 3 done)

### State checked on 2026-11-18

- Host (Thelia `main`, core 3.4.0, Flexy 1.3.3, DDEV,
  at its DDEV address). The module is `modules-dev/TheliaCMS`, linked
  by a Composer `path` repository (`vendor/thelia/modules/TheliaCMS`): the
  commands that read `local/modules/TheliaCMS/...` in this plan read
  `modules-dev/TheliaCMS/...` there, and the module is never copied into
  `local/modules/` (it would load twice).
- Branch `test/sample-site-showcase` = `origin/main` + the seed commit. Lot 3,
  not committed: `Tests/Browser/sample-site/seed.php` (recipes, listing pages,
  home page, French by default, components import, modules Page and
  TheliaBlocks deactivated, `price_display_mode`), `Tests/Browser/sample-site/components.json`
  (the 9 components, `format`, `version` and `components` only), `docs/developing.md`
  (section "A sample site to check the page list on") and this plan.
- Proof: on a fresh database (the demo before the seed, brought to the schema
  of the core 3.4.0) the seed ends without error twice with the same tree,
  dates and statuses as the seed before the recipes; `thelia_cms:render-published
  --all --dry-run --url <the address of the host>/` ends without error;
  `/`, Actualités, a gear test, a route, a portrait, Brèves answer 200 and the
  home page is the magazine's.
- Changed against the plan: the spec sheet is on the gear tests whose title
  starts with "Essai", not on every article of the gear rubrics (a comparison,
  a size guide or "Notre test de …" has no bike to describe), and its bike is
  read from the title (road, gravel, mountain, kids, e-mountain, cargo); no
  recipe opens with a heading: the title band of the page already shows the
  title; the ride calendar is on the one news of the autumn programme; "Bien
  débuter" uses the advice recipe; the introductions of the listing pages say
  "most recent first"; the gear test "… à moins de 1 500 €" is now "… abordable",
  a showcase site printing no price. No component was changed: BikeSpecSheet
  keeps its `price` prop, which the recipes leave empty and its template then
  leaves out.

### Left to do

- The maintainer's look at 1440 and 375 px (acceptance of lot 3: Actualités with
  its images, a gear test with its spec sheet, a route with card and carousel,
  the home page, the front in French): not done by lot 3, which was proved
  by HTTP codes and by the markup of the pages.
- Commit lot 3 (type `feat(hors-ticket)` or as the maintainer decides), push and
  PR: one order per gesture.
