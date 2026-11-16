# 18. A heading takes a style of the theme, apart from its level

Date: 2026-11-16. Status: accepted. Amends ADR 17.

## Context

A `cms:heading` (ADR 17) looked like the headings of the free text at its
level: an `h3` was always the size of an `h3`. A theme names the looks of its
headings apart from their element: the toolkit of Flexy writes them as
utilities (`h1` to `h6`, `components/Atoms/Typography`) that any element
carries. An editor who wants a part of a page to open with a large heading
had to choose a higher level, which breaks the order the heading check of the
publication reads, or leave it small.

## Decision

- **The level orders the page, the style is how it looks.** `cms:heading`
  takes a `style` next to its `level`, a `choice` whose values are the heading
  styles of the theme, plus `level`, the look of its level, by default. The
  levels stay `h2` to `h4`: the title of the page is the one `h1` (ADR 10).
- **The theme declares its styles** in `config/theliacms.yaml`, under
  `sections.heading_styles`: a value, a label and the classes the heading
  carries. A theme that declares none and is Flexy, or a child of it, gets the
  six headings of the Flexy toolkit, which Flexy 1.3.0 does not declare; any
  other theme offers the look of the level only.
- **A styled heading carries the classes of its style alone**, without the
  class of the free text, and `blocks.css` sizes by level only a heading
  without a style: the layer of the module may come after the one of the
  theme and would win.
- **A style the theme no longer declares falls back on the look of the
  level** when a document is read again: the page is kept, not refused.
- **In a component of the site the editor sets both.** The generator is told
  to hand two `choice` props of the component, `title_level` and
  `title_style`, to the heading that opens it; the values a `choice` prop of
  the caller offers are checked against those of the heading when the
  component is saved, as for the level (ADR 17).
- **The heading that opens a section of the module takes the same two
  choices.** Columns, the list of pages and the form offer `title_level` and
  `title_style` (`HeadingChoice`, shared with `cms:heading`); the headings
  they draw under it, of a column or a card, are one level below it. A theme
  that draws the list of pages or the form itself reads `heading` (`tag`,
  `child_tag`, `class`).

## Consequences

- A heading is drawn the same way in a section of its own and in a
  component; its look follows the theme, not the module.
- The values of `style` depend on the theme: a component kept under one theme
  and checked again under another that offers fewer styles is refused at its
  next save, and drawn meanwhile with the look of the level.
- Components generated before keep their level written in the call and the
  look of their level.
