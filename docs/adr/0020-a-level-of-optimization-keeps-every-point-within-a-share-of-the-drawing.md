# 20. A level of optimization keeps every point within a share of the drawing

Date: 2026-11-17. Status: accepted.

## Context

The screen that prepares an SVG offered an extension point,
`SvgOptimizationLevel`, with no implementation. The base optimization is
lossless: it removes titles, unused ids and editor data, and writes each path
in its shortest notation without changing a value. Drawing tools export many
more decimals than the display shows, and that is where most of the weight of
a heavy file sits.

A stronger optimization has to say how far it may move the drawing. "Looks the
same" cannot be checked, so each level needs a distance that a test can
measure.

## Decision

- **Each level is a tolerance, a share of the diagonal of the `viewBox`.**
  Three levels exist: 1/4000 (No visible difference), 1/1000 (Slight loss of
  detail) and 1/400 (Visible loss of detail). The level offered by default
  stays the base optimization alone.
- **Everything runs on the server, in PHP, with no new dependency.** Svgo in
  the browser, a Node binary and oxvg on the server were set aside.
- **Only `d` and `points` change.** Other attributes weigh less than 1.5 % of
  a file and are left alone.
- **The tolerance tightens with the largest enlargement a `transform`
  attribute of the document applies.** A `transform` that cannot be read, or a
  `transform` property in CSS, leaves the drawing as it is. So does content
  drawn at a scale of its own: a pattern, a marker, a symbol, a nested `svg`,
  a mask or a clip path in `objectBoundingBox` units.
- **Rounding keeps the fewest decimals k whose half unit fits in the
  tolerance.** Absolute positions are rounded. A relative move starts from the
  point already rounded, so no error adds up along a path. Arc flags and the
  arc angle stay as they are. Radii are rounded and never become zero.
- **Points are removed with Ramer-Douglas-Peucker, on an explicit stack.** A
  curve whose control points lie within the tolerance of its chord becomes a
  line. The points of a run of lines that lie within the tolerance of a longer
  line are removed. The ends of a run stay. An arc, a moveto, a closepath, a
  smooth curve (`S`, `T`) and the curve it mirrors end a run and stay. Every
  distance is measured against the original drawing, control points included:
  a curve lies within the hull of its control points.
- **Rounding and removal share the tolerance.** Removal gets the tolerance
  minus the most rounding can move a point (half of 10^-k, times the square
  root of 2). When less than half is left, the engine also tries one more
  decimal and keeps the shorter result.
- **The tolerance holds on each axis.** Rounding moves each coordinate by at
  most half a unit of the last decimal kept, which is within the tolerance;
  a point can so move up to the square root of 2 times the tolerance in
  distance. Removal is bounded in distance, within what rounding leaves.
  Holding the tolerance in distance would keep one more decimal on about one
  file in seven, for a difference no one sees.
- **A shape that draws the ends or corners of its strokes is only rounded.**
  That means a `stroke-linecap` other than `butt` or a marker, set on the
  shape or on a parent group, as an attribute or in `style`. A style sheet of
  the document that sets one of them stops removal on the whole drawing.
- **A drawing with more than 500,000 bytes of path data (`d` plus `points`) is
  returned as it came.** The heaviest real file measured holds 373,000. A
  made-up file of 5.4 MB took 8 to 12 s.
- **A result that is not lighter than the input is dropped**, and the input is
  returned.

## Consequences

Measured on 548 real files (icons and illustrations of Flexy, flags of the
back office, a sample of Bootstrap Icons), with gains counted after the base
optimization, raw and gzipped:

| Level | Raw | Gzip |
|---|---|---|
| 1/4000 | -36.5 % | -39.9 % |
| 1/1000 | -59.0 % | -61.6 % |
| 1/400 | -69.0 % | -70.3 % |

- The Flexy illustration `confirm.svg` weighs 375 KB after the base
  optimization, then 178 KB, 66 KB and 25 KB at the three levels.
- The heaviest real file takes at most 1.3 s per level, with a PHP memory peak
  of about 10 MB. The XML document lives outside `memory_limit`.
- Two neighbouring levels can give the same file on a small icon drawn on an
  integer grid. 153 of the 548 files give the same result at all three levels,
  mostly Bootstrap Icons.
- A level applied to its own output can still remove points: each pass holds
  the tolerance of what it receives, not of the original file. Rounding alone
  and the base optimization are stable.
- The levels change how the image renders, so the screen shows the result
  before the file enters the library.

### Rejected

- Merging neighbouring curves: 8 to 11 % of the curves and 3 to 6 % of the
  weight.
- Fitting Bézier curves.
- Rounding the other attributes: under 1.5 % of the weight.
