# Plan: dimensions and framing of the Image section, section forms put back in order (N8)

Status: proposed, not coded. Read on `main` at 0dc0f1e (via `git show`), TheliaLibrary 2.2.1 read in
`~/workzone/thelias/thelia-cms-ai/vendor/thelia/modules/TheliaLibrary` (read only).
One branch from `main` (`feat/image-dimensions`), both parts together (decision (c) of the maintainer).

## Decided by the maintainer (not reopened here)

- (a) Layout: choice buttons (`fieldset[data-cms-choice-buttons]`) take the whole line in every section; order
  Image, Width, Caption, then Appearance; the width buttons on one line; an editor help written for the editor, apart from
  the instruction to the model (new optional field of `Prop`, prompt unchanged).
- (b) Dimensions: width and height chosen by the editor, framing "fill" (crop) or "contain", and the part of the image kept
  (focal point or a zone x,y,w,h served by TheliaLibrary). This absorbs N8 of the roadmap.
- (c) One branch, both together.

## 1. Findings verified

### The section today

- `Content/ImageSection.php:55-70`: props in the order `image`, `alt`, `caption`, `width`. `width` is a `choice`
  `narrow|column|full` (default `narrow`) drawn as buttons with pictograms (`:61-69`).
- `:83-105` `render()`: `<figure class="cms-image cms-image--{width}" data-cms-sizes="..."><img class="cms-image__img" src loading="lazy" alt>` plus
  the `figcaption`. No `width`/`height`, no `style`. `src` is `ImageSource::of()`: `/image-library/{id}/full/max/0/default.{ext}` for the library
  (`Content/ImageSource.php:48`), the https address as it is otherwise (`:32-34`).
- The slots: `ImageSlot::narrow()` = `(max-width: 50rem) calc(100vw - 2rem), 48rem`, `content()` = `(max-width: 77rem) calc(100vw - 2rem), 75rem`,
  `screen()` = `100vw` (`Content/ImageSlot.php:77-90`). `ImageSlot::isValid` accepts `px`, `rem`, `vw`, `min`, `max`, `calc`, `clamp` (`:66`, `:117-142`);
  `isValidHeight` accepts a plain length `px|rem|em` (`:152-155`); `bandedTo($sizes, $height, $ratio)` already computes
  `min(<width>, calc(<height> * <ratio>))` for each entry (`:164-174`).
- CSS: `assets/blocks.css:276-312`: `.cms-image {width: min(75rem, 100% - 2rem)}`, `--narrow {min(48rem, ...)}`, `--full {100%}`,
  `.cms-image__img {display:block; width:100%; height:auto}`.
- Unit tests pin the exact markup (`Tests/Unit/Content/ImageSectionTest.php:23-60`): a section without the new props must keep
  producing these strings byte for byte (backward compatibility test, section 2).

### The pipeline

- Order on publish and on a draft render: render, `normalizer->html`, `sanitizer->html`, then `ImageRewriter::rewrite`
  (`Page/Admin/CmsPageWriter.php:435`, `:576`; same in `Catalogue/Admin/ItemContentWriter.php`, and `Twig/CmsExtension.php:119` for
  `attributes()`). So an attribute the section writes must survive the sanitizer (allow list at
  `Content/Pipeline/PublishedContentSanitizer.php:206-225`: `class`, `id`, `style`, `loading`, `decoding`, `fetchpriority`, `data-cms-sizes`,
  `data-cms-height`; `width`/`height` of `<img>` are in `allowSafeElements`, to be confirmed by a test) and is read, then removed,
  by the rewriter (`ImageRewriter.php:81-87`).
- `ImageRewriter::rewriteImage` (`:92-124`): `fromUrl($src)` returns the `MediaFile` of the original (id, format, width, height);
  `fromUrl` matches `#^/image-library/(\d+)/#` (`Media/CmsMediaLibrary.php:100`), so a URL with a region would already be recognised as the library's,
  but the region is then thrown away: `url()` always writes `/full/` (`ImageRewriter.php:288-293`), `width`/`height` are those of the original (`:141-144`),
  `srcset` widths are 480, 960, 1440 below the original width, then the original as `max` (`:40`, `:301-310`). The `<picture>` gets a WebP `<source>` with the same set (`:227-249`).
- Open (by reading, not run): `url()` writes the size as `{n},!`. In `ImageService::applySize` (`ImageService.php:289-295`) the width is
  `$askedRatio <= $originalRatio ? $newWidth : $newHeight * $originalRatio`, with `$askedRatio = 1` for `n,!`. For a **portrait** original
  (ratio < 1) the result is `n * ratio` wide, not `n`: a `480w` candidate narrower than announced, shown blurred. Landscape and square are
  right. To be measured with a portrait on the bench before the crop work (it also decides how the crop candidates are written, section 3).

### TheliaLibrary (2.2.1, `^2.0` in `composer.json:12`)

- Route `/image-library/{identifier}/{region}/{size}/{rotation}/{quality}.{format}` (`Controller/Front/ImageController.php:25`), formats
  jpg, jpeg, png, gif, jp2, webp (`Service/ImageService.php:163`).
- **region** (`ImageService::applyRegion`, `:190-238`): `full`; `square` (centered, `min(w,h)`); `x,y,w,h` in pixels of the original; `pct:x,y,w,h` in
  percent of the original (decimals accepted, `:216-220`). A zone past the right or bottom edge is cut to the image (`:223-228`).
  No other check: `x` or `y` beyond the image gives a zero or negative `Box`, which Imagine refuses with an exception
  (`Imagine\Image\Box::__construct` throws under 1 px); a non-numeric value is arithmetic on a string. The module must send only valid
  zones (clamped), never what an editor typed.
