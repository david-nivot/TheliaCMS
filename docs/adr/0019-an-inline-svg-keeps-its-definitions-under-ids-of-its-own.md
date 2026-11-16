# 19. An inline SVG keeps its definitions, under ids of its own

Date: 2026-11-13. Status: accepted. Supersedes the decision « The filter is
not widened » of ADR 14.

## Context

The HTML of a page is rendered when it is saved, then filtered by
`PublishedContentSanitizer` and stored. Of an SVG written in it (an icon a
component of the theme draws with `ux_icon()`, an icon of the media library),
the filter used to keep the plain shapes only: no `defs`, gradient, mask,
clipping path or `id`. ADR 14 held icons of the library to that list.

Two things came of it. The round flags of the theme (`circle-flags`, drawn
through a mask) went online square, and a drawing with a gradient, a mask or
a clipping path, as most exports of a design tool are, could not become an
icon.

Keeping definitions brings two risks the plain list did not have. A
definition is named by an id, and a page may hold the same icon twice: the
second copy would then draw with the definitions of the first, which a closed
menu may hide. And a reference (`url(#…)`, `href`) can point to another file,
which a page would fetch from a third party on every visit.

## Decision

- **The filter keeps definitions.** On top of the shapes: `defs`,
  `linearGradient`, `radialGradient`, `stop`, `mask` and `clipPath`, with
  their own attributes, and on shapes and groups `mask`, `clip-path`,
  `shape-rendering`, and a `fill` or `stroke` that is `url(#id)`.
  `gradientTransform` takes a list of transforms, `stop-color` a color,
  `shape-rendering` one of its four keywords.
- **A reference stays inside its SVG.** A paint, a mask, a clipping path or
  the `href` of a gradient is kept only when it names an id of the same SVG.
  One that names another file, a `data:` address, an id the SVG does not
  hold or an id of another SVG of the page is dropped.
- **Every id an SVG cites gets a prefix of its own,** after the sanitizer of
  Symfony: `s<hash>-<rank>-`, the hash drawn from the markup and the rank of
  the SVG in it, its references rewritten alike. An id nothing in the SVG
  cites keeps its name, for an anchor, a label or a rule of the theme that
  names it. The same markup is written the same each time it is saved, and
  cleaning markup twice changes nothing (a former prefix is read back, however
  many times it was given), so no history or cache sees a change that is not
  one.
- **A mask or a clipping path asked for inside another one is dropped.** Each
  level paints the next once per use: twenty nested masks would paint a
  million shapes in the browser of every visitor, the same risk as `use`.
- **An SVG ends where a browser ends it.** The parser of PHP 8.3 keeps the
  HTML a writer left inside an open `svg` (a `<p>`, a `<div>`), where a
  browser leaves the SVG at that tag: `clean()` ends the SVG there too, so
  that the HTML keeps its ids and the next SVG takes a rank of its own. It
  reads one SVG at a time, a tag after another, and copies the rest of the
  page as it is: a page of a megabyte costs it a few megabytes of memory.
- **Still out:** `use` and `symbol` (a shape reused through nested `use` can
  multiply into millions of shapes, and no icon of the theme needs it),
  the `style` element (a style sheet in an SVG styles the whole page),
  `filter` (`feImage` loads an address), animations (they can rewrite a
  link), `image`, `foreignObject`, `pattern`, `marker` and `text`. An `a`
  written in an SVG is kept as a link, under the rules of every link. A
  reference in a `style` attribute stays refused by `CssAllowList`. The old
  `xlink:href` is read as `href` by the parser of PHP 8.3 and dropped by that
  of PHP 8.4: either way, it points inside the SVG or goes.
- **One policy, read by the filter and by the screen.** The rules live in
  `InlineSvgPolicy`. `configure()` hands them to the sanitizer of Symfony,
  which stays the one reader of the HTML of a page; `clean()` then gives the
  ids their prefix. `validate()` reads the same rules on a file and returns
  everything a page would not keep of it, without stopping at the first:
  `IconEligibility` turns that list into the reasons the **Prepare the SVG**
  screen gives, for a new icon as for the replacement of the file of one.
- The base optimization removes what an Illustrator export writes on the root
  and nothing reads (`x`, `y`, and `xml:space` when the drawing has no text),
  so that such an export is not refused as an icon for them.

## Consequences

- A round flag and an icon with a gradient, a mask or a clipping path are
  drawn whole in a published page, and may be icons of the library.
- The screen and the filter cannot drift apart: a test passes a set of
  drawings through both, and a drawing the validation finds whole comes out
  of a page the same, its ids aside.
- A page published before keeps what the former filter left until it is
  rendered again: `thelia_cms:render-published --all` for what is online,
  `thelia_cms:render` for the drafts. Nothing renders by itself.
- The id of a mask, a gradient or a clipping path written in an SVG of a
  page is no longer the id the file holds: a style sheet of the theme that
  would aim at it misses. None is known. The other ids keep their names.
- A drawing that holds `use`, a style sheet or text stays an image. Taking
  `use` in would need a ceiling on its depth and count, and a review of its
  own.
