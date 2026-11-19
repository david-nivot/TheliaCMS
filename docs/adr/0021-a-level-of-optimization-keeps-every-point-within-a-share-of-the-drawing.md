# 21. A level of optimization keeps every point within a share of the drawing

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
- **The tolerance tightens with the strongest enlargement a shape is drawn
  at.** Along the chain from a shape to the root, the strongest stretch of
  each `transform` multiplies, an upper bound of the stretch of their product.
  A shape is drawn in place and again by each `use` that references it or a
  group around it, `href` or `xlink:href`, through other `use` elements too:
  each of these chains counts, and the drawing takes the strongest over its
  shapes. A clip path or a mask in `userSpaceOnUse` is drawn in the space of
  each element that references it (`clip-path` or `mask`, as an attribute or
  in `style`), and counts like a `use`. A `transform` that cannot be read, or
  a `transform` property in CSS, leaves the drawing as it is. A shape drawn
  somewhere at a scale of its own is left as it is: in a pattern, a marker, a
  symbol, a nested `svg`, a mask or a clip path in `objectBoundingBox` units
  or referenced from a style sheet (the element it applies to is not known),
  a path that a `textPath` or an `mpath` follows, or through a `use` placed
  in one of them or leading back to itself.
- **Rounding keeps the fewest decimals k whose half unit fits in the
  tolerance.** Absolute positions are rounded. A relative move starts from the
  point already rounded, so no error adds up along a path. Arc flags and the
  arc angle stay as they are. Radii are rounded and never become zero.
- **An arc is checked along its length once rounded.** A rounded radius can
  move the centre, and the whole arc, much further than its ends: a radius of
  4.98 between ends 9.92 apart, rounded to 5 between ends 10 apart, moves the
  top of the arc by 0.43 for a tolerance of 0.125. The arc is drawn as a
  renderer draws it (SVG 1.1, F.6.5, radii too short enlarged) and compared
  with the original at 33 points, at the same share of the sweep, on each
  axis. When it strays, its radii take up to three more decimals, then their
  own values, then its end keeps its own value too. When even that fails from
  where the segment before it ends once rounded, that end keeps its own value
  and the rounding resumes from that segment, with the state saved before it;
  what follows starts from where it really ends. Each segment is resumed from
  once at most, so the time grows with the path: 4,400 arcs that each stray
  took 510 s when the path was rounded again from its start, 0.9 s resumed.
  An arc whose radius, or whose distance between its ends, is so small that
  its square is zero for a float cannot be measured: renderers read it as
  zero or not depending on their precision, and its path stays as written.
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
  shape, on a parent group, or on a `use` that draws it or a group around that
  `use`, as an attribute or in `style`. A style sheet of the document that
  sets one of them stops removal on the whole drawing.
- **A drawing with more than 500,000 bytes of path data (`d` plus `points`) is
  returned as it came.** The rounding takes a time that grows with the data.
  The heaviest real file measured holds 373,000. A made-up file of 5.4 MB took
  8 to 12 s.
- **A path or point list of more than 40,000 numbers is left as written, by
  the levels and by the base optimization alike.** Memory grows with the
  longest path, about 1,250 bytes per segment along a level, and PHP stops
  with a fatal error at its `memory_limit`. The numbers are counted before
  the data is read, a close counting as one; a segment holds one at least,
  so the ceiling bounds the segments too. At the ceiling, a level peaks at
  about 50 MB and the base at 30 MB. The longest real path holds 37,400
  numbers (8,100 segments). A level leaves a drawing holding such a path as
  it came, and the screen says so.
- **Taking points out stops after 1,000,000 distances measured per drawing,
  over both precisions tried.** Ramer-Douglas-Peucker measures, at worst, a
  number of distances that grows with the square of a run: a square drawn
  over and over loses one corner per split, and 8,000 corners took 4.8 s.
  Past the budget, no further point leaves and the drawing is only rounded:
  those 8,000 corners take 0.26 s. The real file that measures the most takes
  494,000 over both precisions.
- **A value too large for a float leaves its shape as written.** Read as
  infinite, it would be written as `inf`.
- **A result that is not lighter than the input is dropped**, and the input is
  returned.

## Consequences

Measured on 548 real files (icons and illustrations of Flexy, flags of the
back office, a sample of Bootstrap Icons), with gains counted after the base
optimization, raw and gzipped:

| Level | Raw | Gzip |
|---|---|---|
| 1/4000 | -36.5 % | -39.9 % |
| 1/1000 | -59.3 % | -61.8 % |
| 1/400 | -69.3 % | -70.6 % |

- The Flexy illustration `confirm.svg` weighs 375 KB after the base
  optimization, then 178 KB, 66 KB and 25 KB at the three levels.
- The heaviest real file takes at most 1.1 s per level, with a PHP memory peak
  of about 13 MB. The XML document lives outside `memory_limit`.
- Two neighbouring levels can give the same file on a small icon drawn on an
  integer grid. 156 of the 548 files give the same result at all three levels,
  mostly Bootstrap Icons.
- A level applied to its own output can still remove points: each pass holds
  the tolerance of what it receives, not of the original file. A level on a
  drawing with nothing to take out, and the base optimization, are stable.
- The levels change how the image renders, so the screen shows the result
  before the file enters the library.

### Rejected

- Merging neighbouring curves: 8 to 11 % of the curves and 3 to 6 % of the
  weight.
- Fitting Bézier curves.
- Rounding the other attributes: under 1.5 % of the weight.
