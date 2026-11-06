# Header highlights in TheliaCMS (N22)

Status: decided (2026-11-05), not started. Branch `feat/header-highlights`, from `main` at `4fb9daf`.

## Goal

The editor sets the banner of one to three highlighted blocks that HeaderHighlights draws today, from TheliaCMS: media library images with their size and crop (N8), translations, draft, publication and versions. A site no longer needs HeaderHighlights for it.

## What HeaderHighlights 2.2.2 does (read in the test host, `vendor/thelia/modules/HeaderHighlights`)

- One banner for the whole site. A row of `header_highlights` is one block (`image_block` 1 to 3, block 1 being the large one) for one display (`display_type`: `desktop` or `mobile`), with an i18n title, catchphrase, call to action and URL, and its images in `header_highlights_image`. `category_id` is set to the first category at creation (`Model/HeaderHighlights.php:23`) and read nowhere else.
- A mobile block falls back to the desktop block of the same number (`Twig/HeaderHighlights.php`, `getMobiles()`).
- Rendered by a theme hook on `layout.header.bottom` (`Hook/Theme/HeaderHighlightsThemeHook.php`), which Flexy calls on every page (`templates/frontOffice/flexy/base.html.twig:119`), through the Twig component `HeaderHighlights`: `<ul class="Hero">`, a `<picture>` with the desktop image above 1024px and the mobile one below, a `Molecules:Button:Base` per block.
- Served by `/api/header-highlights` (API Platform resources in `Api/Resource/`), with its own shared-cache purge (`SharedCache/`).
- No stylesheet: the `.Hero…` classes are left to the theme, and Flexy has none (no `.Hero` rule under `templates/frontOffice/flexy`).
- Test host: the four tables are empty. A data migration has to be tested on a fixture, or on a copy of a site that uses the module.

## What TheliaCMS already has

- Theme hooks rendered by the module: `Menu/FooterMenuThemeHook.php`, `Consent/ConsentThemeHook.php`, `Catalogue/ItemContentThemeHook.php` and three more. Drawing something of the CMS on `layout.header.bottom` follows a known pattern.
- Images with width, height, ratio, focal point and zoom (N8, 2.12.0). One ratio per image: `docs/plans/image-dimensions.md` §5 left a second ratio for narrow screens (art direction) for later.
- A CMS home page (`home_page_id`). No shared section yet (F8 is not engaged).

## Options

### Where the banner lives

- (a) A section component of the module, `cms:highlights`, one to three blocks, placed in any page by the editor or the generator. Shows only where it is placed (the CMS home page, typically), not on every page as HeaderHighlights does.
- (b) A site zone drawn by TheliaCMS on `layout.header.bottom`, edited like a page (a hidden page used as a zone). Reproduces today's behaviour on every page. It is the first piece of F8 (shared sections): one fixed zone, not the general mechanism.
- (c) A Flexy component declared in `config/theliacms.yaml`. No module code, but it needs a Flexy PR for the component and its styles, and it shares the limit of (a): no display on every page.
- (a) and (b) combine: the component is written once, and the zone of (b) holds one section of it.

### The mobile image

- (m1) Each block has an optional `image_mobile` prop, drawn in a `<picture>` below 1024px, as HeaderHighlights does. Local to the component.
- (m2) One image per block, cropped by the N8 ratio on every screen. Less to fill, loses what a site set for mobile.
- (m3) Art direction for every image of the module (second ratio, `docs/plans/image-dimensions.md` §5 (c)). Larger, outside N22.

### The existing data and the API

- (d1) A command `thelia_cms:import:header-highlights` that copies the banner to a section (images to the media library, every language, desktop and mobile), as a draft: the editor publishes it, then removes HeaderHighlights. `/api/header-highlights` is not reproduced: a site that reads it keeps the module.
- (d2) Same, and the module answers `/api/header-highlights` from the section, so a client reading the API keeps working.
- (d3) No import: the editor writes the banner again.

## Decided (2026-11-05)

