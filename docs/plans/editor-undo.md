# Plan: undo / redo on the content screen, and no lost section form (N28, N53 optional)

Status: decided (2026-11-06, by the maintainer), to be coded on `feat/editor-undo`. Read on `main` at 0394091 (2.16.0). Wave 5 of the
roadmap, family "editor". Source: gap 1 of the Gutenberg comparison (N28); N53 is the optional
leftover of N47 (rows of a list dragged with the mouse).

## State verified on 0394091

Abbreviations: CC = `Generation/Admin/ContentController.php`, SU = `Generation/Admin/SectionUpdate.php`,
JS = `templates/backOffice/default-twig/assets/content-editor.js`, ED = `templates/backOffice/default-twig/content/edit.html.twig`,
SF = `templates/backOffice/default-twig/content/_section_form.html.twig`.

### What changes the draft, and what is kept

- Every action exists twice, page (`/admin/cms/pages/{id}/content/<action>`) and catalogue item
  (`/admin/cms/catalogue/{type}/{id}/content/<action>`), both through `ContentScreenTarget`: one
  implementation serves both.
- By hand: `update` (section form, and the inline edit of the preview, JS ~1243), `remove`, `move`
  (arrows or dropped rank), `duplicate`, `add` (CC 161-203) go through `byHand()` (CC 346-369),
  `SU::apply/remove/move/add/duplicate`, then `saver()` (CC 408) = `ContentWriter::saveContent`
  (`Content/Editing/ContentWriter.php` 69). **No revision is written**; a ledger row
  `GenerationLog::recordManual` (`Generation/GenerationLog.php` 348) stores the document *after*
  the change. Answer: 302 to the screen.
- Generator: `generate`, `revise`, `section`, `translate` go through `run()` (CC 387-406):
  `keepDraft` (revision labelled `draft`) then `saveContent` with provenance.
- `restore` (CC 209-224) = `ContentWriter::restoreDraft` (115-127): keeps the current draft, saves the
  kept one, replaces the provenance mark.
- Prototyped component: `/content/component/keep` (`Generation/Admin/CmsPageComponentController.php`
  166-174) appends a section, `recordManual`, no revision. Page only.
- `publish` / `unpublish` do not change the draft.
- Revisions (`Content/Editing/ContentVersions.php`): 20 publications (395) and 5 drafts (398, label
  `draft`), pruned apart. Stacking undo snapshots there would push out the drafts kept before a
  generation.
- The ledger `cms_page_generation` (schema 674) keeps a full document per hand action and is
  **never purged** (no purge command touches it; `history()` 396 only limits the read).
- No version, hash or lock on the draft: `cms_page_content` has `updated_at` only (schema 188). Every
  path reads the draft, changes it in memory and writes it whole: the last write wins.

### How the screen applies an answer

- Every hand action is a `fetch` that follows the 302, then `swap()` (JS 131-173) replaces
  `[data-cms-content]`, reopens the `<details>`, keeps the side panel scroll and the preview position
  (N12, JS 192-274), and sets `running = false` (153).
- State that lives only in the browser: `running`, `keptPosition`, the open panels, the current
  section, and **any value typed and not sent**.

### Undo today

- Only the Tiptap bar of the inline edit in the preview (`toolbarItems` "undo,redo,…", JS ~1153) and
  ProseMirror inside one field of a section form. Nothing on the document.
- No Ctrl+Z binding on the screen (keydown handlers: JS 264, 1271-1280 inline edit, 1910 Escape).

### Section forms and the loss of input

- One native modal `<dialog data-cms-edit>` per section (ED 406), opened by `command="show-modal"`
  (ED 403) or `openEditDialog` (JS 1886); closed by `<form method="dialog">` (ED 412-414) or Escape
  (JS 1910). Modal: the rest of the screen is inert while it is open.
- Closing keeps the typed values in the DOM without saving them and without a word; the next `swap()`
  throws them away. Reopening shows values that are not saved.
- `beforeunload` (JS 2272-2277) only while `running` (a generation or a drop). Nothing tracks a changed
  form. Confirmations in use are `window.confirm` (remove 1796, restore 1802).

### Rows of a list (N53)

- Rows: `[data-cms-items]` (SF 64-93), prototype macro `item` (SF 226-258); add, remove, up, down in
  JS 2005-2154; `renumber()` (2036-2041) renames the fields in DOM order. The server keeps the order of
  the indexes it receives (`SectionEditScreenTest::testRowsSentInAnotherOrderAreSavedInThatOrder`):
  a drag only has to move the node and renumber.
- Sections are already dragged with native HTML5 drag and drop, no library (JS 1681-1775, grip
  `[data-cms-grip]`); `menu-editor.js` 59 has another one.

## Options

### Where the undo steps live (decision 1)

- **A. A table of steps** `cms_content_step` (target type, id, locale, label, section id, document and
  provenance before, document and provenance after, hash of each, author, date, `undone`), capped per
  content and language. Isolated from revisions and from the ledger; page and item alike.
- B. The ledger `cms_page_generation`: already one row per hand action, but it stores only the
  *after*, never the state before the first step, and mixes a cost ledger with an undo pointer
  (extra columns all the same).
- C. The admin session: no schema, but documents of 50 kB × steps × pages in every session, lost at
  logout, invisible to a second tab.

### What can be undone (decision 2)

