# Plan: the right to publish, then concurrent edits (F4)

Status: decided (2026-11-19, by the maintainer); step S done (2.35.0), step M inventoried on
`feat/f4-concurrent-edits`, coded there (see "Done" under step M). Read on `main` at 758282b (2.34.0). Wave 5 of the roadmap, lot F4
("Droit de publier, concurrence"), after Q4 (one controller and one writer for the page and the
item of the catalogue, 2.13.0) and N29 (`may_change` and `may_publish` apart in the templates,
2.13.0). Each step updates this plan in its own commit.

Abbreviations: PC = `Page/Admin/CmsPageAdminController.php`, PB = `Page/Admin/CmsPageBulkController.php`,
PA = `Page/Admin/PageBulkAction.php`, PT = `Page/Admin/CmsPageType.php`, PW = `Page/Admin/CmsPageWriter.php`,
CC = `Generation/Admin/ContentController.php`, CS = `Generation/Admin/ContentScreen.php`,
CT = `Catalogue/Admin/ItemContentTabHook.php`, CW = `Content/Editing/ContentWriter.php`,
CR = `Security/CmsResources.php`, TC = `TheliaCMS.php`, EP = `Settings/EditorProfileSeeder.php`.

## State verified on 758282b

### Rights

- Nine resources (CR:29-46), `all()` (CR:52-65), declared to the container by
  `Security/CmsAdminResourcesCompiler.php`. Access types of the core: VIEW, CREATE, UPDATE, DELETE.
- Seeding in PHP, no SQL: `TC::update()` (:211) and `postActivation` (:129) call
  `seedAdminResources()` (:304). A new resource gets access 0 on every profile, **except a right
  named in `CR::splitFrom()` (:76-82), which gets the whole access mask the profile has on the
  right it came from** (`accessOf()`, TC:342). A profile that only reads pages (VIEW) would get
  VIEW on the new right: the new right must therefore be checked on UPDATE, never VIEW.
- EP grants every access on `admin.cms.page` to the seeded Editor profile (EP:64-76).
- The guard requires VIEW on the resource of the path for every `/admin/cms` request; the
  publication routes sit under the pages, so they keep needing VIEW on `admin.cms.page`.

### Where publication is decided today (all on UPDATE of `admin.cms.page`)

| Gesture | Where | Check |
|---|---|---|
| Publish a page (settings screen) | PC:233 `publish()` → `publishPage()` PC:455 | `denyUnless(UPDATE)` PC:235 |
| Take a page offline (settings and header zone) | PC:244 `unpublish()` | PC:246 |
| "Save and publish" | `asksToPublish()` PC:480 | `mayPublish()` PC:491 = UPDATE |
| Show or hide a page (list row) | PC:272 `toggleVisibility()` | PC:274 |
| `visible`, `publishAt`, `unpublishAt` of the settings form | PT:127-150, applied PC:602-605 | UPDATE of the form |
| Bulk show, hide, publish, unpublish | PB:81-85, PA:37 `access()` | UPDATE (DELETE for the bin) |
| Publish the content (page and catalogue item) | CC:342 | `denyUnlessMayChange()` CC:554 |
| Take an item offline (catalogue tab) | CC:384 | same |
| Buttons on the content screen | CS:83 `$mayPublish = $mayChange` | UPDATE |
| Unpublish link of the catalogue tab | CT:106 | UPDATE |
| Buttons of the page settings | `pages/edit.html.twig:216`, `:242` | `may_publish` |
| Bulk menu of the page list | `pages/list.html.twig:195-202` | none: every option shown |

Every path ends in `PW::publish()`/`unpublish()` (PW:341, :367) then `CW::publish()`/`unpublish()`
(CW:191, :240). Visibility and the publication window take a page off the site as surely as an
unpublish (`Page/PublishedAccessibilityFinder.php:89-92`, `Sitemap/SitemapThemeHook.php:171-174`).

### Out of reach of a right

`thelia_cms:publish` (`Page/PublishPagesCommand.php`) and the site import (`ImportExport/ImportCommand.php`,
which writes the published version) run without a logged-in administrator: whoever has the console
publishes. Unchanged by this plan.

### Tests in place

`Tests/Integration/Security/ContentRightsTest.php` (`adminWith([...])` builds a profile from a
map of rights), `AdminScreenTestCase`, `Tests/Integration/Module/AdminResourcesUpdateTest.php`
(split right copied at the update), `SubmissionRightsTest` (model of N45).

### Concurrency (step M)