- **size** (`applySize`, `:240-322`): `max` (as it is, or x2 with `^`); `w,h` exact (squashes if the ratio differs); `w,` and `,h` read as a square `w,w` / `h,h`
  (`:286-287`); `!` keeps the ratio inside the box; `pct:n`; `^` allows upscaling; without it a size over the (cropped) image is a 400 (`:297-299`);
  `*` pads with a transparent canvas. **There is no "cover" mode**: a crop to a ratio is `region` then `size`, as the module will compute them.
  `Box` and `Point` round their arguments (`Imagine/Image/Box.php`, `Point.php`), so fractions are tolerated.
- **Cache of the derivatives**: written to `web/image-library/{id}/{region}/{size}/{rotation}/{quality}.{format}` on the first request
  (`:167-186`), region and size taken as they come in the URL. Dropped for an image when its file is replaced
  (`Service/LibraryImageService.php:182-192`) and with the Thelia cache clear (`EventListeners/CacheClearListener.php`). Nothing bounds how many
  exist: one more reason to send only the zones the module computed and to quantise them (section 3), and never to ask a derivative while the editor drags.
- **Focal point or crop stored on an image: none.** `library_image` has `file_name, mime_type, width, height, file_size, decorative` and the
  i18n `title, alt, caption` (`Config/schema.xml:6-26`); `grep -rniE "focal|crop|focus"` over the module finds only `applyRegion`'s `crop()` calls and a
  Liip constant in `Loop/LibraryImage.php:59`. So nothing to reuse, and storing the framing on the library image would need a change of that module (schema, update SQL, admin).
- Not verified: that older 2.x of TheliaLibrary (the constraint is `^2.0`) already serve `x,y,w,h` / `pct:`; the test host runs 2.2.1.

### The form

- `Generation/Admin/SectionFormBuilder.php:313-316` draws a choice with `icons` as radios (`asButtons`, `:353-364`) with `data-cms-choice-buttons`;
  the template macro `choice_buttons` (`templates/backOffice/default-twig/content/_section_form.html.twig:24-25`, `:41-62`) wraps it in a
  `fieldset.mb-3` (not a `.cms-content__field`).
- `content-editor.css:346-360`: the form is a grid, one column, two columns from `34rem` of the drawer (`@container`). `:532-538` (inside `@supports subgrid`):
  each `.cms-content__field` is `grid-row: span 3; grid-template-rows: subgrid`. The choice fieldset takes one row, as tall as its buttons, so a neighbour
  in the other column keeps its label in that row and its input falls under the buttons. The selector that makes a field span the whole line
  (`:381-383`) lists `fieldset:not([data-cms-choice-buttons])`: the exclusion of the choice fieldsets is explicit there, and it is the one to drop.
  The same exclusion exists for the rows of a list (`:382`). The appearance block has its own 2-column grid (`:355-358`) with padding and alignment as choice buttons.
- Seen on the test host (1440 px, page 63 `img-test-sections`, section 6 Image, "Modifier à la main"): the drawer is about 575 px wide, so one column
  (container under 34 rem once padding is out), and the fault does not show on an Image section there; it shows in a wider panel (a form with a list
  takes `clamp(30rem, 56vw, 64rem)`, `:363-365`) or when two short fields precede a choice. Order seen: Image (card with "Choisir dans la médiathèque", the
  placeholder "Cette image n'est plus dans la médiathèque" for the test id), Texte de l'image, Légende, Largeur (3 buttons already on one line), Apparence (folded).
  To reproduce the fault before fixing it: a section with a short text field next to a choice (not checked yet, to do first).
- Help: `SectionFormBuilder.php:284` gives `'help' => $prop->help`; `Content/Prop.php:145` and `Generation/CatalogSheet.php:100` read the same
  `help` for the model. `Prop::choice(...)` takes it positionally (`Prop.php:97`).
- The inputs of a prop are read back by `propsFrom` (`SectionFormBuilder.php:186-206`): `Integer` => `(int) ($value ?? default ?? 0)`.
  Number inputs are capped at 10 rem (`content-editor.css:334-336`).

## 2. Data model

New props on `cms:image`, all optional, all with a default that means "as today". Schema order is also the form order (point 3 below).

| Prop | Type | Bounds | Default | Meaning |
|---|---|---|---|---|
| `width` (exists) | choice | + value `custom` | `narrow` | `custom`: the box is `width_px` wide |
| `width_px` | integer | 0, or 160-2400 | 0 | box width in px, used when `width = custom` |
| `height_px` | integer | 0, or 80-2000 | 0 | box height in px; 0 = the natural height of the image |
| `fit` | choice | `cover` (fill, crop), `contain` | `cover` | read only when `width_px` and `height_px` are both set |
| `focus_x` | integer | 0-100 | 50 | % of the width of the original kept at the centre of the frame |
| `focus_y` | integer | 0-100 | 50 | same, height |
| `zoom` | integer | 100-400, multiples of 10 | 100 | 100 = the largest zone of the box ratio; 200 = half its width and height |

Rules, in `ImageSection::normalizeProps()` after `PropSchema::normalize()` (as `ColumnsSection` does its own checks; to confirm when coding):

- `width_px` and `height_px` out of range or ratio outside 1:4 to 4:1 (decision 2): refused with a message the editor reads, as other bounds are
  (`PropSchema.php:172-174`). 0 is allowed by the schema bounds `0..2400`; the 1-159 hole is a section rule.