- Place: (a) then (b). The component `cms:highlights` first, usable in any page; then a zone on `layout.header.bottom` that holds one section of it, for display on every page.
- Mobile image: (m1), an optional `image_mobile` prop per block, drawn below 1024px.
- Data: (d1), a command that imports the banner as a draft; `/api/header-highlights` is not reproduced, a site that reads it keeps HeaderHighlights.

## Steps

1. Component `cms:highlights`: props, render (`<ul class="cms-highlights">`, `<picture>`), styles in `assets/blocks.css` (Flexy does not compile Tailwind utilities written in the module), `help` texts for the generator, catalogue sheet, tests (render, props, generator schema).
2. `image_mobile` per block (m1), falling back to the desktop image like HeaderHighlights.
3. Zone on `layout.header.bottom` (b), in its own PR after step 1: theme hook, hidden zone page, screen, cache invalidation with the page, test of the hook on a Flexy page.
4. Import (d1): command, fixture of the four tables, test seen red, dry run.
5. CHANGELOG, README (Theme integration), version bump with its SQL file if a table or a setting is added.

## Step 3: the zone on layout.header.bottom

Read at `956ddf4` (2.13.0). Frozen: a zone on `layout.header.bottom` holding one section of `cms:highlights`. Open: its shape. Hours are for a senior dev with a coding agent.

### Z1. Model of the zone

- (i-a) A hidden page, new column `cms_page.zone` (code, null = ordinary). Schema + `TheliaMain.sql` + `Config/update/2.14.0.sql`. Fail open: the 13 reads that say `model = 0` (`Sitemap/SitemapThemeHook.php:170`, `Search/PageSearch.php:62`, `Search/Tnt/CmsPageIndex.php:55`, `Page/ScheduledChanges.php:64`, `Page/StalePages.php:112`, `Page/PublishedAccessibilityFinder.php:90`, `Page/PublishPagesCommand.php:172`, `Page/Admin/CmsPageAdminRepository.php:153,295,331,626`, `Dashboard/ShowcaseStats.php:151`, `Page/PageTypeRepository.php:72,86`) each need `AND zone IS NULL`; a read forgotten shows the zone.
- (i-b) A hidden page with `model = 2` (the column is `TINYINT`, `Config/schema.xml:48`, no DDL). Fail closed: every read above already leaves out `model <> 0`, and the list, tree, sitemap, search and dashboard follow without a change. What changes is the few places that say `1 === getModel()`: `Page/PublishedPageRepository.php:366` (`isLive()`, must become `0 !== getModel()` or the zone is served at its URL), `ImportExport/SiteExporter.php:118` and `ImportExport/Step/PagesStep.php:91,163` and `ImportExport/Step/PageTaxonomy.php:48` (see Z6), and the refusals that a zone should keep: `Page/Admin/CmsPageWriter.php:429` (trash), `:795` (visibility). `Page/Admin/PageContentTarget.php:78` (refuses publication of a model) stays `1 ===`: a zone is publishable, which is the point. Cost: the meaning of a widely read column grows a third value (contract 2: each reader re-read, list above, templates read: `templates/backOffice/default-twig/pages/edit.html.twig:16,84,161,182,201,207` use `is_model` from `Page/Admin/CmsPageAdminController.php:396,553`, equal to `1 === model`, so a zone is not a model there).
- (ii) A setting `header_zone_page_id` (precedent `home_page_id`, `404_page_id`, `maintenance_page_id`: `Settings/CmsSettings.php:41-43`) pointing at an ordinary page. No hiding at all: the page keeps its URL (`Page/CmsPageViewListener.php:70-84`), sitemap, search, menus (`Menu/MenuTargetResolver.php:76`), list. Every one of those reads would need an `id <>` exclusion. Rejected.
- (iii) New tables `cms_zone_content` and `cms_zone_revision`, a third `ContentTarget` family (the writer is built for it, `docs/plans/content-target.md:225`). No leak by construction, but new `RevisionStore`, row, target, screen target (`Generation/Admin/ContentScreenTarget.php`), 15 routes on `Generation/Admin/ContentController.php:70-330`, and 4 readers that list contents of a family one by one (`ImportExport/SiteExporter.php:188`, `Content/Admin/SectionUsageFinder.php:57`, `Media/Admin/MediaUsageFinder.php:183`, `Page/RenderPublishedCommand.php:206`, plus `Content/Site/SiteComponentUsage.php:153`) must learn it, or the images of the zone are deletable and the zone is not exported.
- (iii-bis) Reuse `cms_item_content` with `item_type = 'zone'` (no DDL, `Config/schema.xml:234-257`). Rejected: three of those readers skip an unknown type (`tryFrom` then `continue`: `ImportExport/SiteExporter.php:190-192`, `Media/Admin/MediaUsageFinder.php:190-194`, `Page/RenderPublishedCommand.php:217-220`), so the zone would not be exported, not counted as a use of its images, not rendered again.

