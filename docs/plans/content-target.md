# Plan: one content target, one writer, one controller for a page and a catalogue item (Q4a, Q4b)

Status: decided 2026-11-05 (see "Decided"), read on `refactor/content-target` at 9540187 (PR #88 not merged yet: `Http/Admin/AdminControllerSupport`,
component controllers in `Component/Admin/`, PHPStan rule "Content does not depend on Generation", ADR 12).
Q4 comes after Q3 and Q6; the shared support is an injected `final readonly` service, not a parent class (frozen).
What Q4 prepares: F2 (list the published versions and put one back, a save before an edit, a comparison per
section), F4 (a right to publish apart from the right to change, a "changed by X" banner, the version date checked
on submit), N23 (translate the title, summary, description and address of a page too). All three land in the files
below, today twice.

## Findings verified (module at 9540187)

### The two controllers
`Generation/Admin/CmsPageContentController.php` (637 lines, route class `/admin/cms/pages/{id}/content`, names
`admin.cms.pages.content.*`, 63) and `Catalogue/Admin/ItemContentController.php` (495 lines,
`/admin/cms/catalogue/{type}/{id}/content`, `admin.cms.catalogue.content.*`, `type` constrained by
`CatalogueItemType::ROUTE_REQUIREMENT`, 61).

- **Same code, the target aside.** `edit`, `generate`, `revise`, `section`, `restore`, `writeFromAnotherLanguage`,
  `documentOrFail`, `backWithErrors`, `back`, `screenUrl`, `denyUnlessMayGenerate`, `denyUnlessMayPublish`,
  `blankToNull`, `flash`, `translate`: page 86-136, 138-171, 209-228, 369-400, 557-636; item 83-166, 209-257,
  420-494. Only the lookup (`livePageOrFail` 596 / `itemOrFail` 454), the target (`$this->target()` 547 /
  `$this->targets->forItem()`) and the wording of two messages differ ("This page holds nothing to change yet" 562 /
  "This content holds nothing to change yet" 425).
- **The changes by hand.** The page writes `update`, `remove`, `duplicate`, `move` as four copies of 35 lines
  (173-367); the item folds them into `byHand()` + `afterHand()` (312-355, `match` on the change, 331-336). `add`
  stands apart on both sides (page 302-331, item 192-207).
- **Same shape, different content.**
  - `publish`: page 402-454 catches six exceptions (heading, invalid, empty, placeholder, model page, missing
    fields); item 259-297 catches three (heading, invalid, empty). Both flash the heading problems in `warn` mode
    afterwards (page 447 through `draftHtmlOf()`, item 290 through the repository).
  - `run`: the page offers the generator to make a component of the site (`withComponentMaking`, 464) and saves
    the fields and the SEO proposal (470-477); the item does neither (359-370).
  - `subject`: the routes (page 502-517, 14 entries with `component_generate` and `component_keep` served by
    `CmsPageComponentController`; item 377-381, 12 entries), back link (settings of the page 496 / edit screen of
    the item in the core, `itemEditUrl` 407-418), previews (`PreviewLink` / `ItemPreviewLink`), publish hint,
    `testId` (`cms-page-content` 518 / `cms-item-content` 396), `publishable` (false for a model page, 520), the
    template of the page (`template()` 533-545). `ContentScreen` hides the prototype when the subject has no
    `component_generate` route (`Generation/Admin/ContentScreen.php:98,117`).
- **Item only:** `unpublish` (299-310), posted from the tab of the core edit screen
  (`Catalogue/Admin/ItemContentTabHook.php:126`). A page is taken offline from its settings screen, not here.
- **Two direct Propel queries** on the page side: `documentOf()` 554 and `draftHtmlOf()` 570.
  `CmsPageAdminRepository::draftDocument()` (264) now answers the first; nothing answers the second yet.
- **Neither uses `AdminControllerSupport`** (`Http/Admin/AdminControllerSupport.php:37`): both keep their own
  `flash`, `translate`, `backWithErrors` and `denyUnless*`. `phpstan-baseline.neon` lists both files (7, 13, 73).
- **Lookup order.** The page is looked up before the language (89-90), the item after it (86-87), because
  `CatalogueItems::find()` needs a locale (`Catalogue/CatalogueItems.php:32`). `EditLanguage::resolve()` writes
  the edit language into the session (`Page/Admin/EditLanguage.php:43-45`).