`cms_page_content` and `cms_item_content` carry `draft_updated_by` (`Config/schema.xml:174`, :252)
and the timestampable `updated_at`; no version counter. `Page/PageAuthorship.php` stamps the author.
Every draft write goes through CW (Q4).

## Decided (2026-11-19, by the maintainer)

1. **Form of the right:** a resource of its own, `admin.cms.page.publish`, checked on UPDATE.
   `splitFrom()` copies the access unchanged; only a profile that could change the pages can
   publish after the update.
2. **Upgrade:** `admin.cms.page.publish` split from `admin.cms.page` in `splitFrom()`: every profile
   keeps what it could do, no SQL. The seeded Editor profile gets it.
3. **Scope of step S:** every path to `CW::publish()`/`unpublish()` (page, catalogue item, bulk
   actions, "Save and publish"), plus visibility (row toggle, bulk show and hide) and the
   publication window (`visible`, `publishAt`, `unpublishAt` disabled in the form without the right).
4. **Step M:** a version token carried by the form, a save made on a stale version refused instead of
   overwriting, a banner "changed by X N minutes ago"; no lock.

## Frozen with the decisions (defaults not objected to)

- Publishing needs UPDATE on `admin.cms.page.publish` alone (plus the VIEW of the guard): a reviewer
  may publish without changing. Changing still needs UPDATE on `admin.cms.page`.
- One helper reads the right for the controllers (`AdminControllerSupport::mayPublish()`,
  `denyUnlessMayPublish()`), used by PC and CC; PB checks `PageBulkAction::resource()`; CS and CT read
  the resource through the security context as they already did. No `isGranted(PAGE, UPDATE)` left
  standing for a publication.
- "Save and publish" without the right is not offered; posted anyway, the draft is saved and the
  page is not published (as today for a model).
- PA gets `resource()` next to `access()`: show, hide, publish, unpublish → the new right; tag →
  pages; bin → pages DELETE. The bulk menu hides the options the profile may not use.
- Restoring a page from the bin (PC:361) puts the published content of its branch back online (the
  bin keeps it, PW:423): it needs the right to publish, whatever the branch holds. Setting another
  page as home (PC:315) changes what the root of the site shows: it needs the right to publish too.
- Page documents stay under `admin.cms.page` (decision of 2.6.0). Moving to the bin stays DELETE on
  the pages.
- Release: step S is 2.35.0 (no SQL); step M carries its own `Config/update/<version>.sql`.

## Steps, in order

### S. The right to publish (S), done

Resource and titles (fr "CMS : publication des pages", en "CMS: publishing pages"), `all()`,
`splitFrom()`, container declaration, Editor profile; one helper; checks on every gesture of the
table above; `may_publish` from the helper in PC, CS, CT; form fields disabled through a
`may_publish` option of PT (on the model of `is_model`); bulk menu filtered. README (Permissions:
ten resources, the row of the new right, the page row no longer says "publication") and CHANGELOG
(`[Unreleased]`, Added + Changed: what an upgraded profile keeps).

Tests:
- A profile with pages VIEW + UPDATE and no right to publish: 403 on the publish and unpublish of
  a page, the publish of the content of a page and of an item, the unpublish of an item, the row
  toggle, bulk show, hide, publish and unpublish, restore from the bin of a published page, set as
  home; "Save and publish" saves the draft and leaves the page offline; `visible`, `publishAt`,
  `unpublishAt` posted are ignored; no publish button nor option on the screens.
- A profile with VIEW only on the new right is refused the same (the copy trap).
- A profile with pages VIEW and the right to publish publishes; with both, nothing is refused.
- Upgrade: a profile with pages VIEW + UPDATE gets the same on the new right and publishes; a
  profile with pages VIEW only gets VIEW and cannot publish (`AdminResourcesUpdateTest`).
- The seeded Editor profile publishes.

Each test seen red by a sabotage before it is kept.

Done: `CmsResources::PAGE_PUBLISH` in `all()` and `splitFrom()`, declared to the container, titled
(fr, en), granted to the Editor profile; `AdminControllerSupport::mayPublish()` and
`denyUnlessMayPublish()` (message translated); PC publish, unpublish, visibility, set as home,
restore and "Save and publish"; PT `may_publish` option disabling `visible`, `publishAt`,
`unpublishAt` (help on the visibility); PA `resource()` read by PB; CC publish and unpublish; CS and
CT read the new right. Screens: set as home, the visibility of the list rows, the publication
options of the bulk menu and "Restore" in the bin are hidden without the right. README (Permissions)
and CHANGELOG. Tests: `PagePublishRightTest` (6), `ContentRightsTest` (+2, the publication split
from the changes), `PageBulkTest` (+1), `ItemContentScreenTest` (+1), `AdminResourcesUpdateTest`
(split rights compared to their own origin, +1 reader case); `ContentActivityLogTest` and
`ContentScreenTest::testTheGeneratorIsARightOfItsOwn` give their profile the right to publish.
Twenty-two sabotages, each red (one stayed green first: `mayPublish()` read on VIEW; the VIEW test
now reads the settings screen too). Unit suite 2063 green; integration suite 1757 green (7 skipped, 1 incomplete).
Checked in the browser at 1440 px in French, with a temporary profile allowed to change the pages and
not to publish, against the super administrator: on the settings, no "Save and publish", no publish
nor unpublish, no "Set as home page", "Online" and both dates disabled with their help; no "Publish"
on the content screen; on the list, no "Show" or "Hide" and the bulk menu down to the tag and the
bin.

