# Image sizes bench

Measures, in a real browser, what `sizes` an image of a published page
announces and whether the file the browser picks fits the size it is drawn at.

## Files

- `measure.js`: an arrow function to run in the page. For each `<img>` it
  returns (as `lines`, one string per image, and as objects in `window.imageRows`) `sizes`, `currentSrc`, the width of the candidate picked (the `w`
  descriptor read from the `srcset` of the image and of its `<picture>`),
  `naturalWidth`, `clientWidth` and `devicePixelRatio`, and two ratios:
  - `upscale` = `clientWidth x DPR / candidate`: above 1, the image is drawn
    larger than the file and looks blurred;
  - `overload` = `candidate / (clientWidth x DPR)`: above 1.5, a file much
    larger than needed was downloaded.
- `make-pages.py` writes `pages.json`, a site export (`thelia_cms:import`)
  with one page that has an image or columns section of every kind: `full`,
  `column` and `narrow` images, two to four columns, an external `https`
  image, an image in free text, a component of the site that draws one.
  Arguments: `<width>=<id>:<stored file name>` for the four library images
  below, and the slug of the page (`--slug`, default `img-test-sections`).
  One page per run, so a page keeps the `sizes` it was published with.

- `measure.js` also returns, per image, `boxRatio` (`clientWidth / clientHeight`
  of the box as drawn), `region` (the part of the file asked, read from
  `currentSrc`: `full`, or `x,y,w,h`) and `corners` (the colour of the four
  corners of the file the browser took, top left, top right, bottom left, bottom
  right), and for the page `layoutShift` (sum of the `layout-shift` entries since
  the start of the measure; 0 when the boxes hold their size before the files
  arrive).
- `make-quadrants.php` writes the picture of the framing bench: 1600 x 1000 px,
  four plain quarters (red, green, blue, yellow top left to bottom right), so
  `corners` says which part of the original a file shows.
- `make-pages.py --frame` writes the page of the framing bench (default slug
  `img-test-frame`): sections of the Image component with a size in pixels, at
  focus 0/0, 100/100, 50/50 and zoom 200, in a portrait box (600 x 800), shown
  whole, with a picture narrower than the box, an external address, a portrait
  original, a width alone and a legacy section. Arguments:
  `quadrants=<id>:<file> portrait=<id>:<file> small=<id>:<file>`.

- `make-pages.py --components` writes the page of the components bench (default
  slug `img-test-components`): a component of the theme and a component of the site
  (carried by the export, `ImgTestPhoto`) with their pictures framed, at the top level
  and in the rows of a list. Arguments: `quadrants=<id>:<file> portrait=<id>:<file>`.
  The component of the theme is a fixture: copy `fixtures/ImgTestCard.html.twig` to
  `components/Organisms/ImgTestCard/Base.html.twig` of the front theme and
  `fixtures/theliacms.yaml` to its `config/theliacms.yaml` (if the theme declares
  none), then clear the cache. The quadrants make each zone a plain colour:
  `theme-1x1-red`, `theme-original-yellow`, `theme-16x9-green`, `row-1x1-green`,
  `site-16x9-blue`.

## Run it

1. Upload four test pictures of 308, 900, 1200 and 2400 px wide to the media
   library (any picture, 4:3), and note the id and stored file name of each.
2. `python3 make-pages.py --slug img-test-x 308=9:aaa.jpg 900=10:bbb.jpg
   1200=11:ccc.jpg 2400=12:ddd.jpg > pages.json`, then
   `php Thelia thelia_cms:import pages.json` and
   `php Thelia thelia_cms:publish --page <id>` (the id the import created).
3. In a browser, open the page in a fresh tab (a candidate once chosen is not
   given up for a smaller one on the same page), set the viewport (375, 768
   and 1440 px wide; DPR 2 on the first two, 1 on the last) and run
   `measure.js`. With Chrome DevTools: paste `await (<the file>)()` in the
   console, or give the file to `evaluate_script`.
4. Compare the rows before and after a change: same page content, a new page
   published under each version of the code.

## The framing bench

1. `php make-quadrants.php quadrants.png`, upload it to the media library (any
   tag the CMS reads), note its id and stored file name; take a portrait picture
   and one under 960 px wide from the library.
2. `python3 make-pages.py --frame quadrants=<id>:<file> portrait=<id>:<file>
   small=<id>:<file> > pages.json`, import and publish as above.
3. Open the page in a fresh tab at 1440 px (DPR 1) and 375 px (DPR 2), run
   `measure.js`. Checks: `boxRatio` within 1 % of the box (16:9 = 1.78, 600 x 800 =
   0.75) at both widths; `corners` of the fill sections as the focus says (focus 0/0
   keeps the red corner at top left, zoom 200 at 0/0 shows red only); a zone
   narrower than the box asks `region` inside the original; `upscale` at most 1 and
   `overload` at most 1.5 except where the next candidate is far larger (a 600 px box
   at DPR 1 takes the 960 candidate); `layoutShift` 0.