- **Every change made from the screen**: hand actions, inline edit, prototyped component, generator
  answers, restore. A generation undone is put back by Redo without a new call.
- Hand actions only; generations stay on "Previous drafts".

### The guard on a section form (decision 3)

- **Ask before closing a changed form** (close button, Escape) with "discard / keep editing", reset
  the form when discarded, and warn on leaving the page while a form is changed.
- The same plus the unsent values kept in `sessionStorage` and offered back at the next opening.
- Warn on leaving the page only.

### N53 (decision 4)

- **In this branch, its own commit, after N28** (same JS file).
- Later, on its own.
- Dropped (the arrows are enough).

## Decided (2026-11-06, by the maintainer)

1. Steps in a dedicated table `cms_content_step` (option A), 30 per content and language.
2. Every change made from the screen can be undone, generator answers and restore included.
3. Guard: ask before closing a changed section form, reset it when discarded, warn on leaving the
   page while a form is changed. No copy in `sessionStorage`.
4. N53 in this branch, its own commit, after N28.

## Frozen with the decisions (defaults not objected to)

- One stack per content and language, shared by the admins (the draft is shared); the button names
  the step and its author ("Undo: section "hero" edited by …").
- Undo is refused when the draft is no longer the one the step left (hash of the stored JSON
  compared): another tab, another admin, an import or the command line changed it. Message: reload.
  No lock elsewhere.
- A new change after an undo drops the steps that could be redone.
- Steps are recorded by one writer method used by the screen paths only (`byHand`, `run`, `restore`,
  component keep); imports and the command line write none (the hash check covers them).
- Cap: 30 steps per content and language, pruned at each record; deleted with the content.
- Undo and Redo: two buttons in the actions bar (ED 71), disabled when nothing applies; Ctrl/Cmd+Z and
  Ctrl/Cmd+Shift+Z (and Ctrl+Y) when the focus is not in a field and no dialog is open. Same POST →
  302 → `swap()` path as the other actions, the section of the step kept in view.
- Right: modify pages (like `restore`), never the right to generate.
- The provenance mark goes back with the document (as `restoreDraft` does).
- README "Sections edited by hand are not snapshotted" rewritten; CHANGELOG `[Unreleased]`.

## Lots, in order (one branch `feat/editor-undo`, one commit per lot, one PR)

1. **N28a, guard on a section form** (JS, ED, labels FR/EN). Snapshot of the form when the dialog
   opens; on `cancel` (Escape) and on the close form, compare and confirm; reset on discard; own
   flag for `beforeunload` (never `running`). Inline edit of the preview: same question on Escape /
   Cancel when the text changed. Proof in the browser at 1440 px (`.claude/rules/section-forms.md`).
2. **N28b, undo / redo**. Schema + `Config/update/2.17.0.sql` + `TheliaMain.sql`; `ContentSteps`
   (record, undo, redo, prune); writer method; routes `undo` / `redo` for page and item; buttons,
   shortcuts, labels; README, CHANGELOG.
3. **N53, drag a row** (optional): grip in the macro `item`, native drag limited to its list,
   `renumber()` + `refresh()` + `aria-live` message; arrows stay the keyboard way.

## Estimate (pilot days, read on 0394091)

| Lot | Days |
|-----|------|
| N28a guard | 0.5 |
| N28b schema, service, writer, routes | 0.75 |
| N28b screen (buttons, shortcuts, position kept) | 0.25 |
| N28b tests and sabotages (undo, redo, hash refused, redo dropped, cap, item, right) | 0.5 |
| N28b browser proof, docs | 0.25 |
| N53 (option) | 0.5 |
| **Total** | **2.75** (2.25 without N53) |

Contingency of the lot: +0.5 d (Tiptap field sync, see below).

## Tests to write (seen red first)

- Undo puts back the document before the step, redo the one after, provenance included.
- Undo refused when the draft changed outside the step; nothing written.
- A new change after an undo drops redo; cap of 30 kept; catalogue item same as page.
- Undo of a generation does not call the gateway; undo without the right to modify: 403.
- Guard: browser script (no JS unit test in the repo), closing a changed form asks, a pristine one
  does not.

## Changed while coding N28b

- The buttons sit at the top of the side panel, next to "Help on this screen", not in the actions
  bar: two more buttons wrapped the bar onto a second line at 1440 px (46 to 83 px high).
- The draft is compared as stored (the JSON string), not through a hash column: the same check, one
  column less. After a move, the step keeps the draft as the writer stored it, so the opposite move
  compares with what is really there.
- Undoing a generation puts back the document and its mark only: the header the generator filled
  where the page had none (search title and description) stays.

## Changed while coding N53

- The handle of a row is `data-cms-item-grip`, not the `data-cms-grip` of a section: the section
  list binds every `data-cms-grip` it holds, its dialogs included, and would drag the whole section.
  The row stops its `dragstart` and `dragover` for the same reason.
- A row let go outside its list goes back where it was (sections post the place they are in).
- The handle is hidden when the list has one row, like the arrows.

## Checked since

- The Tiptap fields of a section form write their value to the form at each keystroke
  (`tiptap-editor.js` 253-256, seen in the browser with N28a): comparing `FormData` is enough.

## Not checked

- `ItemScreenTarget` / `cms_item_content` read by grep only; assumed symmetric with the page.
- An old step can fail validation if the catalogue changed since (`InvalidDocumentException`): shown
  as a refused undo, like `restore` (CC 219).