- `width = custom` with `width_px = 0`: drawn as `narrow` (no error: the editor sees the field).
- `height_px > 0` with a preset width: ignored (decision 4); the form hides the field then.
- Framing, focus and zoom matter only with both dimensions set and `fit = cover`.

**Backward compatibility.** A stored section is `{"image":12,"alt":"","caption":"","width":"narrow"}`. `PropSchema::normalize` fills the missing
props with their defaults (`PropSchema.php:96-102`), so a document read today and written back carries the seven new keys with neutral values.
With `width != custom` and `height_px = 0` the render path is the existing one and must produce today's markup byte for byte: the
four assertions of `ImageSectionTest` stay as they are and run first. An older module reading a newer document drops the unknown props
(`PropSchema` docblock `:22-26`), which is a clean downgrade. Published HTML of existing pages does not change: proved by
`thelia_cms:render-published --all --dry-run` reporting every existing page as unchanged (section 7).

**Where the framing is stored: in the section** (`focus_x`, `focus_y`, `zoom`), not on the library image. Reasons:
(1) the library has no such field and a module of another repository would have to change, with its own migration and admin;
(2) a framing is a property of a *use* (16:9 banner and 1:1 thumbnail of the same photo are two framings); on the image it would be one per image;
(3) an external https image has no library row at all; (4) a section is a document the module versions (kept versions, duplicates, import and export) and the
framing travels with it. The reason that would reverse this: a site that wants one framing for every use of a photo (a "keep the face" default per image). A library-side
*default* focal point could then be added later and read as the section's initial value, without changing this model.

**Why focus + zoom, not a stored zone** (decision 3 below): the zone depends on the ratio, the focus does not. The editor changes 16:9 to 4:3: a stored zone x,y,w,h is
wrong, a focus + zoom recomputes. The zone sent to TheliaLibrary is *derived* (section 3), so IIIF `x,y,w,h` is still what the image route receives.