### Rights, today
- `Security/CmsAdminGuard.php`: every route under `/admin/cms` needs `admin.cms.page` VIEW, the sections `pages`
  and `catalogue` both mapped to `CmsResources::PAGE` ("The documents of the catalogue items are written like
  pages"), and the token of the back office on every write.
- Both controllers: generate, revise, section, translate need `admin.cms.generate` UPDATE (page 607-612, item
  465-470); update, remove, duplicate, add, move, restore, publish (and the item's unpublish) need `admin.cms.page`
  UPDATE (page 614-619, item 472-477). `edit` checks nothing beyond the guard on either side, although the item
  docblock (57-59) and ADR 11 say the screen takes "the right to change pages".
- Both writers allow custom code with `admin.cms.custom-code` UPDATE (`CmsPageWriter.php:1018-1021`,
  `ItemContentWriter.php:371-374`).
- No right of the core on the item itself (product, category...) is checked anywhere. The tab is shown on
  `admin.cms.page` VIEW and its unpublish button on UPDATE (`ItemContentTabHook.php:97,106`).

### The two writers
`Page/Admin/CmsPageWriter.php` (1040 lines; the content part is `saveContent` 180-202, `keepDraft` /
`previousDrafts` / `restoreDraft` 245-283, `renderPublishedAgain` 352-391, `publish` 398-446,
`publishableHtml` 478-534, `unpublish` 536-558, `contentFor` 1003-1011, `mayPublishCustomCode` 1018-1021; the rest
is page settings, bin, copies, models, tree). `Catalogue/Admin/ItemContentWriter.php` (390 lines).

- **Same pipeline:** validate, render, normalize, sanitize for the draft (page 183-188, item 89-93); render again,
  rewrite images, refuse when empty, check headings for publication (page 498-520, item 345-360);
  `renderPublishedAgain` identical apart from the purge (page 352-391, item 269-301).
- **Page only:** model page refused (481), required fields of the type (486-491), sample text refused
  (`PlaceholderContentChecker`, 527-531), search index in the transaction (`indexForSearch` 416, 1027-1039),
  `PageInvalidator::contentChanged` / `presenceChanged` depending on whether the page was online (422-427, 552-555),
  plus `saveSeo`, `saveFields`, `renderDraftAgain`, `assertPublishable`, `removeProvenance`.
- **Item only:** `CachePurger::purge([$type->cacheKey($id)])` on publish, render and unpublish (249, 297, 315), the
  unpublish purging even when nothing was online; `forget()` (323-327) since no foreign key ties the tables to the
  core (ADR 11).
- **Activity log.** Pages log `CMS page #%d ...`; items log `CMS content of the %s #%d ...` (106, 250, 316) through
  `CmsActivityLog::record()`, whose resource defaults to `admin.cms.page` (`Page/Admin/CmsActivityLog.php`): an
  item entry is filed under the page resource with the item id as resource id, so "page #12" and "product #12" share
  a key in the system log.
- **Log timing.** The page logs the publication inside its `try`, after the commit (429-435); the item after the
  `try` (249-256).

### The versions
- `Page/Admin/PageVersions.php` (184 lines, Q5): `keepPublication` (inside the transaction), `keepDraft`,
  `drafts`, `publications`, `draft`, `publication`, pruning 20 publications and 5 drafts per (page, locale), label
  `draft`. `publications()` and `publication()` have no caller outside the tests yet: they wait for F2.
- `ItemContentWriter` holds its own copy: constants 53-60, `keepDraft` 114-135, `previousDrafts` 142-152,
  `restoreDraft` 161-174, `draftRevisions` 176-179, `documentOf` 185-192, `prune` 198-214, `snapshot` 376-389.
  Same retention, same label, same pruning.
- **Schema:** `cms_page_revision` and `cms_item_revision` have the same columns (`document`, `html`,
  `provenance`, `label`, `created_by`, timestampable) and differ only by their key: `page_id` with a cascading
  foreign key to `cms_page` (`Config/schema.xml:182-203`) versus `item_type` + `item_id` without a foreign key
  (255-274). Same for `cms_page_content` (157-181) and `cms_item_content` (232-254). No migration is needed to
  share the code.
- Nothing exports the revisions: the import writes `cms_item_content` only
  (`ImportExport/Step/CatalogueContentsStep.php:79-94`).

### A third copy: the command
`Generation/GenerateCommand.php` saves a generated document through both writers (item 178, page 187-194), without
keeping the replaced draft first, unlike both screens (page 467, item 366).

### Layering
`Content/` imports nothing from `Page`, `Catalogue` or `Generation` (imports read: Content, Install, Locale, Media,
Model, Partial, Security). The PHPStan rule (`Tests/PHPStan/ContentDoesNotDependOnGenerationRule.php`,
`phpstan.dist.neon:48`) forbids `Generation` only. The content screen (`ContentScreen`, `ContentSubject`,
`GenerationRunner`, `SectionUpdate`, `TranslationSources`) lives in `Generation/Admin/`, so the single controller
stays outside `Content`.

## Duplication measured

Measured on the item files, the second copy, against the page files.

**Method 1, statements.** Lines kept: not blank, not a comment, not a `use`/`namespace`/`declare`, not a lone
brace or parenthesis. Then each line is normalized: the target variables and types renamed to one placeholder
(`$page` / `$item`, `CmsPage $page, string $locale` / `CatalogueItemType $type, int $id, string $locale`,
`CmsPageContent` / `CmsItemContent`, `CmsPageRevision` / `CmsItemRevision`, the empty and heading exceptions, the
lookup and target helpers). Count = multiset intersection of the normalized lines of both files. Run with an inline
Python script on stdin (not kept in the repository).

| Pair | Item statements | Identical as written | Identical after renaming the target |
|---|---|---|---|
| Controllers | 246 | 112 | 174 (71 %) |
| Writers, content part of `CmsPageWriter` | 81 | 48 | 57 (70 %) |
| Item versions / `PageVersions` | 64 | 30 | 36 (56 %) |
| **Total** | **391** | **190** | **267 (68 %)** |

**Method 2, methods.** Each method of the item files, docblock included, classed by reading it against its twin:

| | Controller | Writer | Total |
|---|---|---|---|
| Same code, the target aside | 264 | 205 (123 versions + 82: `saveContent`, `renderPublishedAgain`, `contentFor`, `mayPublishCustomCode`) | **469** |
| Same shape, target-specific content | 111 (`publish`, `run`, `subject`, `itemOrFail`, constructor) | 107 (`publish`, `publishableHtml`, `unpublish`, constructor) | **218** |
| Item only | 28 (`unpublish`, `itemEditUrl`) | 9 (`forget`) | 37 |

So **687 of the 885 lines of the two item files sit in a method with a twin on the page side, 469 of them as the same
code**. The audit's "about 800" counted both sides loosely; 687 is the measured figure for the copy. To this adds
the copy inside the page controller: four by-hand actions of 35 lines (140 lines) where `byHand()` takes 63.
Expected net reduction after Q4a + Q4b: 500 to 650 lines (estimate, not measured: it depends on the size of the
two target adapters).

## Design

### The target
- `ContentKey` (final readonly value): `kind` (`page`, or a `CatalogueItemType` value), `id`, `locale`. Names the
  target in log messages, exception messages and revision lookups.
- `ContentTarget` (interface), one implementation per family:
  - `PageContentTarget` (in `Page/Admin/`) wraps a live `CmsPage` and a locale;
  - `ItemContentTarget` (in `Catalogue/Admin/`) wraps a `CatalogueItem` (which already carries type, id and
    locale).
  It gives: its key; its content row, found or new (`CmsPageContent` / `CmsItemContent`, through a small adapter
  so the shared code never names a Propel class); its revision store (below); the checks that come before
  rendering (model page, required fields) and after (sample text), as a list of refusals; what follows a write
  (page: search index in the transaction, `PageInvalidator` depending on `wasOnline`; item: `CachePurger` on the
  item key, always, as today); its log message for `UPDATE`, `PUBLISH`, `UNPUBLISH`, and the resource it is filed
  under (unchanged: `admin.cms.page`).
- **Who builds it:** a `ContentTargets` service with `forPage(CmsPage, string $locale)` and
  `forItem(CatalogueItem)`, used by the writer callers (controller, commands, import) that hold a page or an item
  today. On the screen, a `ContentTargetResolver` reads the route: `{id}` alone resolves a live page through
  `CmsPageAdminRepository::findLive()` before the language (as today), `{type}` + `{id}` resolve an item through
  `CatalogueItems::find()` after the language (as today); not found = 404 on both.
- **Where:** the interfaces and the shared writer under `Content/Editing/` (they name `Content` and `Model` only,
  the rule of ADR 12 holds and is extended to nothing else); the two implementations next to their family. See D1
  for the alternatives.

### The shared writer (Q4a)
`ContentWriter` (`final readonly`) does what both writers do the same way: `saveContent(target, document,
?provenance)`, `keepDraft`, `previousDrafts`, `restoreDraft`, `publish` (transaction, snapshot, revision, then the
target's after-publish), `renderPublishedAgain(target, write)`, `unpublish`. It delegates to the target the
refusals, the side effects and the wording.

- `CmsPageWriter` keeps its public methods (named in 29 files outside the tests and 42 test files): the
  content ones become one-line delegations to `ContentWriter` with `PageContentTarget`; the page-only ones
  (`saveDraft`, `saveSeo`, `saveFields`, `renderDraftAgain`, `assertPublishable`, `removeProvenance`, bin, copies,
  tree) stay.
- `ItemContentWriter` shrinks to `forget()` and the target building, or goes, its callers (`GenerateCommand:178`,
  `RenderPublishedCommand:112`, `ItemDeletionListener`, `ItemContentController`, nine test files) calling
  `ContentWriter` with an `ItemContentTarget` (D1).
- The exceptions keep their classes and messages: the target raises `EmptyPageContentException` or
  `EmptyItemContentException`, `HeadingStructureException` or `ItemHeadingStructureException`. Merging them is
  out of scope (the controllers catch them by class).
- `assertPublishable()` (page) goes through the same checks as `publish()`, as today.

### The versions
`PageVersions` becomes `ContentVersions` (or keeps its name, D2) and takes a `ContentTarget` instead of a
`CmsPage`. The storage is a `RevisionStore` per family (`PageRevisionStore` over `CmsPageRevision`,
`ItemRevisionStore` over `CmsItemRevision`): new revision for a key, query of the revisions of a key with an
optional label, find by id within a key. Retention (20 / 5), label, listing, pruning, the empty-draft rule and the
restore rule live once. **No change to the SQL schema in the recommended option** (two tables kept): no
`Config/schema.xml`, `Config/TheliaMain.sql` or `Config/update/<version>.sql`. Option D2-B (one table) needs all
three plus a replayable data move; see there.

The revision id posted to `restore` must still be looked up within the key of the target (page id, or item type +
id, and locale): a revision of page 12 must never restore on product 12. Two tables make that hold by
construction; one table makes it a filter that a test must pin.

### One controller (Q4b)
`Generation/Admin/ContentController.php` (`final readonly`) replaces both:
- **Routes:** every action carries two method-level `#[Route]` attributes with the exact paths, names, methods
  and requirements of today (`admin.cms.pages.content.{action}` on `/admin/cms/pages/{id}/content/...`,
  `admin.cms.catalogue.content.{action}` on `/admin/cms/catalogue/{type}/{id}/content/...`); `unpublish` keeps its
  item route only. Signature `(Request $request, int $id, ?string $type = null)`. No URL, no route name, no
  template changes. `CmsPageComponentController` stays as it is (its routes are the page's only). See D3.
- **Screen description:** what differs per family on the screen (routes, back link and label, previews, publish
  hint, `testId`, `publishable`, template link, component making, wording of the messages, which save the
  generator's answer triggers: fields and SEO for a page) comes from a `ContentScreenTarget` per family built by
  the resolver, which also builds the `ContentSubject` and the `GenerationTarget` (`GenerationTargets::forPage` /
  `forItem`, unchanged).
- **By hand:** the four by-hand actions on one `byHand()` as the item does today; `add` apart.
- **Shared support:** `AdminControllerSupport` for `translate`, `flash`, `flashFormErrors`, `denyUnless`; its
  `flash` checks `FlashBagAwareSessionInterface` (58-65), which removes the two baseline entries.
- **No Propel in the controller:** the draft document and draft HTML come from the target (for a page,
  `CmsPageAdminRepository::draftDocument()` and a new `draftHtml()`).
- **Rights:** unchanged and per action, identical for both families: generate, revise, section, translate need
  `admin.cms.generate` UPDATE; update, remove, duplicate, move, add, restore, publish, unpublish need
  `admin.cms.page` UPDATE; the guard keeps VIEW and the token. One `denyUnlessMayChange()` and one
  `denyUnlessMayGenerate()` in one file is what F4 will split (publish apart from change). Item rights of the core:
  D4.

## To decide

**D1. Shape of the shared writer.**
A) `ContentWriter` + one `ContentTarget` per family (composition): the shared code never names a page or an item;
a third family (a brand, a store type) is a new class, not an edit of the writer. Costs two adapters (rows,
revisions) and one more indirection to read.
B) The target is a plain key; the writer `match`es on the kind for the side effects and checks: fewer classes, but
the writer imports `Page` and `Catalogue`, and every family difference is a branch in shared code.
C) Keep both public writers; extract only the pipeline (render, filter, check) and the versions into shared
services: smallest diff, the controller still needs two writers and two code paths.
Reco A, with `ItemContentWriter` removed rather than kept as a facade (nine test files to touch, once).

**D2. Where the versions are stored.**
A) Two tables kept, one `ContentVersions` over a store per family: no migration, the page revisions keep their
cascading foreign key, isolation per family by construction.
B) One table `cms_content_revision` (`target_kind`, `target_id`, `locale`, ...), with `Config/schema.xml`,
`Config/TheliaMain.sql`, a replayable `Config/update/<next>.sql` moving the rows of both tables and dropping them,
the Propel models, and the page cascade redone in code (bin purge, page deletion). F2 then reads one table. Costs
about 4 h more and a migration on every site.
C) Leave the item versions in `ItemContentWriter`: F2 written twice.
Reco A. Rename `PageVersions` to `ContentVersions` (it is no longer about pages); a class name is cheap now.