What the page model gives for free (proof): draft, publish, unpublish, versions and drafts kept, per language: `Content/Editing/ContentWriter.php:69,93,115,147,190,239` over `Page/Admin/PageContentTarget.php`; the content screen finds a page by id with no filter on `model` (`Page/Admin/CmsPageAdminRepository.php:173-179`, used by `Generation/Admin/ContentTargetResolver.php:80`).

How the zone fits the rule "a front read of pages goes through `isLive()` or `model = 0`": ordinary reads keep it, since `isLive()` (`Page/PublishedPageRepository.php:366`, used by `find` :54, `isReachable` :328, `withTag` :139, `ofType` :211) refuses `model <> 0` and `ofType` filters `model = 0` (:195). The zone has its own reader (Z3) that names `model = 2` on purpose: the one authorised exception, covered by the same test shape as `Tests/Integration/Page/ModelPageOfflineTest.php`.

Other places that read pages with no model filter, to patch for a zone: `Generation/Tool/SearchPagesTool.php:63` (the generator would offer the zone as a link target; today true of models too), `Page/RenderPublishedCommand.php:170` (renders published again, wanted), `ImportExport/SiteExporter.php:541` (exports every page, wanted, Z6).

Identity: at most one page with `model = 2` (the writer keeps it, like `assertSoleModel` for a type, `Page/Admin/CmsPageWriter.php:673`). Created on the first visit of its screen by a POST button (not a write on GET), title "Header zone". Publishing must not give it an address: `Page/Admin/PageContentTarget.php:153-163` (`addressIfMissing`) writes a rewritten URL and reserves a slug; a zone skips it.

Recommendation: (i-b), `model = 2`. Fail closed and no schema change. Its price is a muddied column; if a reviewer refuses that, (i-a) is the clean version for +2 to 3 h (SQL files, 13 filters, a test per filter) and F8 will need a `zone` code column anyway (several zones), at which point `model = 2` migrates to it. Estimate 2.5 to 3.5 h.

### Z2. Its screen

- (a) Reuse the content screen of a page, `/admin/cms/pages/{id}/content` (`Generation/Admin/ContentController.php:70`), reached from a new entry. Zero new routes. Its back link and title are those of a page (`Generation/Admin/PageScreenTarget.php:135` already reads `model`); to adapt for the zone: not read in detail.
- (b) Own screen. Rejected: rebuilds what (a) gives.
- Entry: a route `/admin/cms/zones/header` that creates the page if absent (POST) then redirects to (a); a link in the side navigation (`Hook/CmsSideNavHook.php`, `onMainInTopMenuItems`) and one on the settings screen. Not the dashboard block: it shows in showcase mode only (`Hook/CmsDashboardHook.php`, pitfall in `.claude/CLAUDE.md`).
- Components: any section, no new restriction. `Content/SectionAvailability.php` is a site-wide list, not per target, and no per-target restriction exists (grep `SectionAvailability`: only the generator reads it). The generator also stays (a zone is a document like another); a zone with a heading structure break is checked like a page (`refuseRendered`/`brokenHeadings`).
- Recommendation: (a), entry route, any section, `cms:highlights` named in the screen help and README. A restriction to `cms:highlights` is a new mechanism: not estimated, not recommended.
- Estimate 2 to 3 h.

