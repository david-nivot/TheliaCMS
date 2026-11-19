# Plan: the right to publish, then concurrent edits (F4)

Status: decided (2026-11-19, by the maintainer), coded on `feat/f4-publish-right`; step S done,
step M to come in its own PR. Read on `main` at 758282b (2.34.0). Wave 5 of the roadmap, lot F4
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
