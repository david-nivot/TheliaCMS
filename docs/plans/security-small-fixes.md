# Plan: four small security fixes (S5, S8, S9, S10)

Status: planned, not started. Read on `main` at 65ddc42 (2.8.3, F1 merged).
Four points of wave 1 of the roadmap, independent of each other. Two choices
must be settled before S10 is coded (see "To settle").

## What is asked

- **S5**: the sandbox of the components of the site refuses more than two
  `for` inside each other.
- **S8**: the previews of a page, an item and a component answer with
  `Referrer-Policy: no-referrer`.
- **S9**: after a form is sent, the visitor goes back to a path of the site
  only, never to another site named by the Referer.
- **S10**: an uploaded image has a ceiling of weight and of pixels.

## S5: three loops inside each other

### What is there today

- `Content/Site/SiteTemplateRenderer.php`: tags `if`, `for`, `set` (41),
  functions `cycle`, `min`, `max` (52, `range` refused for having no bound),
  `MAX_LENGTH` 20 000 (57). `problems()` (66) refuses `constant`, `extends`
  and `use` by pattern (80, 84), then runs `checkSecurity()` (91) without
  rendering. The policy is built at line 131. Nothing counts the depth of the
  loops.
- A list prop holds at most 24 items (`Content/Site/SitePropReader.php:243`)
  and an item never holds a list (`SitePropReader.php:30-31`, `ITEM_TYPES` 39).
  Two loops are enough for any props a component can have; a third one can
  only multiply a list by itself (24³ rows, or far more through `split`).
- `render()` (108) runs `problems()` before every rendering: a stored
  component refused by a new rule draws nothing on the page and logs an error
  (`Content/Site/SiteComponentSection.php:84-87`).
- The rules are written in the prompt of the component generator
  (`Generation/Component/ComponentPrompt.php:196`) and in
  `docs/creating-a-component.md:302-305`.

### Principle

In `problems()`, after `checkSecurity()`: parse the template into its tree
(`Environment::tokenize()` and `parse()`, Twig 3.30 of the host,
`vendor/twig/twig/src/Environment.php:544,566`), walk it (`Node` is
iterable, `Node.php:290`) and count the `Twig\Node\ForNode` inside each
other. Above two, one problem that says so with the line of the third loop,
in the style of the other messages. A walk of the tree rather than a pattern
on `{% for`: a comment, a `verbatim` or a string holding the word would fool a
pattern.

### Files

`Content/Site/SiteTemplateRenderer.php` (constant `MAX_LOOP_DEPTH = 2`, the
walk), `Generation/Component/ComponentPrompt.php:196` (one rule more),
`docs/creating-a-component.md:302`, `CHANGELOG.md`.

### Test

`Tests/Unit/Content/Site/SiteTemplateRendererTest.php`:
- `refused()` (41) gets two cases: three `for` inside each other; three `for`
  with an `if` between the second and the third. Red on `main`: `problems()`
  answers `[]`.
- A new test: two loops inside each other, and three loops one after the
  other, give no problem (the cap is on depth, not on count).

### Edge cases

- A `for` in the `else` of a `for` is in the tree of the first one: counted as
  inside it. Stricter than needed, harmless.
- A `for` in a `{% set x %}…{% endset %}` block counts like any other.
- A component already stored with three loops is no longer drawn. Not
  measurable from here (no database read allowed): before the release,
  look on the sites for a template holding three `{% for` (a `LIKE` on
  `cms_component.template` is enough to list candidates by hand).
- The cap does not bound `split`: `for c in props.text|split('')` twice over
  a text of 2 000 characters (`SitePropReader.php:146`) is 4 million turns.
  See "Open questions".
