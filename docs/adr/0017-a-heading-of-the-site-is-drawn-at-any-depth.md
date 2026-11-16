# 17. A heading of the site is drawn at any depth

Date: 2026-11-13. Status: accepted, amended by ADR 18. Amends ADR 15 and ADR 16.

## Context

The module offers a native `cms:heading` section: a heading on its own, at
level 2, 3 or 4, styled like the headings of the free text. A component of
the site writes its headings in its own markup, at the level and in the look
the generator chose, so a heading of a component and a heading of the page
next to it can differ in size and in level.

`cms_component()` draws components of the site and of the theme only, one
level deep: a component that draws another cannot be drawn itself, and a
call inside a drawn component draws nothing. Opening it to `cms:heading`
under those rules would make every component that draws its heading with it
one that no other component may draw, and its heading would vanish when
another draws it.

## Decision

- **`cms_component()` draws `cms:heading`**, by that name, with `text` and
  `level` (`h2`, `h3` or `h4`). It is the only section of the module that
  may be drawn (`CatalogueDrawableSections::find()`).
- **A heading is a leaf.** `SiteTemplateRenderer::LEAVES` lists the sections
  that draw nothing themselves. A leaf is drawn at any depth, a drawn
  component included, and calling one does not make a component one that
  draws others: the rule of one level reads `SiteTemplateRenderer::nesting()`,
  the calls without the leaves (validator, generator, preview).
- **A heading is drawn in place**, in the markup of the caller, and goes
  through the filter of the caller like the rest of it: it carries no
  attribute the filter takes out, unlike a component of the theme.
- **Its level is checked when the component is saved.** A level written in
  the call must be one the heading offers; a `choice` prop of the caller
  handed to `level` must offer no other value. The same holds for any choice
  a call fills.
- The generator of components is told it may draw it, with its levels, and
  that it keeps a component drawable.

## Consequences

- A component can take its heading from the site and stay one that another
  draws; the heading check of the publication reads its level as any other.
- A heading drawn by a component counts towards the 24 calls of a rendering.
- Another section of the module that draws nothing could become a leaf by
  joining `LEAVES` and `CatalogueDrawableSections`; none does today.