### Z3. Rendering and cache

- Hook: `Zone/HeaderZoneThemeHook` implementing `ThemeHookInterface`, `supports('layout.header.bottom')`, like `Menu/FooterMenuThemeHook.php:41-44` and `Consent/ConsentThemeHook.php:38-41`; the renderer concatenates every handler, catching errors outside debug (`vendor/thelia/modules/TwigEngine/Service/ThemeHookRenderer.php`). Flexy fires it on every page (`templates/frontOffice/flexy/base.html.twig:119`).
- On or off: the footer hook is off by default (`Menu/FooterMenuThemeHook.php:38-40`, a theme may draw it already), the item hook on by default (`Catalogue/ItemContentThemeHook.php:28-33`). The zone is opt-in by nature (nothing is drawn until an editor publishes it): no setting.
- Read: one query for the published HTML of the page with `model = 2` in the language being served (`Twig/ItemContentExtension.php:72` reads the language from `LangService`), then `PartialRenderer::substitute()` for live blocks (`Twig/ItemContentExtension.php:79`) and the wrapper `<div class="cms-zone cms-zone--header">` styled in `assets/blocks.css`. Not `PublishedPageRepository::find()`: it reads the page, its tag, fields and images on each call (`Page/PublishedPageRepository.php:50-92`), too much for every page view. Cost per view: one indexed query on `cms_page_content` + one substitution. No cache layer at first (`Menu/MenuCache.php` exists because a menu resolves one row per entry); measured: not measured.
- Page cache: with the shared cache of the core on, every page of the site, the shop's too, is filed under `cms` and `cms-menu` (`Http/PageCacheListener.php:85`, `Http/CacheTags.php:59`). Publishing, unpublishing or rendering the zone again must purge `CacheTags::SITE`, as the settings screen does (`Settings/Admin/CmsSettingsController.php:146`) and the importer (`ImportExport/SiteImporter.php:122`). `PageInvalidator::contentChanged()` is not enough: it purges `cms-page-<id>` only (`Page/PageInvalidator.php:53-57`), a tag no page carries, the zone having no URL. Menu precedent: `MenuCache::invalidate()` + purge of `cms-menu` on every menu write (`Menu/Admin/CmsMenuWriter.php:89`), and the narrower rule of `Menu/NativeContentMenuCacheListener.php` (purge only when a menu links to the change) has no equivalent: the zone is on every page.
- Seam: `Catalogue/Admin/ContentTargets.php` `forPage()` returns the target; for `model = 2` return a `ZoneContentTarget` that extends the behaviour of `PageContentTarget` on `afterPublished`, `afterRenderedAgain`, `afterUnpublished`, skipping `addressIfMissing`, the search index (`duringPublication`) and the sitemap/lists invalidation (`PageInvalidator::listsChanged`, :73-79), and purging `SITE`. `Page/ScheduledChanges.php:64` ignores a zone's dates (`model = 0`): a zone has no schedule.
- Cost of the purge: every publication of the zone empties the shared cache (`Page/PageInvalidator.php:25-30` says why that is avoided for pages). Accepted: an editorial act, rare, same as a settings save.
- Recommendation: dedicated reader + theme hook, no app cache, purge `SITE` from the target. Estimate 2 to 3 h.

### Z4. Rights

- (a) `admin.cms.page` UPDATE for edit and publish, as every page action: `Generation/Admin/ContentController.php:441-444`, `Generation/Admin/ContentScreen.php:76-78` (`$mayPublish = $mayChange`), `Page/Admin/CmsPageAdminController.php:483-485`. Generator: `admin.cms.generate`.
- (b) `admin.cms.settings` for the zone: it is a site-wide element. A new check beside an existing screen.
- Planned F4 splits publish from change in that one place (`docs/plans/content-target.md:7,219`): the zone follows it with no work.
- Recommendation: (a). The zone shows on every page, a stronger reason for F4 than for a page, to be noted in the plan of F4 (not done here). Estimate 0.25 h (entry route guarded the same, VIEW on the side nav entry).

### Z5. Translation

