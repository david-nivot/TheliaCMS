# Plan: the answers of a form (N41 to N45)

Status: decided (2026-11-09, by the maintainer), coded lot by lot on `feat/form-answers`; done: N45, N41, N42, N43. Read on `main` at 9009ac8 (2.18.0). Wave 5 of the
roadmap, family "formulaires". Requested by the maintainer; the five lots were first read on
a896919 and checked again on 9009ac8 (line numbers below are the current ones). Each lot updates
this plan in its own commit.

Abbreviations: SC = `Form/Admin/CmsSubmissionAdminController.php`, SR = `Form/Admin/SubmissionRepository.php`,
SX = `Form/SubmissionExport.php`, ST = `templates/backOffice/default-twig/forms/submissions.html.twig`,
FL = `templates/backOffice/default-twig/forms/list.html.twig`, TC = `TheliaCMS.php`.

## State verified on 9009ac8

### Storage

- Table `cms_form_submission` (`Config/schema.xml:499-520`): id, form_id, locale, email, data (CLOB,
  JSON), ip_hash, created_at, updated_at. **No status column.** Indexes (form_id, created_at)
  (`schema.xml:511-514`, `Config/TheliaMain.sql:318`) and email (`TheliaMain.sql:319`).
- `data` is a list of `{name, type, label, value[, granted_at]}` (`Form/SubmissionData.php:32`,
  decoded at :57). **The value stored is the label picked, not a code**: a choice renamed later
  no longer matches its old answers.
- Field types: enum `Form/FieldType.php:24-54` (text, email, textarea, select, checkbox, radio,
  phone, date, consent, country). `hasChoices()` = select, radio (:81-84); `isDropDown()` =
  select, country (:92-95); `isTickBox()` = checkbox, consent (:101-104).
- Retention: `Form/SubmissionPurger.php:48-52` purges on age only (`created_at` older than
  `RetentionPolicy::cutoff(retention_days)`, 0 = keep, :42-44), deleted forms included (:41).

### Screen and routes

- Prefix `/admin/cms/forms/{id}/submissions`, names `admin.cms.forms.*` (SC:41).
  `submissions` GET (SC:57), `submissions_export` GET `/export.{format}` csv|json (SC:81),
  `submissions_delete` POST `/{submissionId}/delete` (SC:112).
- ST: total `'%count% answer(s)'` (ST:49), one card per answer (ST:67-68), no selection, one
  delete form per card (ST:78-80). FL: count of answers linked to the screen (FL:71-73, :84).
- SR: one filter, email `LIKE %…%` (SR:74-87, :83); 25 per page (SR:28); newest first,
  then id (SR:36-37). `all()` loads every matching row in memory (SR:56-63).
- Delete: `denyUnless(CmsResources::FORM, AccessManager::DELETE)` (SC:115), one activity log line
  with id and form code, no address (SC:126-133).
- CSRF: the template posts `_token` = `assignToken()` (ST:80); the check is central, for every
  non-safe admin request (`Security/CmsAdminGuard.php:146-147`, header `X-CSRF-Token` accepted).
  A new POST route needs the field only, and a line in `Tests/Integration/Security/WriteRoutesTest.php`
  (:59-60, :200).

### Export

- SX: JSON pretty printed (SX:31-34); CSV with UTF-8 BOM (SX:85), **comma hard-coded** (SX:88),
  formulas defused by a `'` prefix (`defused()`, SX:107-113). Columns = the answers present in the
  rows exported, by `name`, first seen first, label of the first seen; a removed question keeps its
  column (SX:37-52). Four fixed headings come from the controller (SC:145-153).
- SC: export takes the email filter only (SC:85-86), is logged `EXPORT` (SC:88-93),
  `Cache-Control: private, no-store` (SC:107).
- No XLSX writer in the host vendor (no phpoffice, no openspout). `ext-zip` and `xmlwriter` are
  loaded on the host and in the CI (`.github/workflows/ci.yml:125`); the module's
  `composer.json` requires only `php >= 8.3`.

### Notification

- `Form/SubmissionMailer.php:49` `notifyRecipients()` sends the answers, a subject and an intro
  (:57-58); **no link to the back office**.

### Rights

- Seven resources in `Security/CmsResources.php:29-37`, `admin.cms.form` at :31, `all()` :42-45,
  declared to the container by `Security/CmsAdminResourcesCompiler.php:21-37`.
- The guard requires VIEW on the resource of the path for every request (`CmsAdminGuard.php:140`,
  `resourceFor()` :172). Reading and exporting the answers need nothing more; deleting needs
  DELETE (SC:115).
- Access types of the core: VIEW, CREATE, UPDATE, DELETE only (`AccessManager.php:24-27`). An
  "export" right is therefore a resource of its own, not an access type.