**Editor-only props.** `height_px`, `width_px`, `fit`, `focus_*`, `zoom` and the `custom` choice are not for the generator (section 5). New optional
fields of `Prop` (all default to today's behaviour):

- `?string $editorHelp`: the help the form shows; the form uses `editorHelp ?? help` (`SectionFormBuilder.php:284`); `Prop::toJsonSchema` (`:145`) and
  `CatalogSheet::propLine` (`:100`) keep reading `help` only, so the prompt does not move.
- `bool $editorOnly`: left out of `toJsonSchema`, of `CatalogSheet`, and carried over from the replaced section by the grafts (section 5).
- `list<string> $editorOnlyChoices`: values of a choice not offered to the model (`custom`), left out of the JSON schema enum (`Prop.php:163`) and of `typeOf`
  (`CatalogSheet.php:119`).
- `?string $editor`: name of the editor control (`crop`), like `picker`, read by `SectionFormBuilder` to draw the crop block.

Alternative to `custom` in `width` (kept for the dev): a separate boolean or a `size_mode` choice, which keeps `width` a 3-value enum and avoids `editorOnlyChoices`
at the price of one more prop and a less direct form (the 4th button is the natural reading of "width").

## 3. Rendering

### HTML

Natural (no custom size): unchanged.

Custom, fill (`width = custom`, `width_px = 960`, `height_px = 540`, `fit = cover`, library image 2400x1600, `focus 35/20`, `zoom 150`):

```html
<figure class="cms-image cms-image--custom" style="width:min(960px, 100% - 2rem)" data-cms-sizes="min(960px, calc(100vw - 2rem))">
  <img class="cms-image__img cms-image__img--cover"
       src="/image-library/12/full/max/0/default.jpg" alt="..." loading="lazy"
       style="aspect-ratio:960/540;object-fit:cover;object-position:35% 20%"
       data-cms-crop="35,20,150,960,540">
  <figcaption class="cms-image__caption">...</figcaption>
</figure>
```

After the rewriter (publication):

```html
<picture>
  <source type="image/webp" sizes="min(960px, calc(100vw - 2rem))"
          srcset="/image-library/12/540,100,1067,600/480,270/0/default.webp 480w,
                  /image-library/12/540,100,1067,600/960,540/0/default.webp 960w,
                  /image-library/12/540,100,1067,600/max/0/default.webp 1067w">
  <img class="cms-image__img cms-image__img--cover" width="1067" height="600" alt="..." loading="lazy" decoding="async"
       style="aspect-ratio:960/540;object-fit:cover;object-position:35% 20%"
       src="/image-library/12/540,100,1067,600/max/0/default.jpg"
       srcset="...same with .jpg..." sizes="min(960px, calc(100vw - 2rem))">
</picture>
```

(numbers illustrative; the arithmetic below gives the real ones).

Why the section writes `src` as `full` plus `data-cms-crop`, and the rewriter makes the region: the rewriter already holds the `MediaResolver`
and the pixel size of the original; `ImageSection::render` is a pure function today (its tests need no database), and would need the library to know the
size of the original. The attribute is declared like `data-cms-sizes`: allowed by the sanitizer, read from the `<img>`, validated (five integers in
range, else ignored), removed from the published markup. The CSS on the `<img>` is written by the section, which knows the box; it is the whole truth for an image the rewriter does not own.

### The zone (pure class, e.g. `Content/ImageFrame`, unit tested without a database)

Original `W x H` px, box `Wb x Hb` (ratio `r = Wb/Hb`), focus `(fx, fy)` in %, zoom `z` in %:

1. largest zone of ratio `r`: if `W/H >= r` then `h0 = H, w0 = H*r` else `w0 = W, h0 = W/r`;
2. `w = w0 * 100/z`, `h = h0 * 100/z`; round `w` down to an integer >= 1, then `h = round(w / r)` (clamped to `H`), so the zone has the ratio of the box to a pixel;
3. centre `cx = W*fx/100`, `cy = H*fy/100`; `x = clamp(round(cx - w/2), 0, W - w)`, `y = clamp(round(cy - h/2), 0, H - h)`;
4. `x,y,w,h` equal to the whole image (`z = 100` and same ratio): region `full`.

Pixels, not `pct:`: the derivative path then has no decimals and no colon, and the arithmetic is the one the unit tests state. Percentages are what is *stored* (survives a
replaced file of other dimensions, which the library handles by dropping the derivatives; the pages are re-rendered by `thelia_cms:render-published`).
Quantised by construction: focus in 1 % steps, zoom in 10 % steps: at most a few hundred distinct zones per image and ratio, and a new derivative only
when the editor saves.

### Candidates of the srcset

- Region `R = x,y,w,h` (or `full`). Widths `[480, 960, 1440]` strictly below `w`, then the zone itself: `R/max/0/default.ext` (the zone at its own size,
  no `size` computation, no upscaling, no 400).
- Each intermediate candidate: `R/{n},{m}/0/default.ext` with `m = round(n * h / w)`, **both numbers given and no `!`**. That is an exact `Box(n, m)` (`applySize`
  `:301-306`), within a pixel of the ratio, and avoids the portrait defect above of `n,!`. `n < w` and `m < h` hold, so no 400.
- Same for the WebP `<source>`. `width`/`height` attributes: those of the zone (`w`, `h`), consistent with `aspect-ratio` of the style, no layout shift
  (a CSS `aspect-ratio` on the `<img>` is used before the file loads in any case).
- Zone smaller than the box (the image is under `Wb`): the rewriter keeps what it has; the editor sees a warning in the crop block (section 4).
  No upscaling on the server (`^` never sent).
- Contain: no zone; candidates as today (`full`), `data-cms-height="{height_px}px"` so `ImageSlot::bandedTo` holds the announced width to
  `min(boxWidth, height * ratio)` (reused as is: the box is letterboxed, the image is as wide as that). On a narrow screen the real height is smaller
  than the declared one, so `sizes` slightly over-announces: a larger candidate than needed, not a blurred one.

### `data-cms-sizes`

- Custom: exactly `min({width_px}px, calc(100vw - 2rem))`. A single source size without a media condition is valid in `sizes`; it follows the CSS
  `width: min(Wpx, 100% - 2rem)` of the figure. It passes `ImageSlot::isValid` (words `min`, `calc`, `px`, `vw`, `rem` are on the list, `:66`); a new
  `ImageSlot::box(int $widthPx)` builds it and its test is added to `ImageSlotTest`.
- The 2 rem margin is the one of the other widths (`MARGIN`, `:52`); if the theme changes it, `blocks.css` and `ImageSlot` change together, as the docblock says (`:28-29`).

### CSS (`assets/blocks.css`)

```css
.cms-image--custom { margin: 0 auto; }               /* width: inline, min(Wpx, 100% - 2rem) */
.cms-image__img--cover   { object-fit: cover; }
.cms-image__img--contain { object-fit: contain; }
/* aspect-ratio and object-position are inline: they are values of the section */
```

`.cms-image__img {width:100%; height:auto}` stays: with `aspect-ratio` on the element, the height follows the width.

Why inline `style` for `aspect-ratio` and `object-position`: their values are the editor's numbers; a class per value is not possible, a custom property is another
route through the CSS filter. `style` passes today (`allowAttribute('style','*')`, `PublishedContentSanitizer.php:209`). The lot `fix/css-filter-csp` (done on
another branch, `docs/plans/css-filter-csp.md`) puts an allow list of functions on `style`: `aspect-ratio:960/540`, `object-fit`, `object-position:35% 20%`,
`width:min(960px, 100% - 2rem)` use no function beyond `min()` (kept by that plan's "keeps" cases); to check when both branches meet.

### Mobile

Decision 5. Recommended behaviour: the **ratio is kept**: the box is `min(Wpx, 100% - 2rem)` wide and `aspect-ratio` gives its height, so the zone computed on the server is
exactly what is seen at every width. Fixed height with a narrowing width would change the ratio and make the server zone wrong (CSS `object-fit: cover` would crop again, from the zone, around
`object-position`, which is why that fallback is written anyway).

### External https image (no IIIF zone)

No region and no dimensions known server side. The CSS still holds: `aspect-ratio` from the box, `object-fit`, `object-position` from the focus. Zoom cannot be honoured
(only a server-side zone or `object-view-box`, which Firefox lacks, can). Decision 6.

### Image of the library whose size is unknown (never measured)

`CmsMediaLibrary::fromUrl` measures on the way past (`:136`); if it still gets nothing, the rewriter keeps `src` as `full` and the CSS alone frames.

## 4. The screen

### Form put in order (no new library)

```
 6. Image                                                      [x]
 ------------------------------------------------------------------
 Image                                                          (?)
 +----------------------------------------------------------------+
 | [thumb]  tarte.jpg   2400 x 1600 px   Described                |
 | [Choose in the library]  [Remove]                              |
 +----------------------------------------------------------------+
 > Another source: a file or an address
 Width                                                          (?)
 Narrow for a photo...; wider only when the brief asks.
 [ Narrow ] [ As wide as content ] [ Whole screen ] [ Custom ]     <- one line, 4 buttons
   (only with Custom)
   Width (px) [ 960 ]   Height (px) [ 540 ]   [16:9][4:3][3:2][1:1][Image ratio]
   Fit        [ Fill (crop) ] [ Contain ]                             <- full line
   Framing    [ Frame the image... ]   Centre 35 % / 20 %, zoom 150 %, zone 1067 x 600 px
 Caption                                                        (?)
 [                                                              ]
 Text of the image                                              (?)
 [                                                              ]
 > Appearance
 [Save this section]
```

- Order = order of `ImageSection::schema()`: `image`, `width`, `width_px`, `height_px`, `fit`, `focus_x`, `focus_y`, `zoom`, `caption`, `alt`.
  The maintainer's order lists Image, Width, Caption, Appearance and does not place the text of the image: placed after the caption here (to confirm). The prompt
  lists the props in the same order, so the four `ImageSection` lines of the catalogue sheet move; the determinism bench (`docs/evaluation/determinism`)
  is rerun once on the Image briefs.
- "Custom" shows the dimension block; the width and height fields are number inputs (so they keep the 10 rem cap), side by side. The ratio buttons fill the height from the width
  (and "Image ratio" from the natural size); they write nothing but the two fields. Shown and hidden by `content-editor.js` on the value of `width`; without the script all fields stay visible
  (the form works, as the list rows do without it).
- Layout fix: drop `:not([data-cms-choice-buttons])` at `content-editor.css:381` and `:382` so a choice fieldset spans `1 / -1`; same for the choices of the appearance
  block (`.cms-content__appearance-fields > fieldset[data-cms-choice-buttons]`). `.cms-content__choices` keeps `auto-fit, minmax(8rem, 1fr)`: four buttons fit one line from about 34 rem and wrap
  whole below it. Check on the other sections that draw choice buttons (appearance: padding, alignment; any `Prop::choice` with `icons`) and in a list row, where a full-line choice changes the row.
- The help of each field: `editorHelp` written for the editor (what the field does for the page, in the words of the editor), e.g. Width: "How wide the picture is on the page. Custom sets the size in pixels."
  `help` stays the instruction to the model.

### Crop screen

Where it opens: the "Frame the image..." button of the dimension block (visible with Custom, both sizes set and Fill); disabled with a reason for an empty image or an external image
without a known size. Opens **inline** as a panel under the block (not a third stacked dialog over the section drawer and the media picker); closing it returns the focus to the button.

```
 +- Frame the image -------------------------------------------------+
 |  Original                                      Result at 960 x 540 |
 |  +-----------------------------+              +-----------------+ |
 |  |/////////////////////////////|              |                 | |
 |  |//+---------------------+////|              |   (what the     | |
 |  |//|          +          |////|              |    box shows)   | |
 |  |//+---------------------+////|              |                 | |
 |  |/////////////////////////////|              +-----------------+ |
 |  +-----------------------------+              Zone 1067 x 600 px  |
 |   dark = outside the zone, + = focal point     Shown at 960: sharp |
 |  Horizontal  [-------o--------]  35 %                              |
 |  Vertical    [----o-----------]  20 %                              |
 |  Zoom        [o---------------] 150 %                              |
 |  [Centre]  [Reset]                                      [Done]     |
 +--------------------------------------------------------------------+
```

- Pointer: drag inside the stage to move the frame (Pointer Events with `setPointerCapture`, `touch-action: none` on the stage only, so the page still scrolls elsewhere);
  a click puts the focal point there. The frame keeps the box ratio and stays inside the image. The zoom slider resizes it around its centre.
- Keyboard: the frame is a focusable element (`role="group"`, label "Zone kept, arrow keys to move"). Arrows move 1 %, Shift+arrows 10 %; `+` / `-` (and PageUp / PageDown) change the zoom by 10 %;
  Home centres; Esc closes the panel. The three sliders are native `input[type=range]` with a label and `aria-valuetext` ("35 %"): the same three numbers, with no dragging needed
  (WCAG 2.5.7), and they carry the values to the form (they are the `focus_x`, `focus_y`, `zoom` inputs, `type=range` in the crop block, `number` without script). A live region says "Zone 1067 by 600 pixels, at 35 percent, 20 percent".
- Preview: made in the browser from the full image already shown by the picker (`object-fit` / `object-position` on a box at the real ratio and `transform: scale()` for the zoom). **No derivative is asked while
  dragging**: each zone would write a file under `web/image-library/` (section 1). The first request of a derivative happens on the first view of the saved section.
- Resolution hint: zone width in the original (from the size the picker already knows) against the width shown (`width_px`, doubled for a 2x screen): "Shown at 960 from 1067 px: sharp" / "from 600 px: soft".
- Hidden values: the three fields are in the form (`SectionFormBuilder` draws them with `data-cms-crop`; `propsFrom` already reads integers), so a save without opening the panel still sends them.

New file `assets/image-crop.js` (vanilla, same shape as `media-picker.js`: `CmsImageCrop.bind(root)` called by `content-editor.js:1407` next to `CmsMediaPicker.bind`), a block of `content-editor.css`, the asset version
hash of `ContentScreen.php:185` extended with the new file.

**JS library (to propose, not decided).** Without: about 200 to 300 lines of vanilla JS for a frame locked to a ratio that moves and scales; no new dependency, no CSP source, loaded
like the other assets. With: Cropper.js (MIT) or similar gives free-ratio zones, rotation, touch gestures and a ready preview, but our case is a locked ratio, there is a focal-point model (not a free zone) and a stored result of
three integers; a library would be kept for what it does not do here and its CSS has to be restyled for the Bootstrap theme. Sizes not measured here. Recommended: vanilla.

## 5. The generator

- The model keeps what it has: `width` among `narrow`, `column`, `full` (`custom` is `editorOnlyChoices`, not in the JSON schema enum nor in the sheet). `width_px`, `height_px`, `fit`, `focus_*`, `zoom`
  are `editorOnly`: not in `toJsonSchema`, not in the sheet, so the prompt stays the same except for the order of the Image lines (section 4).
- Consigne to add to the catalogue, in the description of `cms:image` (`ImageSection::description()`, one sentence, English as the rest): "The exact size of the picture and the part of it that is kept
  (framing) are set afterwards by the editor: leave them out; choose only the width."
- The grafts must not lose an editor's framing. In section mode and in translation (`docs/plans/generation-quality.md`, G6: only `text` and `html` come from the answer on a translation; a section answer replaces the
  section) `editorOnly` props are copied from the section being replaced **if the component and the image are unchanged**, otherwise reset to defaults (a new photo makes the old focal point meaningless).
  Test in `Tests/Unit/Generation`.
- `PropSchema` still validates the props of a document whatever wrote it (a generator, an import, a script): bounds are the section's, not the prompt's.
- Open: the file where the grafts live, `Generation/PageGenerator.php` and `Document::withSection()`, was not read for this plan; to read before step 8.

## 6. Points to decide

1. **Unit of the dimensions.** (a) px only, integers. (b) px and rem. (c) px, or % of the content width. They differ in what the server can compute: the zone is in pixels of the original and the ratio of the box
   must be known; % of a width of unknown size (a "full" band) gives no ratio, rem depends on the root font size of the theme. **Recommended (a)**: ratio and zone are exact, `sizes` is a plain length, the editor sees the numbers of the file.
2. **Bounds.** (a) Width 160-2400, height 80-2000, ratio 1:4 to 4:1, zoom 100-400. (b) Looser (up to the image size). (c) Only the image size. Bounds keep a band of 2400x80 or a 4000 px box
   from producing a derivative nobody wants and a `sizes` that lies. **Recommended (a)**, constants in one place (`ImageSection`), a message that names the bound.
3. **How the kept part is stored.** (a) Focus (x, y) + zoom, 3 integers: ratio independent, a slider each, zone derived. (b) The zone itself x,y,w,h in % of the original (4 numbers): free choice, but a change of the
   box ratio invalidates it, and it needs a free-ratio editor. (c) Focus only (no zoom): simplest, CSS-only, no server zone, no band saving. **Recommended (a)**: it uses what TheliaLibrary serves (zone) and survives a change of ratio.
4. **Preset width and a height.** (a) Height only with Custom width: the zone is exact (a ratio is known). (b) Height also with the three presets: a banner at the whole width of the screen, but the ratio then depends on the viewport, which
   rules out a server zone (CSS `object-fit` only). (c) Both, with a ratio chosen instead of a height for the presets. **Recommended (a)** now; (c) is an extension if banners are wanted (note for the roadmap).
5. **Mobile.** (a) Ratio kept: box scales down, same zone. (b) Height fixed in px, width shrinks: the picture is cropped more tightly on a phone (CSS only: the server zone no longer matches). (c) A second ratio for narrow
   screens (art direction): two zones, a `<picture>` with a media condition; more fields, more derivatives. **Recommended (a)**; (c) later if wanted.
6. **An external https image (no zone).** (a) Frame with CSS only: box, `object-fit`, `object-position` from the focus; zoom is disabled with a visible reason. (b) Refuse dimensions on an external image (the controls
   stay off). (c) Zoom through a clipping wrapper and `transform: scale`, which shows the zoom but downloads the whole file and blurs it. **Recommended (a)**: still useful (the box and the focal point), honest about the zoom.
7. **JS library for the crop screen.** (a) None: vanilla frame locked to the ratio. (b) Cropper.js or equivalent. (c) A web component of the core if the Thelia back-office ships one (not looked for). **Recommended (a)**, section 4.
8. **Where the screen of framing opens.** (a) Inline panel in the drawer. (b) A dialog stacked over the drawer as the media picker is. **Recommended (a)**: one layer less to manage focus in; (b) if the drawer is too narrow for the stage in use.
9. **Order of the form.** The maintainer's order places Image, Width, Caption, Appearance; the text of the image (`alt`) is placed after the caption here. And the prop order of the schema is the prompt order (the four lines of the catalogue move):
   (a) reorder the schema (recommended; the bench is rerun once); (b) keep the schema order and give the form its own order (a new field of `Prop`, more code for a cosmetic gain).

Not decided here but to confirm: whether to keep `width = custom` as a value of the `width` choice (recommended, section 2) or add a separate mode prop.

## 7. Commits, tests, size, risks

One branch from `main` (`git fetch --prune`, `git checkout main && git pull --ff-only`, branch `feat/image-dimensions`). Titles `type(scope): message` unless a ticket is given (no ticket given for this plan);
each test written first and seen red against `main` by sabotaging the code it covers.

| # | Commit | Content | Size |
|---|---|---|---|
| 1 | `fix(content-editor): choice buttons take the whole line` | CSS `:381-383`, appearance choices full line; reproduce the fault first in a form with a short field next to a choice | S |
| 2 | `feat(content): editor help, editor-only props` | `Prop::editorHelp`, `editorOnly`, `editorOnlyChoices`, `editor`; form uses `editorHelp`; `toJsonSchema` and `CatalogSheet` skip them | S |
| 3 | `refactor(content): order and editor help of the Image section` | new order, `editorHelp` strings, fr and en catalogues (`I18n/fr_FR.php`, `en_US.php`) | S |
| 4 | `feat(content): size, height and fit of the Image section` | props, bounds, `ImageSlot::box`, markup, `blocks.css`, legacy byte-equality test | M |
| 5 | `feat(content): the kept part of an image, served by the library` | `ImageFrame`, `data-cms-crop`, rewriter (zone URLs, srcset, `width`/`height`), sanitizer attribute | M |
| 6 | `feat(content-editor): dimension fields of the Image section` | conditional block, ratio buttons, `propsFrom` | S |
| 7 | `feat(content-editor): frame the image` | `assets/image-crop.js`, CSS, asset hash, keyboard and pointer | L |
| 8 | `feat(generation): keep the framing through a rewrite` | editor-only graft, consigne in the description, section and translation modes | S |
| 9 | `docs(image): bench pages, docs, changelog` | `make-pages.py`, `measure.js`, `docs/sections.md`, README, CHANGELOG, roadmap N8 | S |

Possible measure to land first, as its own commit or its own branch if the dev prefers: the portrait defect of `n,!` (section 1), by reading a portrait of the bench; if confirmed, the candidates of the *existing* rewriter are written `n,m` too.

### Tests

- Unit (no database): `ImageFrameTest` (table: landscape original in a landscape and a portrait box, zoom 100/200/400, focus 0 and 100 against the edges, equal ratio gives `full`, an original smaller than the box, a 1 px zone,
  rounding keeps the ratio within 1 px); `ImageSectionTest` (the four existing strings unchanged, then custom fill, custom contain, custom with `width_px = 0`, bounds refused, an old document gets the defaults);
  `PropSchemaTest` (new bounds); `ImageSlotTest` (`box()` is valid by `isValid`); `ImageRewriterTest` (zone URLs, widths strictly below the zone, last candidate `max`, the intermediate `n,m`, WebP source with the same zone, `width`/`height` of the zone,
  attribute removed, invalid `data-cms-crop` ignored, external and unknown image untouched, contain keeps `full` and bands `sizes`); sanitizer test (`data-cms-crop` and the three style properties survive, a `url()` in `style` does not);
  `CatalogSheet` and `Prop::toJsonSchema` tests (editor-only absent, `custom` absent from the enum, `help` in the sheet unchanged, the example unchanged); graft tests of section 5.
- Integration: `SectionFormBuilder` (editor help shown, order, the choice fieldset has the full-line marker, the dimension block, `propsFrom` on the new integers); a section edit screen test as `SectionImagePickerTest` does.
- Seen red: each test above is run once against a deliberately broken implementation (swap the clamp, drop the removal of the attribute, write `full` in `url()`), and the zero of the grep for `data-cms-crop` left in published HTML is seen to fail on a planted case.
- Proof in the browser at 1440 px (and 375, 768 with DPR 2) with the bench `docs/evaluation/images/`:
  1. Existing pages: `thelia_cms:render-published --all --dry-run` (no write): every existing page is reported unchanged, then `measure.js` rows before and after equal on `img-test-sections`.
  2. `make-pages.py` gets a second page (`--slug img-test-frame`) with a test image of four coloured quadrants (colour reads the corner kept) and these sections: custom 960x540 fill at focus 0/0, 100/100, 50/50, zoom 200; the same at 600x800 (portrait box on a landscape image);
     contain 960x540; an image smaller than the box; an external https image with a box and a focus; a portrait original; a custom width alone (no height); a legacy section.
  3. `measure.js` is extended with `boxRatio` (`clientWidth/clientHeight`), the region parsed from `currentSrc`, and the shift measured by a `PerformanceObserver` on `layout-shift`; checks: ratio within 1 % of the box at 1440 and 375; candidate picked >= the shown width x DPR
     (`upscale` <= 1) and <= 1.5x (`overload`); no layout shift once the image loads.
  4. The pixel proof: draw the picked file on a canvas (same origin) and read the colour at the corner kept for each focus; fetch each region URL and check the answer's size equals `w x h` (or `n x m`), and an out-of-image zone is never emitted.
  5. The screen: in the drawer, open the crop panel, drag, then use the keyboard only (arrows, Shift, `+`/`-`, Home, Esc), read the three numbers, save, reopen; screenshot at 1440 and the 575 px drawer, the wide panel for the layout fix (choice buttons on their own line next to short fields).
     Cache check: count the files under `web/image-library/{id}/` before and after a session of dragging (no change), and after one save (one more branch of the tree).
  6. Quality gate of the module (`composer stan`, `composer cs`, `composer test`, `composer test:integration` on a Thelia 3 host, CI matrix main and 3.3.0).

### Size

Global: **L**. Parts: layout, order and editor help S (commits 1-3, about 5 h); markup and bounds M (commit 4, about 5 h); zone and rewriter M (commit 5, about 7 h); form fields S (commit 6, about 2 h); crop screen L
(commit 7, about 12 h); generator S (commit 8, about 3 h); bench, docs, browser proof (commit 9, about 5 h). About 39 h of pilot time. Hypotheses: decisions taken as recommended (vanilla JS, focus + zoom, px, ratio kept, no change to TheliaLibrary),
the pre-existing portrait defect is not larger than described, the test host has the two PNGs of the bench.

### Risks

- **Derivative cache without a bound** (TheliaLibrary writes every requested region under `web/`, public URL): the module only sends zones it computed from a quantised model and never while dragging; a hostile or buggy URL elsewhere is a defect of the library (region and size
  go unchecked into the path and into arithmetic, `ImageService.php:167`, `:209-220`) to report to that module, not here.
- **Library version**: `^2.0` allowed; `x,y,w,h` known to work on 2.2.1 only. Raise the constraint after a test on the lowest 2.x, or after reading its changelog.
- **Regeneration loses a framing**: handled by the graft (commit 8); tested.
- **Interplay with `fix/css-filter-csp`**: inline `style` values must survive its allow list; to run when both branches meet.
- **Published pages**: new props change no existing HTML (proved by the dry run); pages with a framing keep the zone URL until re-rendered after a replaced file (`thelia_cms:render-published`).
- **The portrait defect** may touch existing pages' srcset: fixing it changes published HTML of portrait images (a good change, but visible in the dry run; flag it in the changelog).
- **Order change** moves four lines of the catalogue sheet: the prompt cache prefix changes once; the bench is rerun on the briefs that ask for a picture.
- **Subgrid and full-line fieldsets**: a choice in a list row changes how the row looks; checked on every section that has choice buttons before the first commit.
- **Image replaced by one of other proportions**: percentages hold, the zone is recomputed at the next render; the editor should reopen the frame (a note in the help).

## Not verified

- The fault of the layout was not reproduced on the Image section (the drawer is one column at 575 px); only the CSS reading and the dev's description back it. Reproduce first (commit 1).
- Nothing was run: the portrait defect, the 400 and 500 cases of the image route, the sanitizer keeping `width`/`height`, `data-cms-crop` and `aspect-ratio`.
- `Generation/PageGenerator.php` and the graft code, `content-editor.js` internals, `ColumnsSection` bounds code, the other sections that draw choice buttons: not read.
- Sizes of vanilla versus library; support of `object-view-box`; TheliaLibrary below 2.2.1.

## Decided (2026-11-04, by the developer)

1. Crop stored in the section as a focal point and a zoom (x %, y %, zoom 100-400 %); the served region is computed for each requested size.
2. Pixels, ratio kept: width 160-2400, height 80-2000, ratio between 1:4 and 4:1; under the chosen width the image shrinks with its ratio (`aspect-ratio`). Height is editable in the "custom" mode only; the three presets stay.
3. The crop screen is a **dedicated dialog** above the drawer (not the panel unfolded in the drawer recommended above): more room for a large picture; keyboard: arrows move the point, +/- the zoom.
4. The other recommendations are kept as written: an external https image is cropped by CSS only (zoom off); no new JS library; the schema of the section is reordered (image, width, caption, text of the image), which moves four lines of the generator prompt; the model sets the presets only, never pixels nor a crop.

## Code decisions, phase A (2026-11-04)

- Commit titles are `type(hors-ticket): message` (the repository has no ticket), not `type(scope)` as the table of section 7 says.
- `Prop::forEditor(help, only, onlyChoices, editor)` sets the four editor fields on a built prop (a wither), instead of four more parameters on each factory.
- `Prop::offeredChoices()` is the one place that removes the editor-only values of a choice, for the JSON schema and the sheet.
- The bounds are the section's, checked in `ImageSection::normalizeProps()` whatever the width: width 0 or 160-2400, height 0 or 80-2000, ratio 1:4 to 4:1 when both are set, zoom by steps of 10. A preset width with a height is stored but drawn as the preset.
- The fit is a choice drawn as buttons (`bi-crop`, `bi-aspect-ratio`); the custom width has the pictogram `bi-rulers`.
- `data-cms-crop` is not written yet: the box is drawn by CSS alone (`aspect-ratio`, `object-fit`, `object-position`); zoom has no effect before phase B.
- Contain declares `data-cms-height="{height_px}px"` on the figure, as planned, for the rewriter of phase B.
- The asset version of `ContentScreen.php` is derived from the mtime of the files: nothing to bump by hand.

## Code decisions, phase B (2026-11-04)

- `Content/ImageFrame` is the pure class: `zone()` (section 3 arithmetic; the width is floored with a 1e-9 guard against float noise, the zone is clamped inside the original, `null` for the whole picture), `attribute()` and `parse()` of `data-cms-crop="focus_x,focus_y,zoom,box_width,box_height"`. `parse()` accepts only what `ImageSection` could have written (focus 0-100, zoom 100-400 by 10, box within the bounds and the 4:1 ratio); anything else is ignored.
- The section writes `data-cms-crop` on the `<img>` only in fill mode with both dimensions; contain and the width-only box do not carry it.
- The sanitizer lets `data-cms-crop` through on `<img>` only (`PublishedContentSanitizer`).
- `ImageRewriter::attributes()` takes an optional zone (4th parameter): `width`/`height` are the zone's, `srcset` candidates are `R/{n},{m}/0/...` strictly under the zone width then `R/max/0/...`, `sizes` as declared. The rewriter also rewrites `src` to the zone at `max` (a browser without `srcset` must not load the whole file). The fixed-height banding of contain is not applied to a zone.
- A zone equal to the whole picture, an unknown size and an external image take the path of before.
- Form (`content-editor.js`, `bindImageDimensions`): the block is bound to a section form that holds `width_px`, `height_px`, `fit`, `focus_x`, `focus_y`, `zoom` and the `width`/`fit` radios, by the end of the field names, so no other component is touched. Width, height and the proportions show with "A size in pixels"; the fit with a height; the summary with a height and the fit "fill". `focus_x`, `focus_y` and `zoom` are hidden by the script and stay in the form; the summary is written from them on every input, which is what the crop dialog of phase C will trigger.
- The button "Recadrer l'image..." is absent in B: the summary carries an empty `[data-cms-kept-slot]` where phase C puts it (a disabled button that does nothing would only mislead).
- "Image ratio" reads the natural size of the thumbnail the picker shows (the same proportions as the file); with no image shown it does nothing. Proportions fill the height from the width, clamped to 80-2000; without a width they move the focus to the width field.
- The caption and the text of the image get `cms-content__field--line` from the script (a line each); without the script they keep the grid.