- Per language like a page: one row of `cms_page_content` per locale (`Config/schema.xml`, `unq_cms_page_content_page_locale`), published apart.
- Fallback when a language has no published zone: (a) draw nothing, (b) draw the default language. A page does (a): "a 404, not a fallback" (`Page/CmsPageViewListener.php:79-83`), and `PublishedPageRepository::find()` returns null (:60-62). A banner in another language on every page of a language is not a 404 but is the wrong text; HeaderHighlights itself has no such fallback by language (not read). Recommendation: (a), nothing drawn, and the zone screen shows which languages are published (`PublishedPageRepository::publishedLocales()`, :346).
- Stale translation (F6): `Page/StalePages.php:112` joins `model = 0`: a zone is not in the stale list and its banner on the dashboard. The row is written by the shared writer (`TranslationFreshness` through the target), so the zone screen shows the stale mark on its own screen. Accepted for step 3; widening `StalePages` is a separate change.
- Generator translate (`/translate` route, `Generation/Admin/ContentController.php:230`): applies as for a page, `Generation/GenerationTargets.php:54` gives a `model = 2` page the model document of its type (`page_type` default). Not read further: not checked.
- Estimate 0.5 h (+ a test of the fallback).

### Z6. Export and import

- The exporter writes every page, models included (`ImportExport/SiteExporter.php:541`, `pageDocument()` :105-125 with `'model' => 1 === $page->getModel()`); the importer matches a page by its address (`ImportExport/Step/PagesStep.php:77`, `PageAddresses`), which a zone has not. Without a change a `model = 2` page is exported as an ordinary page (flag false) and imported as an ordinary visible page with a published version: a leak.
- Do: export `'zone' => true` for `model = 2`; the importer sets `model = 2` and `visible = 0` for it and keeps its published version (`PagesStep.php:163` empties it for a model only), found on the target site by `model = 2`, not by address; a second zone in a file is imported as a draft ordinary page with a warning (same rule as a second model, `Step/PageTaxonomy.php:48-62`). Images follow through the media map as for any page.
- Format version of the file: not read (`SiteDocument.php`): check whether a new key needs a bump.
- Recommendation: as above. Estimate 1.5 to 2.5 h, a round trip test seen red.

### Z7. HeaderHighlights still installed

Both draw on `layout.header.bottom`; handlers are concatenated in the order of the tagged iterator (`ThemeHookRenderer.php`, no priority declared by `HeaderHighlights\Hook\Theme\HeaderHighlightsThemeHook`; the order is not checked). HeaderHighlights draws nothing when its API has no row (`HeaderHighlightsThemeHook.php:34`), the zone nothing until published.

- (a) Doc only: README and CHANGELOG say to empty or remove HeaderHighlights once the zone is published.
- (b) A setting `header_zone_hook` (off by default as the footer, `Menu/FooterMenuThemeHook.php:38-40`). Makes the editor flip a switch after publishing for nothing.
- (c) Detect the module active and say so on the zone screen (notice). Not checked: the call to read a module's state; `Notice/` has the pattern, not read.
- Recommendation: (a) now, (c) if cheap. The import (step 4) already ends on "the editor publishes it, then removes HeaderHighlights". Estimate 0.25 h (a), +0.5 h (c).

### Z8. Schema, setting, version

- (i-b): no schema change, no setting, no SQL. A version bump with a CHANGELOG entry (release rule: `Config/module.xml` + dated `[Unreleased]`, see the "Auto Release" row of `/home/dfoulhoux/workzone/thelia-cms-ai/.claude/CLAUDE.md`). `Config/update/` holds SQL for versions that changed a schema only (`2.6.0`, `2.7.0`, `2.10.0`, `2.13.0`).
- (i-a): `Config/schema.xml` + `Config/TheliaMain.sql` + `Config/update/<version>.sql` rejouable, caches and `var/propel/<env>` removed, test base replayed (pitfalls rows in the host `CLAUDE.md`).
- Recommendation: (i-b) in step 3, no SQL.

### Decided for step 3 (2026-11-05)