- New resources are seeded in PHP, not SQL: `TC::update()` (:194) then `seedAdminResources()`
  (:203, also `postActivation` :112, body :275-300). **A new resource is given access 0 on every
  existing profile** (:293-297). A resource split off `admin.cms.form` must copy the access of
  `admin.cms.form` when it is created, or the upgrade silently takes the answers away from every
  profile but the super administrator.

### Tests in place

Unit: `Tests/Unit/Form/SubmissionExportTest.php` (7), `SubmissionDataTest`, `SubmissionValidatorTest`,
`RetentionPolicyTest`. Integration: `Tests/Integration/Form/FormSubmissionTest`, `FormPurgeTest`,
`SenderBurstTest`, `FormPageCacheTest`, `Security/WriteRoutesTest`. **No test drives the screen,
the delete or the export of the answers.**

## Decided (2026-11-09, by the maintainer)

1. **Statuses (N41):** three fixed, new, handled, archived. No labels per form.
2. **Rights (N45):** two resources, `admin.cms.form.answers` (VIEW read, UPDATE status, DELETE
   delete) and `admin.cms.form.answers.export` (VIEW export). Existing profiles keep their access
   at the upgrade.
3. **No XLSX (N43):** the CSV only, with the separator Excel expects. The step M of N43 (XLSX,
   columns chosen, one sheet per language) is out of this plan.
4. **Delivery:** one branch `feat/form-answers`, one commit per lot in the order below, one PR,
   then release 2.19.0 on agreement.

## Frozen with the decisions (defaults not objected to)

- Order: N45, N41, N42, N43, N44 (rights first, so every new gesture is born with its right;
  the status before the filter that reads it; the filters before the export and the summary
  that reuse them).
- N41: status `new` by default, set to `new` on every existing row by the migration
  (`Config/update/2.19.0.sql`, replayable through `information_schema`, plus `schema.xml` and
  `TheliaMain.sql`); the status never suspends the purge; a status change and a bulk gesture write
  one activity log line each, without address. The default list shows every status but archived;
  archived is a filter value. The recipient mail gets a link to the answer on the screen (no new
  personal data in the mail).
- N42: a `SubmissionFilters` read from the query like `Page/Admin/PageFilters.php`, passed to one
  search used by the screen, the export and the summary. Period on `created_at`, status, and the
  value of a field limited to select, radio and country, matched in PHP on the decoded rows of the
  period, said slow beyond a few thousand answers (to measure). State in the address (GET), works
  without JavaScript, filter bar on the model of the page list (one line).
- N43: separator `;` when the language of the screen is French, `,` otherwise; filters of N42;
  rows read in batches (no `all()` in memory); every column, as today; every cell still defused,
  export still logged and `no-store`.
- N44: a read service (not in the controller) that counts by day, week or month and by value of a
  choice field; tiles and plain HTML bars, no chart library; follows the filters of N42 and counts
  the statuses of N41.
- N45: the screen hides the link and the count of answers without VIEW on the answers, the export
  buttons without VIEW on the export; `seedAdminResources()` copies the access of `admin.cms.form`
  onto a resource split from it when it creates it, and only then.

## Lots, in order

### 1. N45: rights to read, export and delete the answers (S), done

Resources and their titles (fr, en), `resourceFor()` of the guard for the submission paths, export
check, hidden links and buttons, access copied at creation. Tests: a profile with the form but
without the answers is refused the screen and the export (403) and sees no link; a profile with
the answers but not the export sees no button and gets 403 on the export address; after the
upgrade, a profile that had `admin.cms.form` keeps reading and exporting.

Done: `CmsResources::FORM_ANSWERS` and `FORM_ANSWERS_EXPORT`, with `splitFrom()` naming the right
each came from; the guard maps `forms/{id}/submissions…` to the answers (`resourceFor()`); the export
checks its own right, the delete `FORM_ANSWERS` DELETE; `seedAdminResources()` copies the access of
the older right when it creates a split one (`accessOf()`); the Editor profile gets both. Links and
buttons hidden without the right (`may_read_answers`, `may_export`). The dashboard keeps its count
of answers: a number, no personal data. Tests: `SubmissionRightsTest` (4), `AdminResourcesUpdateTest`
(split right); six sabotages, each one red. README (Permissions) and the in-app help updated.

### 2. N41: status and bulk actions (M), done

Column, migration, badge, change of status by POST, selection with "mark as handled", "archive",
"delete" (on the model of `Page/Admin/CmsPageBulkController.php` and `PageBulkAction.php`).
Tests: default `new`, change by one and by many, rights (UPDATE, DELETE), log line without address,
an archived answer still purged at its age.