**D3. How one controller answers both families of addresses.**
A) Two method-level `#[Route]` per action, full paths and names: works on every Symfony version, explicit, 25
attributes (12 actions x 2 + unpublish).
B) Two class-level `#[Route]` prefixes on one class: shorter, but whether the attribute loader of Symfony 7.4
combines several class-level routes is not verified here (hypothesis to check before choosing it).
C) Two thin route classes (about 30 lines each) that resolve the target and call one action service: no attribute
trick, but two controllers in name, against the frozen "one controller".
Reco A.

**D4. Rights of the core on a catalogue item.**
Today a person with `admin.cms.page` UPDATE writes and publishes the document of a product without any right on
products.
A) Keep exactly that in Q4 (a refactor changes no behaviour).
B) Also require the core's UPDATE right on the item type (the resource names of
`Thelia\Core\Security\Resource\AdminResources` to read, not read here), on the writes of the item routes and on
the tab: a behaviour change for shops whose CMS editors have no catalogue right.
C) A resource of the module, `admin.cms.catalogue`, granted apart.
Reco A in Q4, then B as its own ticket with a CHANGELOG entry under "Changed", decided with F4 (which already
reworks the rights).

**D5. The small differences between the two families.**
Item unpublish purges even when nothing was online; the item log is filed under the page resource with the item
id; the page logs inside its `try`; items never offer component making; the command does not keep the replaced
draft.
A) Keep all of them in Q4, each one a capability of the target, listed in the PR.
B) Align the harmless ones in Q4 (purge only when online; log after the commit on both), keep the visible ones
(log resource, component making, command).
Reco A: a refactor PR that changes nothing makes its tests the proof; the alignments go in a follow-up commit
each, after Q4b.