- Changing the prompt changes what the generator receives: keep it out of a
  round of the determinism corpus (`docs/developing.md`, "The prompt reads
  translated words").

### Estimate

1 h 15 (walk and message 30 min, tests 20 min, prompt, doc and CHANGELOG
25 min).

## S8: the previews send no referrer

### What is there today

- The only `Referrer-Policy` of the module is set by
  `Preview/ItemDraftResponse.php:42`, for the page of an item opened with the
  draft token (`ItemDraftRequest::SERVED_ATTRIBUTE`), tested at
  `Tests/Unit/Preview/ItemDraftResponseTest.php:42`.
- The three preview controllers set `X-Robots-Tag` and `Cache-Control` and
  nothing else:
  - `Preview/CmsPagePreviewController.php:65-66` (route `cms.page.preview`,
    `/cms-preview/{id}/{locale}/{expires}/{signature}`);
  - `Preview/ItemPreviewController.php:78-79` (`cms.item.preview`);
  - `Preview/ComponentPreviewController.php:98-99` (`cms.component.preview`).
- Their address is the authorization (signature and expiry in the path,
  `CmsPagePreviewController.php:25-30`): every resource of another site the
  page loads and every link followed from it receives it in the Referer.

### Principle

The same header as `ItemDraftResponse`, on the three responses. Two ways, see
"To settle" (1).

### Files

The three controllers, `CHANGELOG.md`.

### Test

One assertion next to the `X-Robots-Tag` one in the three integration tests
that already open each preview:
- page: `Tests/Integration/Generation/SectionEditScreenTest.php:348`
  (`testThePreviewOfAPageShowsTheWholePageOrItsSectionsAlone`), on the whole
  page and on the content alone;
- item: `Tests/Integration/Catalogue/ItemContentScreenTest.php:204`
  (`testTheSignedLinkShowsTheDraftAndATamperedOneIsNotFound`);
- component: `Tests/Integration/Content/SiteComponentScreenTest.php:79`
  (`testARequestEndsOnACandidateDrawnInTheThemeAndNothingIsKeptYet`).
Red on `main`: the header is `null`.

### Edge cases

- A form posted from inside a preview no longer sends a Referer: S9 takes the
  visitor back to `/`. A preview is not where forms are sent; accepted.
- Nothing else of the module, of the core or of the installed modules reads
  the Referer (see "The same pattern elsewhere"), nor the Live components of
  Symfony UX (`vendor/symfony/ux-live-component/src`, pattern `referer`).
- The cache headers are not overwritten afterwards: `PageCacheListener`
  (`Http/PageCacheListener.php:52`) acts only on a request carrying the tags
  that `Page/CmsPageViewListener.php:98` alone sets.

### Estimate

45 min (three lines and three assertions 15 min, integration suite seen red
then green 20 min, CHANGELOG 10 min).

## S9: the way back after a form

### What is there today

`Form/Front/CmsFormSubmitController.php`, `backTo()` (132-148): the host of
the Referer is compared with the served host (140), then its path is taken as
it is (141) and the redirect is `path#anchor` (147). A Referer
`http://host//other.site/x` has the right host and the path
`//other.site/x`: the browser reads the redirect as an address on another
site. `http://host/\other.site` does the same (browsers read `\` as `/`). A
Referer without a host (`javascript:alert(1)`) gives a relative path
(`alert(1)#anchor`). No test opens this route today (patterns `cms-form`,
`CmsFormSubmitController`, `referer`, `HTTP_REFERER` in `Tests/`).

### Principle

Keep the path only when it starts with `/` followed by anything but `/` or
`\`; otherwise `/`. The query string is kept as today. To test it without a
form in the database, move the computation of the path into a small class of
`Form/Front/` (a pure function of the Referer and the host), called by
`backTo()`; the anchor stays in the controller.

### Files

`Form/Front/CmsFormSubmitController.php`, one new class in `Form/Front/`,
`CHANGELOG.md`.

### Test

A new unit test in `Tests/Unit/Form/` with a data provider:

| Referer | Path expected |
|---------|---------------|
| `http://host/page?x=1` | `/page?x=1` |
| `http://host` | `/` |
| `/page` (no host) | `/page` |
| `http://host//other.site/x` | `/` |
| `http://host/\other.site` | `/` |
| `http://host/%2F%2Fother.site` | kept: encoded, a path of the site |
| `http://other.site/page` | `/` |
| `//other.site/x` | `/` |
| `javascript:alert(1)` | `/` |
| empty | `/` |
| `http://host/` + tab + `/other.site` | `/` or `/_/other.site` |

Red on `main`: move the current lines into the class unchanged first, run the
test (the `//`, `\` and `javascript:` cases fail), then add the rule.

### Edge cases

- Hypothesis to confirm by the last case of the table: `parse_url()` turns a
  control character of the path into `_`, so a tab between two slashes (which
  a browser drops) cannot rebuild `//`. Whatever it does, the expected path
  never starts with `//`.
- A host written in capitals in the Referer is refused today (strict
  comparison, line 140) and sends to `/`: safe, kept.

### Estimate

1 h (class and rule 20 min, test 25 min, CHANGELOG 15 min).

## S10: a ceiling on uploaded images

### What is there today

- Three `Image` constraints with formats only, no `maxSize`, no `maxPixels`,
  written three times the same way:
  - `Page/Admin/CmsPageType.php:197-200` (`imageUpload`, image of a page);
  - `Media/Admin/CmsMediaType.php:75-78` (`file`, replacing an image);
  - `Generation/Admin/SectionFormBuilder.php:415` (file of an image prop).
- **A fourth way in, without any constraint**: sending to the library
  (`Media/Admin/CmsMediaAdminController.php:144-156`, route
  `admin.cms.media.add`, used by the list and by the picker of a section,
  `SectionFormBuilder.php:407`) goes through `CmsMediaWriter::refusalOf()`
  (`Media/Admin/CmsMediaWriter.php:125-134`), which checks the PHP upload
  error and the type, nothing else.
- What reads the image: on upload, TheliaLibrary reads its header only
  (`getimagesize()`, `vendor/thelia/modules/TheliaLibrary/Service/LibraryImageFileNamer.php:78`,
  `LibraryImageService.php:205`). It is decoded when a variant is first drawn
  (`Service/ImageVariantService.php:104,141`), with GD by default
  (`Service/ImageService.php:423`). A small file whose header declares
  50 000 × 50 000 pixels is accepted today and costs its whole bitmap in
  memory at every attempt to draw a variant.
- Limits that exist:
  - module: none on images (patterns `maxSize`, `maxPixels`, `MAX_SIZE`,
    `MAX_BYTES`, `pixels`, `* 1024`); the screen of the library announces
    `UploadedFile::getMaxFilesize()` (`CmsMediaAdminController.php:87`) and
    its script refuses a larger file before sending
    (`templates/backOffice/default-twig/assets/media-upload.js:38,79`);
  - TheliaLibrary: none (same patterns in its sources; its API resource
    declares `Assert\Image` with formats only, `Api/Resource/LibraryImage.php:118`);
  - core: minimums of the installer only, `memory_limit` 128 MB,
    `post_max_size` 20 MB, `upload_max_filesize` 2 MB
    (`vendor/thelia/core/lib/Thelia/Core/Install/CheckPermission.php:44-46`);
  - PHP: `upload_max_filesize` of each server, the only ceiling in force.
- `Symfony\Component\Validator\Constraints\Image` of the host (7.4.22) takes
  `maxSize` (an integer of bytes or `'10M'`, SI unless `Mi`,
  `File.php:213-226`) and `maxPixels` (`Image.php:71,136`), checked from
  `getimagesize()` (`ImageValidator.php:63,157-165`).

### Principle

- One ceiling for the module, next to `CmsMediaType::ACCEPTED_MIME_TYPES`:
  a weight in bytes and a number of pixels, values in "To settle" (2).
- One constraint built in one place and used by the three fields, with
  messages of the module (`maxSizeMessage`, `maxPixelsMessage`) in `I18n/`.
- The same ceiling in `CmsMediaWriter::refusalOf()`: weight from `getSize()`,
  pixels from `getimagesize()`; a refusal for the pixels next to `TooLarge`
  in `Media/Admin/MediaUploadRefusal.php`, with its message.
- The screen of the library announces and checks the smaller of the ceiling
  and `UploadedFile::getMaxFilesize()` (`CmsMediaAdminController.php:87`):
  only the value of `data-max-size` changes, not the script.
- The editor documentation says the ceiling where it lists the formats
  (`templates/backOffice/default-twig/documentation/_en_US.html.twig:284`
  and `_fr_FR.html.twig:284`).

### Files

`Media/Admin/CmsMediaType.php`, `Page/Admin/CmsPageType.php`,
`Generation/Admin/SectionFormBuilder.php`, `Media/Admin/CmsMediaWriter.php`,
`Media/Admin/MediaUploadRefusal.php`, `Media/Admin/CmsMediaAdminController.php`,
`I18n/en_US.php`, `I18n/fr_FR.php`, the two documentation templates,
`CHANGELOG.md`.

### Test

Two fixtures, no real large image: a PNG made of its signature and a header
that declares 6 000 × 6 000 pixels (hypothesis to confirm on the first run:
`getimagesize()` and the type detection read only these bytes), and a file
that starts as a small PNG and is stretched past the weight ceiling with
`ftruncate()` (sparse, nothing written).
- `Tests/Unit/Page/CmsPageTypeTest.php` (already builds the form with the
  validator): `imageUpload` with each fixture is refused, a 2 × 2 PNG is
  accepted.
- A new `Tests/Unit/Media/CmsMediaTypeTest.php`, same three cases on `file`.
- `Tests/Integration/Media/MediaUploadScreenTest.php`, next to
  `testSeveralFilesAreStoredAndTheRefusedOnesSaidWithoutCostingTheOthers()`
  (48): the two fixtures sent with a small PNG, the small one is stored, the
  two others are said refused.
- `SectionFormBuilder` has no test of its file field, and its unit builder
  cannot grant the upload (`Tests/Unit/Generation/SectionFormItemsTest.php:80-88`,
  `SecurityContext` without constructor): it is covered by sharing the
  constraint. Control: after the change, `new Image(` appears once in the
  module (grep seen to find the three today).
Red on `main`: the fixtures are accepted everywhere.

### Edge cases

- A file cut by PHP keeps its answer (`UPLOAD_ERR_INI_SIZE`, `TooLarge`).
- Replacing an image (`CmsMediaWriter::save()`, 75) goes through the form of
  `CmsMediaType`: covered by its field.
- An image already stored above the ceiling stays: the ceiling is checked on
  upload only.
- SVG stays refused (`CmsMediaType.php:33-36`): `maxPixels` does not apply to
  it.

### Estimate

2 h 30 (ceiling and shared constraint 20 min, writer, refusal and screen
30 min, messages and documentation in two languages 25 min, unit tests
35 min, integration test 25 min, CHANGELOG 15 min). Without the fourth way
in (see "To settle", 3): 1 h 30.

## Order of the commits

One branch from `main` (`fix/security-small-fixes`), one PR, one commit per
fix with its test and its CHANGELOG line, smallest first:

1. `fix(hors-ticket): send no referrer from the previews of a page, an item and a component` (S8)
2. `fix(hors-ticket): take a visitor back to a path of the site only after sending a form` (S9)
3. `fix(hors-ticket): refuse more than two loops inside each other in a component of the site` (S5)
4. `fix(hors-ticket): cap the weight and the pixels of an uploaded image` (S10)

After the merge: `chore(hors-ticket): ship four security fixes as 2.8.4`,
`<version>` of `Config/module.xml` only. No schema change: a patch version.

## CHANGELOG

Under `[Unreleased]`, a `### Security` section (the type the file already
uses, `CHANGELOG.md:1240`):

- S8: `The previews of a page, a catalogue item and a component send no referrer (Referrer-Policy: no-referrer): their address, which carries the signature, no longer reaches the sites they load or link to.`
- S9: `After a form is sent, the visitor goes back to a path of the site only: a Referer whose path starts with two slashes or a backslash took them to another site.`
- S5: `A component of the site may put at most two loops inside each other: a third one only multiplied a list by itself and could hold a page for seconds.`
- S10: `An uploaded image is refused above <weight> or <pixels> pixels, in the library, on a page and in a section: a small file declaring a huge picture was accepted and decoded whole on every thumbnail.`

## Decided (2026-11-03)

These choices are settled; do not reopen them.

1. **S8:** one line in each of the three preview controllers, next to
   `X-Robots-Tag`, each with its assertion. No shared helper.
2. **S10, the values:** 25 Mpx and 10 MiB (10 485 760 bytes, binary like
   `Media/Admin/MediaItem.php:76-80`, which divides by 1024; `binaryFormat`
   on the constraint). Measured: a 25 Mpx variant drawn by GD takes the
   process from about 100 to 294 MB, some eight bytes a pixel, allocated
   outside the counter of `memory_limit`.
3. **S10, the fourth way in:** `CmsMediaWriter::add()` gets the same ceilings,
   in the same commit (estimate 2 h 30 for S10).
4. **S5, the prompt:** the rule (two nested `for` at most) is written in the
   prompt of the component generator.

## Open questions

- S5: are there stored components with three loops inside each other on the
  sites? Not measured (no database here).
- S5: `split` with an empty separator turns a text into as many items as it
  has characters; two such loops reach millions of turns under the cap. Bound
  `split`, or the length of what a component draws? Outside this plan.
- S10: the bytes per pixel of GD and the memory left once Thelia is booted,
  to measure before freezing the value.
- S10: the messages of the existing `mimeTypesMessage` are translated
  through `I18n/` (`fr_FR.php:407`); check on screen that the two new ones
  are too.

## The same pattern elsewhere

- **Referer taken as it is**: only `CmsFormSubmitController.php:134-143`.
  Patterns: `referer|referrer` (case-insensitive) in PHP, Twig and JS of the
  module; `headers->get('referer')`, `getReferer`, `HTTP_REFERER` in
  `vendor/thelia/core/lib` and `vendor/thelia/modules` (nothing);
  `document.referrer`, `location.href =`, `window.location =` in JS and Twig
  (nothing); `success_url`, `error_url`, `_target_path` (nothing). Every other
  `new RedirectResponse(` is built from a generated route, or from the host
  of the request (`Vitrine/TrailingSlashRedirectListener.php:135-139`, an
  absolute address, safe); `CmsSiteComponentController::backTo()` (417)
  builds routes from a code checked by pattern.
- **Upload without a ceiling**, besides the three fields and the library
  (S10):
  - documents of a page: `PageDocumentWriter::refusalOf()`
    (`Page/Document/PageDocumentWriter.php:284-295`), ceiling of PHP only,
    which the screen announces (`CmsPageAdminController.php:407-408`). Not an
    image, never decoded: signalled, not in this plan;
  - outside the module, to report upstream: `Assert\Image` with formats only
    in TheliaLibrary (`Api/Resource/LibraryImage.php:118`) and `new Image()`
    in the core (`vendor/thelia/core/lib/Thelia/Form/Image/ImageModification.php:48`);
  - `Settings/SiteFonts.php:77` (`store()`) has no caller outside the tests
    (patterns `fonts->store`, `->store($`, uses of `SiteFonts`): nothing to cap
    today.
  Patterns: `new (Assert\\)?(Image|File)\(`, `Constraints\\(Image|File)`,
  `maxSize`, `maxPixels`, `UploadedFile`, `FileType::class`, `files->all`,
  `files->get`.
- **Preview answers**: only the three controllers and `ItemDraftResponse`.
  Patterns: `Referrer-Policy`, `X-Robots-Tag`, `#[Route` near `preview`,
  `draft`, `signature`, `token`, and the list of the routes outside
  `/admin`.

## Estimate

About 5 h 30: S5 1 h 15, S8 45 min, S9 1 h, S10 2 h 30. With the shared helper
of S8 (1 b), 5 h 45; without the fourth way in of S10 (3), 4 h 30.
