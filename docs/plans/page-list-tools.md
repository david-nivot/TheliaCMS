# Plan: the tools of the page list (N31 to N36)

Status: decided (2026-11-06), coded lot by lot; N36, N31, N34, N33 and N32 done. Read on `main` at 705aa2b
(2.15.0 + #117). Wave 5 of the roadmap, family "editor". Goal: ship in the same release as
N51, N52, N54 (2.16.0). Each lot updates this plan in its own commit.

## State verified on 705aa2b

- Filters: `Page/Admin/PageFilters.php` knows `q`, `status[]`, `visibility`, `page`, `open`
  (constants 33-37); `fromRequest` 72, `isFiltering` 106, `advancedCount` 122, `toQueryParams`
  133-159 (default values left out), `withoutFilter` 161-169. A filter turns the tree into a flat
  list of results (106): no move arrows, no tree.
- Bar: `templates/backOffice/default-twig/pages/_filters.html.twig` 14-60 (visibility 24-32,
  status boxes 46-59), chips 62-74 from `PageFilterPresenter::chips` (134).
- Query: `CmsPageAdminRepository::matching` (~290; i18n left join 296; models out 295);
  results sorted by title in SQL (79); the tree by position (333-334).
- Columns ready and indexed: `cms_page.tag_id` (schema 74, index 140), `cms_page.page_type`
  (42, index 143). `PageListPresenter` already loads all tags in one query (134-143) and
  receives `PageTypeRepository` (44).
- Dates: `cms_page.created_at/updated_at` (timestampable, schema 155), `publish_at/unpublish_at`
  (75-76, shared by languages), `cms_page_content.published_at` (173) and `updated_at` (189).
  None indexed. A content save does NOT move `cms_page.updated_at`
  (`ContentWriter::saveContent` 69-87 saves the content row only).
- Authors: `created_by/updated_by` (84-85) are left empty by the content save (so by the
  generator), the import (`ImportExport/Step/PagesStep.php`), the seeders, visibility, move,
  trash, restore, set as home. Not reliable today: N32 first makes the module fill them on every
  write path (decided 2026-11-06), then adds the author filter.
- Status rule: `PageStatusCriteria::sqlFor` (98), `joinContent` (52), kept equal to
  `PageStatus::resolve` by `PageStatusCriteriaTest`.
- Translation badges (N36): `pages/list.html.twig` 179-183 "To be translated" (title empty in
  the language, `PageListPresenter` 170) and 184-188 "To be translated again" (`StalePages::ids`,
  presenter 65 and 172, link to the builder), both shown together.
- Return to the list: the edit link of a row (`PageListPresenter` 190) and the back links of
  `pages/edit.html.twig` 31-32, `trash.html.twig` 14, `content/edit.html.twig` 55 carry no
  filter; the row buttons already do (presenter 56-57, `backToList` 671-677).
- Bulk: no bulk on pages. Pattern to copy: media, `admin.cms.media.bulk`
  (`Media/Admin/CmsMediaAdminController.php` 379), `<input name="images[]" form="…">` per card,
  `action` select, CSRF by `CmsAdminGuard`, permission per action in the switch (403, 418, 434),
  confirm dialog in `media-list.js`, `MediaBulkTest`. Writer: `CmsPageWriter::assertPublishable`
  (367), one transaction per page at publish (`ContentWriter` 191-214); only
  `toggleVisibility` (795), no "set visibility"; no "set tag" method.
- Cost: `Tests/Integration/Page/PageListingCostTest.php` asserts a constant query count
  (tree, search). Every lot below extends it.

## Decided (2026-11-06, by the maintainer)

1. Scope of 2.16.0 (decided 2026-11-06 by the maintainer): all six, N35 included.
2. N31: one tag and one type at a time (a select each), with "No tag".
3. N34: counters count the whole site (models and trash out), not the current filters, so a
   number does not move when a box is ticked. Zero stays a tickable box.
4. N33: a sort turns the tree into the flat list, like a filter, with a chip "Sorted by …" that
   goes back to the tree. Sorts: title, modified, created. No sort by status.
5. N32 and N33 "Modified": the latest of the page and of its content in the screen language
   (`GREATEST(cms_page.updated_at, cms_page_content.updated_at)`), since a content save does not
   move the page.
6. N36: two distinct labels, both shown when both are true: "Settings to translate" (no title
   in the language, links to the settings) and "Content to translate again" (source changed,
   links to the builder).
7. N35 order: hide/show and tag (set, not toggle), then trash (parents
   and children counted for real in the confirmation), then publish/unpublish (report page by
   page through `assertPublishable`).

## Lots, in order (one branch, one commit per lot, one PR for the six)

The lots share `PageFilters`, `_filters.html.twig`, `list.html.twig` and the presenter: they
cannot run in parallel. Each commit adds its CHANGELOG entry. One PR for the six lots, opened
after N35 (decided 2026-11-06 by the maintainer).

### 1. N36: two distinct translation labels (S), done
- `list.html.twig`: "Settings to translate" (`cms-page-untranslated-<id>`) links to the
  settings in the language, "Content to translate again" (`cms-page-stale-<id>`) to the
  builder; FR "Réglages à traduire", "Contenu à retraduire". The old keys "To be translated"
  and "To be translated again" are gone from the catalogues (used by the list only).
- Tests: `PageListTranslationTest` (label, text, link, gone once named),
  `StaleTranslationTest::testAPageWithBothReasonsShowsBothLabelsEachLeadingToItsScreen`
  (both shown, each link, naming the page settles one reason only).

### 2. N31: filter by tag and by type (S), done
- `PageFilters`: `tag` (id, or `none` read as `NO_TAG` = 0), `type` (code checked by
  `PageTypeCode::isValid`), in `isFiltering`, `advancedCount`, `toQueryParams`,
  `withoutFilter`. Strict in two steps: `fromRequest` keeps only the shape, then
  `knowing($tags, $types)` (called by `PageListPresenter::present` before anything else) drops a
  tag or a type the site does not have; `none` is dropped on a site without tags.
- `CmsPageAdminRepository::matching`: `filterByTagId` (or `tag_id IS NULL`, the foreign key sets
  null on delete) and `filterByPageType`.
- `PageListPresenter` reads the tags and the types once and passes them to the bar, the chips,
  the rows and the page templates (it read the tags in `rows()` and the types in
  `pageTemplates()` before).
- Bar: "Tag" and "Page type" selects with linked labels, shown only when there is a choice (at
  least one tag; two types); chips "Tag: …", "Without a tag", "Type: …"; a line "Flat list,
  without the tree" next to the result count, shown for every filter. Search box `col-lg-4`,
  the selects share the row, buttons `col-lg-auto`.
- Pagination and row actions keep both through `toQueryParams` (`filter_query`, `route()`).
- Tests: `PageFiltersTest` (reading, `none`, removal, `knowing`), `PageListTagTypeFilterTest`
  (each filter, `none`, selects and chips over HTTP, unknown values ignored, row actions and
  the parameters of the pagination keep both), `PageListingCostTest::testFilteringByTagAndTypeCostsTheSameWhateverTheNumberOfMatches`.

### 3. N34: counters per status and return to the filtered list (S + S/M), done
- Counters: `CmsPageAdminRepository::statusCounts($locale)`, one statement (`withColumn` of
  `COALESCE(SUM(CASE WHEN <sqlFor(status)> THEN 1 ELSE 0 END), 0)` per state on the content join
  of `PageStatusCriteria`), live pages without models; passed to `PageFilterPresenter::present`
  (`count` of each status option); label "Draft (4)", count in
  `cms-pages-status-count-<state>`. Whole site, not the current filters (decision 3).
- Return: parameter `return` = `http_build_query(toQueryParams())`
  (`PageFilters::toReturnParameter`, null for the plain list). Read by `fromReturnParameter`:
  over 1000 characters, a key outside the filters, or a value of the wrong shape (list for a
  word, word for a list) gives the plain list; otherwise `fromRequest`, so it is rebuilt from
  filters and never echoed. `PageFilters::returnQuery($request)` passes it on, rebuilt.
- Carried by: the edit and content links of a row (`PageListPresenter`, only when filtering or
  unfolded); the settings screen (`back_url`, `return_query` merged into the content link and the
  publish, unpublish, duplicate, set as home, remove AI mark and documents forms; the settings
  form posts to its own address); `backToEdit` and `CmsPageDocumentController::backToTab` after a
  save, publish or document change; `backToList` reads `return` first (set as home); the content
  screen through `PageScreenTarget::parameters()` (every route, `screenUrl()` after an action, the
  back link to the settings), built by `ContentTargetResolver` from the request.
- Not carried: the trash screen and its back link, the header zone (no row on the list).
- Tests: `PageStatusCriteriaTest::testTheCountersOfTheListAreThePhpStatesOfEveryLivePage`,
  `PageListingCostTest::testCountingTheStatesCostsTheSameOnASmallSiteAndOnABigOne` (one
  statement), `PageFiltersTest` (round trip, refused returns, rebuilt return),
  `PageListReturnTest` (row to settings to content and back, publish keeps it, foreign return
  ignored, counts on screen with and without a ticked box).

### 4. N33: sort and two columns (M), done
- `PageSort` enum (title, modified, created; `startsDescending()`: a title from A, a date from
  the latest). `PageFilters`: `sort` and `dir` (`asc`, `desc`, anything else is the starting
  direction), `isDescending()`, `sortedBy()` (same sort reverses, another starts in its own
  direction, page 1); `dir` written only when it is not the starting one; both in the return
  parameter; a sort counts as filtering (flat list), `withoutFilter(SORT)` drops both.
- `CmsPageAdminRepository::ordered()`: raw ORDER BY from the enum only (no input reaches the
  SQL), rows with nothing to sort on last in both directions (`CASE WHEN … THEN 1`), id as tie
  break. Modified = `GREATEST(COALESCE(page, content), COALESCE(content, page))` on the content
  join of `PageStatusCriteria` (one join for states and sort); the row date is
  `PageListRow::latestOf()` from the content already read, same rule.
- Columns "Modified" (`d/m/Y H:i`, `<time>`) and "Type" (name, or the code when the type has no
  title; `d-none d-xl-table-cell`). Headers "Title" and "Modified" are links with `aria-sort` on
  the sorted one only. "Sort by" select on the second row of the bar, next to the status boxes
  (five selects on one row cut their values at 1440 px); it starts in the starting direction
  (`dir` not carried by the form). Chip "Sorted by …, A to Z" apart from the filter chips
  (`sort_chip`, after them and without "Showing only": a sort keeps every page) and a link
  "Reverse the order" (the creation date has no header). Search box `col-lg-3` (was 4: "All tags" was cut at
  1440 px since N31).
- Tests: `PageFiltersTest` (reading, direction, address, `sortedBy`, removal),
  `PageListSortTest` (each sort both ways, empty titles last, modified from the content,
  headers and `aria-sort`, chip, reverse, columns, row forms and the redirect of "Hide" keep
  `sort`/`dir`, the return of the settings), `PageListingCostTest::testSortingByTheLastChangeCostsTheSameWhateverTheNumberOfMatches`.
- Browser at 1440 px: table 1120 px, no horizontal scroll, actions pinned. At 1180 px the type
  column is gone and the table scrolls 122 px under the pinned actions.

### 5. N32: authors filled everywhere, then filter by date and by author (M+), done

Step A, authors (decided 2026-11-06): every write of the module that changes a page or its
content sets `cms_page.updated_by` to the admin acting; every creation sets `created_by`.
- Paths to cover (left empty on 705aa2b): content save `ContentWriter::saveContent` (so the
  generator and Modifier à la main), `CmsPageWriter` `toggleVisibility` (795), move, `moveToTrash`,
  `restore`, `setAsHome`, `copyOf` (updated_by), `HeaderZone` (67), import
  `ImportExport/Step/PagesStep.php` (the admin who runs it), `PageHeaderTranslator` (already set).
- One place to set it, not one call per path: a small `PageAuthorship` service (admin id from
  the security context, or an explicit id), called by the writers; checked by a test per path.
- Without an admin (console import, seeders at install, a generation run by the messenger or the
  scheduler): write null, never a guessed id. To check before coding: whether a generation can
  run outside a request (then pass the admin id that started it, as the run row already holds).
- A content save does not save the page row today: setting `updated_by` must not trigger the
  page events and cache purges of a page save; use a column update (or check the listeners of
  `CmsPage::save()` first). If the page row is saved anyway, `updated_at` moves with it and
  decision 5 can drop the GREATEST.
- Existing rows (decided): an update SQL of the new version fills an empty `updated_by` from
  `cms_page_content.draft_updated_by` of the latest draft, replayable; `created_by` stays empty.
- Tests: one integration test per write path (updated_by equals the admin), one for "no admin"
  (null).
- Done: `Page/PageAuthorship` (`author()` from the security context, null without an admin;
  `stamp()` on a page about to be saved, `created_by` when new; `sign()` a column update of
  `updated_by` alone, no page event, no `updated_at`, for the writes that do not save the page
  row). Stamped: `saveDraft`, `saveSeo`, `saveFields`, `toggleVisibility`, `move` (the siblings
  whose position changed only), `moveToTrash` and `restore` (the whole branch), `copyOf`
  (duplicate, start from a model), `PageHeaderTranslator`, `HeaderZone::ensure`, `PagesStep`.
  Signed: content save, publish and unpublish (`PageContentTarget::afterSaved/afterPublished/
  afterUnpublished`, the header zone through it), `removeProvenance`, `setAsHome`. Not signed:
  the renderings done again (`renderDraftAgain`, `renderPublishedAgain`: no one's work), the
  re-addressing of descendants, the documents of a page, the seeders. A generation runs in the
  request of the admin who started it, or from `GenerateCommand` (nobody): no messenger, no
  scheduler. `Config/update/2.16.0.sql` fills an empty `updated_by` from the content changed
  last whose `draft_updated_by` is set (highest id at the same second), replayable.
  `PageAuthorshipTest` (each path, an author then another, nobody gives null),
  `AuthorBackfillMigrationTest` (latest draft, tie, a page that names someone, played twice).

Step B, filters:
- One filter: which date (modified, created, published, online from, taken down on), from, to,
  two `type=date` fields with labels and format hint; ISO in the URL; invalid date ignored,
  swapped bounds swapped back. Time zone: check how the columns are stored before coding.
- Published date is per language (`cms_page_content.published_at`); publish window is shared:
  say so in the help.
- Author: a select of the admins who wrote at least one page (`SELECT DISTINCT updated_by`
  joined to `admin`), an id with no account shown as "deleted account"; filter on `updated_by`
  only ("last modified by", decided).
- Tests: each date, bounds inclusive, invalid input, author, deleted account, cost constant.
- Done: `PageDate` enum (modified, created, published, publish_on, unpublish_on; `sql()` from
  the enum only, the content join of `PageStatusCriteria` for modified and published; the sort
  by last change reads the same expression). `PageFilters`: `date`, `from`, `to` (ISO day,
  `!Y-m-d` read back equal, so 2026-02-31 is no day; bounds swapped back; a date without a day
  is no period; `date` written only when it is not "modified") and `author` (an id, dropped by
  `knowing()` when no live page names it, a deleted account kept); both in the return, the
  chips, `advancedCount`, `withoutFilter`. SQL: `>= day 00:00:00` and `< next day 00:00:00`,
  compared as stored: TIMESTAMP columns written by PHP in the shop time zone (Europe/Paris on
  the host), shown by Twig in the same one. `CmsPageAdminRepository::authors()`: one statement,
  `DISTINCT updated_by` of the live pages left joined to `admin`, null for a deleted account,
  by name with the deleted ones last. Bar: a third row, "Date" select with its help (publication
  per language, scheduled dates shared), "From" and "To" (`type=date`, ISO hint for a browser
  without a picker), "Last changed by" (shown when someone changed a page). Chips "Last
  modified: from 01/02/2026 to 10/02/2026" and "Last changed by …", "Deleted account #67".
- Tests: `PageFiltersTest` (periods, address, authors, removal, `knowing`, return),
  `PageListPeriodAuthorTest` (each date both days included, content wins the last change,
  publication of the language listed, author, screen and chips, deleted account, unknown author
  and impossible day ignored), `PageListingCostTest` (period and author cost constant, authors in
  one statement).
- Browser at 1440 px in French: the third row fits, no horizontal scroll; deleted account 67 of
  the dev base listed and filtered.

### 6. N35: bulk actions (L, next release)
- Route `admin.cms.pages.bulk`, `pages[]` per row (flat list only), action select, permission
  per action, confirmation dialog, flash report page by page. Writer methods `setVisible` and
  `setTag` to add. Steps per decision 7.

## Not checked
- A shop whose PHP time zone differs from the one of its database session: the dates are
  compared as PHP wrote them, which holds as long as the session time zone does not change
  between the write and the read.