### M. Concurrent edits (M)

Principle, to be read again at the start of the step (line numbers will move):
- A version per draft (`draft_version` INTEGER on `cms_page_content` and `cms_item_content`,
  replayable update file, `schema.xml`, `TheliaMain.sql`); the date of the draft is not enough (two
  saves in the same second, MySQL and MariaDB rounding apart).
- Every form that writes a draft posts the version it read; CW writes with
  `WHERE draft_version = :read` and raises a typed refusal when no row moves; the screen keeps what
  was typed and says who changed the page and when (`draft_updated_by`, `updated_at`).
- A banner on the content screen and the settings of the page when the draft changed since the
  screen was read by someone else.
- To inventory first: every writer of a draft (sections by hand, generation steps, translation,
  restore of a draft, page fields), the requests made by script (`fetch`) and what they do with a
  refusal.

Tests: two saves on the same version, the second refused and nothing lost; a save on the current
version goes through; the banner names the other author; the race proved with two processes
(model of `SenderBurstTest`).

Documentation, in the PR of the step:
- Editors: `templates/backOffice/default-twig/documentation/_fr_FR.html.twig` and `_en_US.html.twig`,
  section `content` (and `troubleshooting` for the refusal): what the banner says, why a save or a
  publication is refused, how to get the typed text back, where a refused generation went (kept
  drafts); same ids in both languages (`DocumentationScreenTest`).
- A drawing of how it works, on the model of `documentation/_brief_flow.html.twig` (inline SVG,
  geometry drawn once, words given by each language, colours of the back office for the light and
  dark themes, `summary` for screen readers): two editors on the same draft, the version read, the
  save that goes through and moves it, the stale save refused with the screen and the banner. Included
  in both languages of the editor documentation, and the same flow in the README.
- README (developers): section "Editing a page" (the version, the refusal, the writers outside the
  content writer: `thelia_cms:render`, the import, the AI mark), the `draft_version` column for whoever
  writes drafts with SQL.
- CHANGELOG (`[Unreleased]`, Added and Changed) and the trap table of the module's
  `.claude/CLAUDE.md` (the two rows the handover of step S left to add).

#### Inventory (read on be085d6, 2.35.0)

Abbreviations of this section: CSC = `Component/Admin/CmsSiteComponentController.php`,
PCC = `Generation/Admin/CmsPageComponentController.php`, ST = `Content/Editing/ContentSteps.php`.

**Draft writers through CW.** All of them end in `ContentRow::saveDraft()` or
`replaceDraftProvenance()` (`Page/Admin/PageContentRow.php:71`, :84;
`Catalogue/Admin/ItemContentRow.php:69`, :82): one place to write with
`WHERE draft_version = :read`.

| Writer | Entry | Reads the draft it changes |
|---|---|---|
| Section by hand: update, remove, move, duplicate, add | CC:194-235 → `byHand()`, `saver()` CC:521 | server side, at the request (`$target->draftDocument()`) |
| Generation, revision of the draft or of a section, translation | CC:91, :118, :149, :263, :300 → `run()` CC:503: `keepDraft()` then `saveContent()` at the end of the stream | at the start of the stream, saved minutes later |
| Restore of a kept draft | CC:242 → `CW::restoreDraft()` CW:115 (`keepDraft`, `saveContent`, `replaceDraftProvenance`) | the kept one |
| Undo, redo | CC:323, :333 → ST `write()` (`saveContent` + `replaceDraftProvenance`, one transaction) | the step |
| Component prototype kept into the page | PCC:151 `keep()` → PCC:187, section appended | server side, at the request |
| `thelia_cms:generate` | `Generation/GenerateCommand.php:193`, :209 (`keepDraft` then save) | at the start of the call |
| Import of the header highlights (console) | `Zone/HeaderHighlightsImporter.php:148`, :151 | none: replaces |

