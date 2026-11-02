# Plan: duplicate a section in a draft

Status: steps 1 to 7 done on `feat/section-duplicate` (2026-11-02). Each new
test was seen red by breaking the code it covers (nine breakages), then both
suites green. Checked in a browser on the content screen of a page: the copy
shows second in the list and in the preview, without reloading the screen,
and the console stays clean. Not checked: translating a page whose other
language does not hold the copy.
Read on `main` at 83b210e (2.7.1). The design questions are settled (see
"Decisions taken").

## What is asked

The content screen edits, removes, moves and adds a section, but cannot
duplicate one (patterns `duplicate`, `clone`, `copy` give nothing in
`SectionUpdate`, `Document` and the template of the screen). An editor who
wants a second section like the first one adds the component again and types
everything back. Add "Duplicate" next to "Move up" and "Move down".

## What is there today

- `Generation/Admin/SectionUpdate.php`: `apply()` (48), `remove()` (108),
  `move()` (136), `add()` (177). `add()` accepts only a component the site
  offers (`$this->catalog->offered()`, line 180) and logs with
  `$this->log->recordManual()`.
- Two callers, one screen: `Generation/Admin/CmsPageContentController.php`
  (route `/move`, line 301, guarded by `denyUnlessMayPublish()`) and
  `Catalogue/Admin/ItemContentController.php` (`/move`, line 180, through
  `byHand()`, line 310). Both render
  `templates/backOffice/default-twig/content/edit.html.twig` and build URLs
  with `subject.url(action)` (`Generation/Admin/ContentSubject.php:65`).
- `Content/Document.php`: `freeSectionId()` (135) gives an unused id,
  `withSectionAppended()` (124) and `withSectionAt()` (97) place a section.
  `Section` holds `id`, `component`, `props` and `appearance`.
- The id of a section is the `data-cms-section` attribute of the rendered
  section (`Content/DocumentRenderer.php`), which ties the list of the screen
  to the preview (`assets/content-editor.js:498`): a copy needs a new id.
- `assets/content-editor.js:1036` sends the forms of the list
  (`data-cms-move`, `data-cms-remove`…) through `fetch` and redraws the
  screen; a new form attribute must be added to that selector.
- Saving a draft validates against every component of the catalogue
  (`DocumentValidator` → `SectionCatalog::find()` → `all()`), not only the
  offered ones.

## Decisions taken

1. **Same component, same props, same appearance, a new id**
   (`freeSectionId()`), placed **right after the original**.
2. **A section whose component the site put away is not duplicated**, as
   `add()` refuses it: same message, and no "Duplicate" button on such a
   section. **A prototype is duplicated** in the page holding it: it is not
   in `offered()` (`Content/SectionCatalog.php`), but that page already edits
   it. One rule for the screen and the action:
   `SectionCatalog::canBeDuplicated()`.
3. **Only the draft of the edited language changes**, logged as a manual
   change ("Section "x" duplicated by hand as "y".").
4. **One implementation for pages and catalogue items**, without waiting for
   the unification of the two controllers.

## Steps

1. `Document::withSectionAfter(string $id, Section $section)` (or
   `withSectionAppended()` then `withSectionAt()`), with a unit test in
   `Tests/Unit/Content/DocumentTest.php`.
2. `SectionUpdate::duplicate()`: reads `section`, refuses a section no longer
   in the document or whose component is not offered, builds the copy, saves,
   logs.
3. Route `duplicate` (`POST /duplicate`) in both controllers, guarded like
   `move`; in the item controller, a `'duplicate'` arm in `byHand()`.
4. Button in the actions of each section of `content/edit.html.twig`
   (`data-cms-duplicate`, `data-testid="cms-section-duplicate-{id}"`), shown
   when `may_publish` and the component is offered; selector of
   `content-editor.js:1036` extended. Bump the `assets_version` and recopy the
   published script (see `docs/developing.md`, "Module assets").
5. Messages in `I18n/en_US.php` and `I18n/fr_FR.php`.
6. Tests in `Tests/Integration/Generation/SectionEditScreenTest.php`, next to
   `testASectionIsMovedByHandAndTheLedgerSaysSo()`:
   - a page section duplicated lands after the original with a new id and the
     same props and appearance, and the ledger says so;
   - the same on a catalogue item;
   - a section whose component is no longer offered is not duplicated.
   Each one seen red once by breaking the code it covers.
7. `CHANGELOG.md`, under `[Unreleased]`; the editor documentation where the
   actions on a section are listed.

## Points to watch

- Translating from another language checks that the sections match: until
  the other language gets its own copy, the translation may send the extra
  id back for correction. Check the behaviour once on a page in two
  languages and say it in the CHANGELOG if it shows.
- Twig of the test environment does not reload: move `var/cache/test/twig`
  aside after editing the template (`docs/developing.md`).

## Estimate

About 1 h 45 (code 45 min, tests 45 min, script, messages and docs 15 min).
No schema change: release as a minor version.