## Decided (2026-11-05)

- D1: A. `ContentWriter` with one `ContentTarget` per family; `ItemContentWriter` removed.
- D2: A. Two revision tables kept, one `ContentVersions` service (renamed from `PageVersions`) over a store per family; no migration.
- D3: A. Two method-level `#[Route]` per action, paths and names unchanged.
- D4: A. Rights unchanged in Q4; the core's right on the item goes in its own change, decided with F4.
- D5: A. Every difference between the families kept in Q4, as a capability of the target; one alignment commit per difference after Q4b.

## Done (2026-11-05)

- Q4a-0: tests committed alone.
- Q4a-1 and Q4a-2 in one commit (the intermediate state of Q4a-1 was not kept apart). As built:
  - `Content/Editing/`: `ContentKey`, `ContentSnapshot`, `Revision`, `RestorableDraft`, `RevisionStore`,
    `ContentVersions` (renamed from `PageVersions`), `ContentRow`, `ContentTarget`, `ContentWriter`,
    `PublishedRendering` (moved from `Page/Admin/`, returned by the shared writer).
  - Page side: `PageRevisionStore`, `PageContentRow`, `PageContentTarget`. Item side: `ItemRevisionStore`,
    `ItemContentRow`, `ItemContentTarget`, and `ContentTargets` (`forPage`, `forItem`, `forItemKey`), in
    `Catalogue/Admin/` since it names both families.
  - The target hands its revision store to `ContentVersions`; no tagged lookup of the stores.
  - `ItemContentWriter` removed; `forget()` moved to `ItemContentRepository`.
  - `ContentWriter` reads `CmsSettings::headingCheckBlocks()`, a first import of `Settings` in `Content/`.
  - One D5 difference not kept, accepted by the developer: the page logs its publication after the transaction, as
    the item does, instead of inside its `try`. The only observable case was an invalidation failing after the
    commit, which then called `rollBack()` on a closed transaction.
  - Pinned afterwards (`ContentSideEffectsTest`): purges and invalidations run after the commit, for a page and
    for an item.
