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