Every screen gesture above, generation included, goes through `ST::record()` (ST:~60), which
wraps the change (ST:59): a second place where the version read can travel.

**Draft writers outside CW, on an existing draft** (the hypothesis of the estimate counted none):

1. `CmsPageWriter::renderDraftAgain()` (PW:302) writes `draft_html` alone from the stored document,
   and the timestampable moves `updated_at`. Called by `thelia_cms:render`
   (`Page/RenderPagesCommand.php:97`) and after a site component is promoted or copied
   (CSC:401, `renderDraftsUsing()`). Race: it reads the document, renders, writes the HTML; a save in
   between leaves the HTML of the old document under the new one. It must write on the version it
   read, without moving it (same document), and report the draft as left when the row moved.
2. `CmsPageWriter::removeProvenance()` (PW:393), route `remove_ai_mark` (PC:259): clears
   `draft_provenance` and `published_provenance`. The gesture says "I reviewed the text and answer for
   it": on a stale screen it removes the mark of a generation the editor never read.
3. Import of a site with the replace option: `ImportExport/Step/PagesStep.php:190-196`,
   `ImportExport/Step/CatalogueContentsStep.php:81-87` overwrite the draft and the published version
   of existing rows (console and import screen). A replacement by intent: it should move the version,
   so that the screens open on the old draft are refused, not be refused itself.

New rows only, out of the token: `CmsPageWriter::duplicate()` (PW:612-619), `Install/LegalPagesSeeder.php:89`,
`Install/HomePageSeeder.php:114`, the sample site seed (`Tests/Browser/sample-site/seed.php`, not
shipped). Raw SQL naming the draft columns (`Media/Icon/IconCitations.php`, `Page/StalePages.php`,
`Content/Site/SiteComponentUsage.php`, `ImportExport/SiteExporter.php`,
`ImportExport/Step/ComponentsStep.php`) only reads them (patterns tried: `update `, `insert `,
`->save(`, `exec(`).

**On the same screens, not a draft:**

- Page settings (PC:227 → `PW::saveDraft()` PW:78, despite its name): title, address, fields, SEO in
  `cms_page` and `cms_page_i18n`, live as soon as saved; `saveSeo()`, `saveFields()` and
  `saveTranslatedHeader()` write there too, alongside a generation (`saveAlongside()`). No draft
  version covers them.
- Publish (CC:340, PC:233) copies the draft as stored at the click (`PageContentRow::publish()`): a
  stale screen publishes a draft its publisher has not read.

**Requests sent by script.** Every draft write by script is in
`templates/backOffice/default-twig/assets/content-editor.js` (CE); none posts anything that names
the version read (the forms carry `_token`, `section`, `position`, `direction`, `component`,
`candidate`, `revision`, the last being a kept draft, not a version).

| Request | Routes | On a non-2xx today |
|---|---|---|
| Generation stream, CE:1046 (`data-cms-generate`: brief, revise, section, translate) | CC:91, :118, :149, :263 (+ PCC:99, which saves a component candidate, not the draft) | throws on a non-`text/event-stream` answer, banner `labels.network` or the status (CE:1096); what was typed stays |
| Section saved by hand, `send()` CE:1334 | CC:194 | generic `labels.network` (CE:1357): a refusal would read as a network failure; the text typed stays in the box |
| Drag of a section, CE:1927 | CC:208 | `window.location.reload()` (CE:1942): no message, the order dropped |
| Forms of the screen, CE:~2011 (update, remove, move, duplicate, add, restore, undo, redo, component keep; also `requestSubmit()` CE:1554, :2721) | CC:194-243, :323, :333, PCC:151 | `form.submit()` (CE:2031): the same write posted again natively, and the browser shows whatever the server answers |

Consequences for the refusal: it cannot be a bare status. The forms post it again natively, so the
server answers a refused write with the content screen itself (banner, what was typed kept), with a
409 status the script reads before falling back; the stream needs an `error` event of its own when the
save at its end is refused, after minutes of generation.

The other scripts write no draft: `page-tree.js`, `linked-pages.js`, `record-picker.js`,
`media-picker.js` read, or write the library (`admin.cms.media.add`); `menu-editor.js` writes a menu
entry; `page-address.js` proposes and checks addresses without writing; `rich-text-brief.js`
writes the outcome ledger of a text, the text itself going into the field and the section form;
`component-generator.js` (`admin.cms.components.site.generate`) writes a component candidate;
`page-documents.js:110` (`XMLHttpRequest`) uploads a page document. No `sendBeacon` nor
`EventSource` (patterns tried: `XMLHttpRequest`, `sendBeacon`, `EventSource`, `requestSubmit`).