- Z1: (i-b), the zone is a hidden page with `model = 2`; no schema change.
- Z2: the content screen of a page, reached from a new entry; any section allowed, `cms:highlights` advised in the screen help and the README.
- Z3: dedicated reader and theme hook on `layout.header.bottom`, no app cache, publish / unpublish / render again purge `CacheTags::SITE`, no address written.
- Z4: `admin.cms.page` UPDATE for edit and publish.
- Z5: a language with no published zone draws nothing; the zone screen shows the published languages.
- Z6: the zone travels in the export (`zone` key) and is matched on `model = 2` at import.
- Z7: README and CHANGELOG, plus a notice on the zone screen while HeaderHighlights is active.
- Z8: no SQL, no setting.

### Risks

- `model = 2` is a third value for a column documented as "the model of its type, never published" (`Config/schema.xml:43-48`): any reader added later that says `1 === model` is wrong about the zone. Parade: a test per reader shape (`ModelPageOfflineTest` twin) and the comment of the column.
- The purge of `SITE` empties the shared cache of the shop on each publication. Not measured on a site with the cache on.
- The back link and the title of the content screen are a page's; a zone seen as a page with a "Settings" tab would let an editor trash it or change its visibility. The tab is reached from the page edit screen only, which the list no longer links to; the route is still open by id. `moveToTrash` and `toggleVisibility` must refuse (above).
- Not a blocker for the frozen decisions: (a) then (b), (m1), (d1) all hold.

### Estimate of step 3

- Z1 2.5 to 3.5 h; Z2 2 to 3 h; Z3 2 to 3 h; Z4 0.25 h; Z5 0.5 h; Z6 1.5 to 2.5 h; Z7 0.25 to 0.75 h; CHANGELOG, README, version 0.5 to 1 h; browser check on Flexy (desktop and mobile width, shop page and CMS page) 1 to 1.5 h.
- Tests: 4 to 6 h, included in the sum below. Integration: hook draws the published zone on a Flexy front page and a shop page; draws nothing without a published zone, nor in a language with none; the zone page answers 404 at its URL, is absent from sitemap, search (full text and TNT), menus (`isReachable`), the page list, the tag and type lists, `thelia_cms:publish --all`; refuses trash and visibility; publishing purges `cms` (fake `CachePurgerInterface`), publishing writes no address; export/import round trip keeps the zone hidden and published and a second zone becomes a draft with a warning; `isLive()` twin of `ModelPageOfflineTest` with `model = 2` visible and published. Unit: the hook (support, locale, empty). Each seen red by breaking the code covered.
- Total 14.5 to 22 h on the assumption that (i-b) holds, the content screen is reused as is and the export format needs no version bump. Contingency 25%: 3.5 to 5.5 h (the page cache, the content screen back link, the import match). With (i-a): +2 to 3 h. Above the 1 to 1.5 day of the first estimate below (8 to 12 h): the export, the tests per reader and the screen entry were not in it.

## Estimate (reading, not a prototype)

- (a) with (m1): 1 to 1.25 days.
- (b) on top: 1 to 1.5 days.
- (d1): 0.5 day; (d2): 0.5 to 0.75 day more.
- Contingency 25% on the batch: no real data in the test host, and the zone of (b) touches the page cache (`PageInvalidator`, `ScheduledChanges`).

## Not checked

- The exact HTML HeaderHighlights draws on a site that styles `.Hero`; whether sites read `/api/header-highlights` outside the theme.
- Whether a second HeaderHighlights per category exists on any site (`category_id` suggests an intent the code does not follow).
- Step 3: no code was run. Not read: the back link and title of `Generation/Admin/PageScreenTarget.php` for a `model = 2` page; the file format version in `ImportExport/SiteDocument.php`; the order of two handlers on `layout.header.bottom`; how to read the active state of a module for the HeaderHighlights notice; `GenerationTargets` for the generator on a zone. Not measured: the cost of the zone read per page view and of a `SITE` purge on a shop with the core shared cache on. Not checked: other readers of `cms_page.model` outside `*.php` and the templates listed (JS, SQL files).