- Q4b-1 as built:
  - `Generation/Admin/ContentController` on `AdminControllerSupport` replaces the two controllers (deleted).
  - `ContentTargetResolver` finds the page by its id, or the item by type and id, and answers 404. The page is
    looked up before the language, the item after it (its texts are read in it).
  - `ContentScreenTarget` with `PageScreenTarget` and `ItemScreenTarget`: what the screen reads and does for a
    family. `draftHtml()` comes from `CmsPageAdminRepository` for a page.
  - The baseline entries of the two deleted controllers are dropped.
  - Test added: the lookup order (`ContentScreenTest::testAnUnknownPageDoesNotChangeTheLanguageBeingEdited`): a
    page that does not exist leaves the language being edited as it was. Red when the language is resolved first.

## Steps in order

Each test is written first, run green on 9540187, then sabotaged once (the line named below) to see it red, then
the refactor. Base class `CmsIntegrationTestCase` or `AdminScreenTestCase`; generator answers through
`FakeAiGateway`; purges through `purgingWith()`.

### Q4a-0. Tests that fix today's behaviour (one commit, tests only)
Already covered, to keep green throughout:
- Page screen: `Tests/Integration/Generation/ContentScreenTest.php` (brief, kept draft and restore with an unknown
  revision 90, translation 203, SEO 271, publish 531, generate right 542, page in the bin 561);
  `Generation/SectionEditScreenTest.php` (by hand: update 76, remove 253, move 285, add 504-546, duplicate 590,
  right to change 735); `Generation/ProvenanceTest.php` (mark kept by a human edit 138, mark of a restored draft
  150, publication 171).
