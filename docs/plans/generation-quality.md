# Plan: a section written alone, a translation grafted on its source, a generated page checked against the site, SEO fields proposed (G6 G7 G8)

Status: decided 2026-11-04, coded on `fix/generation-quality` (one PR, one commit per finding).

## Findings verified (module at 56de3a0)

- Section mode asks for the whole document: `Generation/PromptBuilder.php:134`
  ("answer with the whole document"). The section id is only text of the prompt;
  `PageGenerator::generate()` takes the answer as the new document (`Generation/PageGenerator.php:290-311`).
  Nothing checks the other sections stayed the same. Callers:
  `Generation/Admin/CmsPageContentController.php:163`, `Catalogue/Admin/ItemContentController.php:158`.
- `Document::withSection()` exists (`Content/Document.php:65`) and serves the edit by
  hand (`Generation/Admin/SectionUpdate.php`).
- A translation is asked to keep ids, components, images, addresses and figures
  (`PromptBuilder.php:132`); only the appearance is taken back from the source
  (`PageGenerator.php:304`, `Document::withAppearancesOf()`).
- `DocumentValidator` (`Content/DocumentValidator.php`) checks the shape only:
  ids, known components, props normalized by `PropSchema` (`Content/PropSchema.php:205-243`:
  an image is a positive id or an https address, a reference a positive id, a URL
  its form). An invented image id, a reference to nothing, a guessed `/contact` pass.
- Headings are checked on the rendered HTML at publication only: `Content/Pipeline/HeadingChecker.php`
  (`check($html, underTitle: true)`), called by `Page/Admin/CmsPageWriter.php:502`
  and `Catalogue/Admin/ItemContentWriter.php:311`.
- The envelope (`Content/DocumentSchema.php:83-133`) holds `version`, `sections`,
  `left_out`, and `fields` when the type has fields. `meta_title` and
  `meta_description` live in `cms_page_i18n` (`Config/schema.xml:104-105`), filled by hand.
- Corrections: `PageGenerator::CORRECTIONS = 2`, a refused document is sent back
  with `PromptBuilder::correction()`; past the limit, `InvalidGenerationException`.

## Decided (2026-11-04)

1. **G6, section mode.** The schema asks for one section. Its id must be the id
   asked (otherwise sent back as a correction); its component may change; its
   appearance is the one of the section it replaces. Grafted with
   `Document::withSection()`. The whole document still goes in the prompt as context.
2. **G6, translation grafted on the source.** Order, ids and components come from
   the source; any difference is sent back as a correction. Only `text` and `html`
   props come from the answer, recursively in `items`; `image`, `reference`, `url`,
   `integer`, `boolean` and `choice` are copied from the source; `json` comes from
   the answer (it may hold text). Appearance from the source, as today.
3. **G7, a check after validation, never throwing a paid page away.** Images of the
   media library that exist, references that exist (page, product, category,
   folder, menu, form, by the kind of the prop), internal addresses that resolve
   (CMS pages and rewritten URLs of the core), no `h1` and no skipped level
   (`HeadingChecker` on the rendered document). One correction turn within the
   existing limit; a problem still there afterwards empties the value and adds a
   `left_out` line, instead of failing the generation. Shape problems keep failing
   as today.
4. **G8, SEO fields, pages only.** Two optional properties of the envelope,
   `meta_title` and `meta_description`, asked for a page written from a brief and for
   a translation. Written only when the field of the page is empty in that language.
   Longer than 60 / 155 characters: ignored, no correction turn. Catalogue items
   (meta of the core product or category) are out of this lot.
5. **No source fingerprint here.** F6 takes back `feat/stale-translations` (0cf648d)
   later, its migration moved out of the published `2.4.0.sql`.
6. **One PR**, one commit per finding.

## Out of scope

- F6 (stale translations), meta of catalogue items, the bench (`deterministic-skills.md`):
  no paid call in this lot.