**A guard already in place:** undo and redo compare the stored draft with the step
(ST:108, :132, `StepOutcome::DraftChanged`, flashed by CC:478 after a redirect).

#### Decided after the inventory (2026-11-19, by the maintainer)

1. Writers outside CW (renderDraftAgain, removeProvenance, import): renderDraftAgain writes on the
   version read without moving it, and leaves the draft when the row moved; removeProvenance carries
   the version of the screen and is refused when stale; the import moves the version. Cost: the
   +0.25 j of the hypothesis.
2. Generation: the stream saves on the version read when it started, and a refusal at its end keeps
   the answer as a kept draft (`keepDraft` of the answer) rather than losing minutes of a paid call.
3. Publish (CC:340, PC:233): carries the version of the screen, refused when stale (same token, same
   refusal).
4. Settings of the page: banner only (from the draft version and the authorship stamp of the page);
   no refusal for `cms_page` and `cms_page_i18n` in step M. A refusal there needs a second counter
   on `cms_page`: its own step if wanted.

All four as written above.

Decided while coding (2026-11-19, by the maintainer): the banner shows when the screen opens on a
draft (settings: a page) someone else changed within the hour, and after a refusal; a write request of
a screen that posts no version is refused like a stale one.

Done: `draft_version` on both tables (`Config/update/2.36.0.sql`, replayable, played twice on the
dev base; `TheliaMain.sql`, `schema.xml`; `module.xml` goes to 2.36.0 with the release, as for every
SQL step). `DraftLock::claim()` reads the version under `SELECT … FOR UPDATE` and raises
`StaleDraftException`; `ContentRow::claimDraft()`, every `saveDraft()` moves the version. CW:
`saveContent()`, `restoreDraft()` (refused before it keeps anything), `publish()` take the version
read; `keepAside()` keeps a refused answer. Undo and redo carry it. PW: `renderDraftAgain()` writes on
the version it read (`DraftRendering::ChangedMeanwhile`), `removeProvenance()` is refused when stale;
the import moves the version. Screens: `DraftNotice` (version posted, refusal, banner); CC answers a
refused write with the screen and 409, refuses a stale generation before the call and keeps an answer
refused at its end; PCC keep; PC publish, "Save and publish", remove the AI mark; the banner on both
screens. Templates: `draft_version` in every writing form, `data-cms-draft-version` on the screen,
synchronised after each redraw; the script keeps the section dialog open with its text on a 409 and
never posts a 409 again natively. Documentation: editor documentation (fr, en, section
`two-editors`, troubleshooting `change-not-saved`), drawing `documentation/_concurrent_flow.html.twig`,
README (with a sequence diagram), CHANGELOG, traps of `.claude/CLAUDE.md`.
Tests: `DraftVersionTest` (7), `DraftRaceTest` (2, two processes: six saves on one version let one
through; a re-render waits for a save in flight and leaves it), `ConcurrentEditScreenTest` (8);
28 screen tests updated to post the version they read (`draftVersionOf()`). Fourteen sabotages, each
red (two stayed green first: the banner for one's own change and a publication that goes through from
the settings; both tests strengthened). Browser, 1440 px, French, two administrators: banner naming
the other, a section saved from a stale screen refused with the dialog and its text kept (first run:
the screen was redrawn and the text lost, fixed), a stale publication refused, the settings banner,
the publication after a reload; the drawing in both languages. Unit suite 2063 green; integration
suite 1774 green (7 skipped, 1 incomplete), PHPStan and the code style clean.

Estimate read again with the inventory: 1.25 j (design) + 0.25 j (writers outside CW) + 0.5 j
(contingency: the four script paths above, the native fallback of the forms, the stream) = 2 j for step M.

## Estimate (pilot days, read on 758282b)

| Step | Nature | Days |
|---|---|---|
| S right to publish (precedent N45, every path listed above) | reproduce | 0.5 |
| M version token, refusal, banner | design | 1.25 |
| Contingency (M: drafts written by script and their handling of a refusal, not yet inventoried) | | 0.5 |
| **Total** | | **2.25** |

Hypothesis of M: the draft writers all go through CW, as Q4 left them; a writer found outside costs
+0.25 j.

## Sessions (one fresh context each)

1. Step S, full suites, browser check at 1440 px in French with a profile without the right, PR.
2. Step M: inventory of the draft writers, then code, suites, browser check with two sessions, PR.

## Not checked

- The upgrade of real profiles on a base with custom profiles (the dev base has no profile with a
  CMS right).