- Page writer: `Page/PublishPipelineTest.php` (snapshot, search text and revision 41, unpublish 89, refusals
  104-185); `Page/PageInvalidationTest.php` (purges of publication 74-95); `Page/RenderPublishedCommandTest.php`
  (page 93-103, item 105-123).
- Item: `Catalogue/ItemContentScreenTest.php` (tab 52, 404 and brand 68, add + publish + unpublish from the tab 85,
  duplicate 111, invalid draft 129, brief 146, kept draft and restore 170, previews 195-211);
  `Catalogue/ItemContentWriterTest.php` (save, filter, publish, unpublish, refusals, deletion 46-153);
  `Catalogue/DealerStoreScreenTest.php`, `DealerStoreContentTest.php`, `ItemContentRoundTripTest.php`,
  `ItemContentUsageTest.php`, `ItemContentThemeHookTest.php`, `Twig/ItemContentExtensionTest.php`,
  `Generation/GenerateCommandTest.php` (item 192).
- Both: `Security/WriteRoutesTest.php` (token on every write, GET only on the screens, both `edit` routes listed
  49, 71), `Security/WritePermissionTest.php` (generate right on the page screen 49), `Security/LoginRedirectTest.php`.

Missing, to write before any refactor:
1. **Route table.** For both prefixes, the list of (name, path, methods, requirements) read from the router equals
   a literal list. Red: rename `admin.cms.catalogue.content.move`, or drop `unpublish`.
2. **Rights matrix, both families.** Each action x {no generate right, no page UPDATE right}: 403 where expected,
   not 403 otherwise. Today only the page side has part of it. Red: remove `denyUnlessMayPublish()` from the item
   `restore` (213-218).