Done: column `status` (`Config/update/2.19.0.sql`, replayable, played twice on the host),
`SubmissionStatus`, `SubmissionFilters` (email and status, read by the screen, the export and the
gestures; N42 extends it), `SubmissionBulkAction`; routes `submissions_status` and `submissions_bulk`;
tabs Current (new and handled, the default), New, Handled, Archived, All, each counting for the
address searched; the export takes the status shown and the JSON carries it; one admin log line per
gesture, filed under `admin.cms.form.answers` (the export and the single delete too), never an
address; the recipients' mail links to `…/submissions#cms-submission-<id>`. Changed from the plan:
an "All" tab, so a request to see one's data finds the archived answers too; no index on the
status (a few thousand answers per form). Tests: `SubmissionStatusTest` (6), `SubmissionFiltersTest`
(4), `FormSubmissionTest::testTheMailLinksToTheAnswerItWasKeptAs`; nine sabotages, each one red
(one stayed green first, the test data fixed). Checked in the browser at 1440 px in French: tabs,
badges, a selection marked as handled by the bulk form.

### 3. N42: filters by period, status and value (S), done

Tests: each filter alone and together, the same rows on the screen and in the export, a renamed
choice says so (old label not matched), address kept by the pagination.

Done: `SubmissionFilters` reads `from`, `to` (days, both included, put back in order) and
`answer[<name>]` (several questions at once, all to match); `parameters()`, `onStatus()`,
`without()`, `cleared()` and `hiddenFields()` carry them through the tabs, the pages, the chips
and the forms of the gestures. `SubmissionRepository::search()` returns a `SubmissionSearch`, the
answers asked for matched once per request in PHP (ids and JSON read 500 at a time on the id),
shared by the page, the count, the tabs (which now count for every filter) and the export.
The values offered are the ones the answers hold (`choiceAnswers()`, read by
`SubmissionChoices`), any language, marked "no longer offered" when no current choice of the form
has them (a country is never marked). Filter bar on one line on the model of the pages, its styles
moved to `_filter_bar.css.twig` (shared with the page list), `page-filters.js` reused; a menu at
the end of the bar opens to the left (`cms-filter-menu__panel--end`). Changed from the plan: the
status stays the tabs of N41, not a menu. Measured on the host (MariaDB, five answers per
submission): 5,000 submissions, filter 38 ms and values 30 ms; 20,000, 201 ms and 115 ms; memory
+3 MB. Tests: `SubmissionFiltersTest` (+4), `SubmissionFiltersScreenTest` (3); eleven sabotages,
each red (one stayed green first, the test data enriched). Checked in the browser at 1440 px in
French: menus, chips, tabs, a selection marked as handled back on the filtered list.

### 4. N43: export for Excel, filtered (S), done

Tests: separator by language, BOM kept, filters applied, batches (memory bounded on a few thousand
rows), formulas still defused.

Done: `SubmissionExport::separatorFor()` (`;` for a French screen, the locale of the translator read
by `AdminControllerSupport::screenLocale()`, `,` otherwise); `writeCsv()` and `writeJson()` write to
a stream, the CSV reading the submissions twice (columns, then rows) from a function rather than
holding them; `toCsv()` and `toJson()` kept on top of them. `SubmissionRepository::all()` replaced
by `each()`: the ids of the list first (an answer arriving meanwhile shifts nothing), then the rows
500 at a time, Propel's pool cleared after each batch. The controller answers a `StreamedResponse`,
logged with the count of the search, still `no-store`; the tests read it through
`getInternalResponse()`. Tests: `SubmissionExportTest` (+3), `SubmissionExportFileTest` (2: Excel
in both languages with filters, BOM, formula; 1,201 rows in 1 + 3 statements, newest first, none
twice); eight sabotages, each red.

### 5. N44: summary of the answers (M)

Tests: counts by period across a month boundary, split of a choice field, follows the filters,
statuses counted.

## Estimate (pilot days, read on 9009ac8)

| Lot | Days |
|-----|------|
| N45 rights | 0.5 |
| N41 status and bulk actions | 0.75 |
| N42 filters | 0.5 |
| N43 export | 0.25 |
| N44 summary | 0.75 |
| Contingency (rights migration on real profiles, JSON filter cost) | 0.5 |
| **Total** | **3.25** |

## Sessions (one fresh context each)

1. N45 then N41 (rights, schema, bulk actions).
2. N42, N43, N44.
3. Full suites, browser check at 1440 px in French, PR, release on agreement.

## Not checked

- The cost of reading the JSON for the summary (N44); the filter is measured under N42.
- The upgrade of real profiles on a base with custom profiles.
