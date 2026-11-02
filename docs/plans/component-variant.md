# Plan: a variant of a component of the site

Status: steps 1 to 5 done on `feat/component-variant` (2026-11-02), as route
`admin.cms.components.site.copy`. Each new test was seen red by breaking the
code it covers (five breakages), then green. The button reads "Copy into a
new component", not "variant": that word already names the variants of the
theme components. Read on `main` at 83b210e (2.7.1). The design questions are
settled (see "Decisions taken").

## What is asked

A component of the site (`cms_component`) only changes in place: "Ask for a
change" rewrites it under the same code. An editor who wants a second version
of it (a dark price table next to the light one) starts today from an empty
request and pays for a component the site already has. Add "Create a variant"
to the screen of a component: a copy under a new code, made without calling
the generator, then adjusted by "Ask for a change" like any component.

## What is there today

- `Content/Admin/CmsSiteComponentController.php`: `show()` (line 248) draws
  `components/site/component.html.twig`; `promote()` (line 273) turns a
  prototype into a component of the whole site; `keep()` (line 194) always
  saves with `STATUS_READY`. Every write is guarded by `denyUnlessMayMake()`
  (settings and custom code permissions).
- `Content/Site/SiteComponentRepository::save()` (line 116): with
  `$replacing = null`, a code already taken throws
  `InvalidSiteComponentException`; the provenance mark is kept only when the
  component passed carries one (line 142).
- `Content/Site/SiteComponentValidator::validate()`: code in PascalCase of 2
  to 40 characters, label 1 to 120, description 1 to 500, template, sample and
  stylesheet checked; the stylesheet must scope under the new
  `scopeClass()`.
- `Content/Site/SiteComponent.php`: the stylesheet is stored unscoped and
  scoped when served (`SiteCssScoper`), its `@keyframes` renamed after the
  class of the component (`scopeClass()`, line 62). A copy of the CSS under a
  new code therefore gets styles and animations of its own.
- The ledger of calls is attached to the id of the component
  (`Generation/Component/ComponentCandidates.php:46`): a variant starts with
  an empty history.
- A prototype (`STATUS_DRAFT`) is offered to no page and not to the generator
  until "Keep for the whole site" (`promote()`).

## Decisions taken

1. **The variant is born a prototype** (`STATUS_DRAFT`): the generator is
   never offered two identical components. It becomes a component of the
   site by "Keep for the whole site", or by its first change kept (`keep()`
   saves `ready`).
2. **No call to the generator.** Template, stylesheet, props, sample,
   description and provenance mark are copied as stored.
3. **The editor types the code and the label**; the code is final (the
   repository never changes a code), the form says so.
4. **The provenance mark is copied**: a variant of a generated component is
   still generated content (AI Act, article 50(2)).
5. **Out of scope: the components of the theme.** They are rendered outside
   the Twig sandbox by Symfony UX (`Content/Theme/ThemeComponentSection.php`);
   a site variant of one would be a generation from a model, another project.

## Steps

1. Route `admin.cms.components.site.variant`, `POST /{component}/variant`,
   guarded by `denyUnlessMayMake()` and the CSRF token of the screen. Reads
   `code` and `label`, builds a `SiteComponent` from the found one with those
   two changed, runs it through `SiteComponentValidator`, saves it with
   `STATUS_DRAFT` and `$replacing = null`. On success, redirect to the screen
   of the variant with a flash; on `InvalidSiteComponentException`, back to
   the screen of the source with the problems.
2. Form on `component.html.twig`, shown when `may_make`: code (pattern of the
   validator, help "PascalCase, cannot be changed afterwards") and label
   (prefilled with the label of the source). Same look as the other actions
   of the screen.
3. Messages in `I18n/en_US.php` and `I18n/fr_FR.php`.
4. Tests in `Tests/Integration/Content/SiteComponentScreenTest.php`:
   - the variant holds the template, stylesheet, props, sample, description
     and provenance of the source, under its code and label, as a prototype;
   - a code already taken, and a code that is not PascalCase, leave nothing
     behind and say why;
   - without the permission to make a component, the route answers 403;
   - the source is unchanged.
   Each one seen red once by breaking the code it covers.
5. Docs: `docs/creating-a-component.md` (§ "A component made in the back
   office") and the entry under `[Unreleased]` in `CHANGELOG.md`.

## Points to watch

- "Variant" already names the variants of theme components (`Base`, `Alt`,
  see `Content/Document::freeSectionId()`). Check the wording of the button
  in both languages does not suggest a theme variant.
- The sample is normalized by the validator: a source kept before a stricter
  rule may be refused as a variant. The message must say what to fix, not
  fail silently.

## Estimate

About 2 h (route and form 45 min, tests 1 h, docs and messages 15 min). No
schema change: release as a minor version.