3. **Revision isolation.** A kept draft of page P posted to the restore of product P (same id), of another page,
   and of the same item in another locale, changes nothing; a publication id is refused as a draft. Red: drop
   `filterByLabel` or the key filter in `draft()` / `draftRevisions()`.
4. **Retention per family.** Seven briefs keep five drafts; 21 publications keep 20; drafts and publications counted
   apart; one family's pruning leaves the other's rows. Red: change `DRAFT_RETENTION`.
5. **Item screen actions not covered:** update, remove, move by hand; revise, section, translate through the
   generator; publish with a heading warning in `warn` mode; the "holds nothing to change yet" refusal. Red: swap
   two arms of the `match` in `byHand()` (331-336).
6. **Side effects per family.** Item publish, render again and unpublish purge `product-<id>`; page unpublish
   purges only when the page was online; page publish indexes the search text (already 41) and the item does not.
   Red: remove the purge at `ItemContentWriter.php:249`.
7. **Activity log, both families:** resource, resource id and message of the `UPDATE`, `PUBLISH`, `UNPUBLISH`
   entries (`AdminLog`). Red: change one message.
8. **Messages that differ per family**, read in French on the screen: publish refusals and the empty-draft refusal
   (their keys are in `I18n/`, a merged message would lose its translation silently). Red: edit one key.
9. **`purgingWith()`** (`Tests/Integration/CmsIntegrationTestCase.php:209-226`) also recurses into the new
   services that hold `CachePurger` or `PageInvalidator`, written with step Q4a-2 but listed here: without it, a
   spy built for `CmsPageWriter` sees nothing and "purges nothing" assertions
   (`RenderPublishedCommandTest.php:96,103`) pass for the wrong reason.

### Q4a-1. Versions shared (one commit)
`ContentKey`, `RevisionStore` x 2, `ContentVersions` from `PageVersions`; `CmsPageWriter` and `ItemContentWriter`
call it; the item copy (123 lines) goes. Tests 3 and 4 and the restore tests stay green.

### Q4a-2. Writer shared (one commit, or two: page then item)
`ContentTarget`, `PageContentTarget`, `ItemContentTarget`, `ContentTargets`, `ContentWriter`; `CmsPageWriter`
delegates its content methods; `ItemContentWriter` callers moved (D1). `purgingWith()` extended. PHPStan with the
Content rule, baseline entries of the removed code dropped.

### Q4b-1. One controller (one commit)
`ContentTargetResolver`, the two `ContentScreenTarget`, `ContentController` on `AdminControllerSupport`; the two
controllers deleted; `CmsPageAdminRepository::draftHtml()`. Tests 1, 2, 5, 8 are the proof that nothing moved.
Browser check of both screens (a page, a product, a dealer store if the module is there): brief, change by hand,
restore, publish.

### Q4b-2. Docs (same PR as Q4b-1)
ADR 11 amendment (one controller, one writer; the screen takes `admin.cms.page` VIEW, as the code does), a line in
ADR 12 if the namespace `Content/Editing` is chosen, CHANGELOG `[Unreleased]` under "Changed" (internal), the
docblock of the item controller's rights moved to the new controller.

Gates on each commit: `composer stan`, `composer cs`, unit suite, integration suite (one suite at a time on the
`test` database; `cache:clear --env=test --no-debug` after the constructors change).

Q4a and Q4b can be two PRs (Q4a first, mergeable alone: the two controllers keep working on the shared writer) or
one PR with these commits.

## Files touched
New: `Content/Editing/ContentKey.php`, `ContentTarget.php`, `ContentWriter.php`, `ContentVersions.php`,
`RevisionStore.php`, `ContentRow.php` (adapter interface), `Page/Admin/PageContentTarget.php`,
`PageRevisionStore.php`, `Catalogue/Admin/ItemContentTarget.php`, `ItemRevisionStore.php`,
`Generation/Admin/ContentController.php`, `ContentTargetResolver.php`, `PageScreenTarget.php`,
`ItemScreenTarget.php`; tests above.
Changed: `Page/Admin/CmsPageWriter.php`, `Page/Admin/CmsPageAdminRepository.php`, `Generation/GenerateCommand.php`,
`Page/RenderPublishedCommand.php`, `Catalogue/ItemDeletionListener.php`, `Catalogue/Admin/ItemContentTabHook.php`
(only if a class name it uses moves), `Tests/Integration/CmsIntegrationTestCase.php`, item test files using
`ItemContentWriter`, `phpstan-baseline.neon`, `docs/adr/0011-...`, `CHANGELOG.md`.
Removed: `Page/Admin/PageVersions.php` (renamed), `Catalogue/Admin/ItemContentWriter.php` (D1-A),
`Generation/Admin/CmsPageContentController.php`, `Catalogue/Admin/ItemContentController.php`.
Not touched: `Config/schema.xml`, `Config/TheliaMain.sql`, `Config/update/` (D2-A), templates, routes, `I18n/`.

## Risks and pitfalls
- **A revision restored across families.** With D2-B, or a store that forgets the kind, a page revision id restores
  on a product of the same id. Parade: test 3, written before.
- **A spy that sees nothing.** `purgingWith()` builds the writer by reflection and recurses only into
  `PageInvalidator` and `PartialCache`; a purge moved into a new service escapes it and the "nothing purged"
  assertions stay green. Parade: test 9, and one assertion per family that something is purged.
- **Messages merged.** Factoring the flash messages into one wording drops the French of the other family, without
  a failing test today. Parade: test 8; keep both English keys as they are.
- **`testId` and route keys of the screen.** `cms-page-content` / `cms-item-content` and the `component_*` route
  keys drive the templates and the tests; a shared subject that sets them wrong hides the prototype or breaks the
  selectors. Parade: the existing screen tests select by `testId`; check both in the browser.
- **Lookup order.** Resolving the language before the page writes the session's edit language on a 404. Parade:
  the resolver keeps the page lookup first.
- **Stale test container.** The test environment has no debug: constructors change, the container stays old
  (`ArgumentCountError`) or a template stays compiled. Parade: clear `var/cache/test` after each step.
- **Transaction scope.** The page writes its search index and its revision inside the publication transaction; the
  shared `publish()` must keep the target's in-transaction work inside and the purges after the commit, or a
  rolled-back publication purges the cache, or a purge reaches a page whose row is not committed. Parade:
  `PublishPipelineTest::testRefusingToPublishLeavesNoTraceBehind` (170) and an item twin of it in test 6.
- **Callers of `CmsPageWriter`.** Its public signatures stay; a signature change would ripple to the import, the
  commands and 42 test files. Parade: delegate, do not rename.
- **The command keeps no draft** (`GenerateCommand.php:178,187`): unifying "save a generation" into the writer would
  start keeping drafts from the command. Out of scope (D5).

## Estimate
Senior developer with a coding agent, tests and gates included, hours of work (QA included):

| Task | Hours | Bound by |
|---|---|---|
| Q4a-0 tests 1-8 (and the helper of 9) | 3.5 - 4.5 | the rights matrix and the item generator cases reuse `FakeAiGateway` and the screen helpers; 4.5 if the route table needs its own base class |
| Q4a-1 versions shared | 1.5 - 2 | D2-A; +4 with D2-B (migration, models, data move, cascade in code) |
| Q4a-2 writer shared | 3 - 4.5 | D1-A; 4.5 if the item callers in tests need more than a type change |
| Q4b-1 one controller | 3 - 4 | D3-A; the screen targets reuse `ContentSubject` as it is |
| Q4b-2 docs, baseline, browser check | 1 - 1.5 | |
| **Total** | **12 - 16.5** | |

Contingency on the whole of Q4: **+4 h (about 25 %)**, for a behaviour difference the new tests reveal and that
needs a decision, or a stale cache or Propel rebuild costing a run. Total with contingency: 16 to 20.5 h, about 2.5
to 3 days at 7 h. The roadmap's "2 to 3 days with Q4b, +30 %" (2.6 to 3.9 days) is confirmed at its low end: the
work itself is about 2 days, not 3; D2-B or D4-B in the same PR add 4 h and 2 h.

## Out of scope
F2, F4, N23 themselves; merging the exception classes of the two families; the third copy of "save a generation"
in `GenerateCommand`; `TranslationSources::forPage` / `forItem` and `GenerationTargets::modelDocument` reading
`CmsPageContentQuery` directly (`Generation/Admin/TranslationSources.php:39-56`,
`Generation/GenerationTargets.php:52-67`), which the `ContentTarget` could answer later.
