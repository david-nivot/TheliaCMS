# Changelog

All notable changes to this module are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this module adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **A Highlights section (`cms:highlights`).** A band of one to three blocks,
  the first one the large one, each a picture under a title, a catchphrase and a
  button, in the structure of the HeaderHighlights module and drawn by the module
  (`cms-highlights…` in `assets/blocks.css`). The band runs the full width of the
  page: the first block takes two thirds, the others are stacked beside it, and
  each text sits in a light panel at the bottom left of its picture, with a
  rounded dark button; the blocks are stacked below 48rem. A block may
  carry a picture for a screen narrower than 1024px (`image_mobile`); without one,
  the picture of the computer is drawn on every screen. The picture, its link and
  its button are optional, and the centre kept of a picture is set by the editor,
  not offered to the generator. The section is added to the dialog of the Text and
  media family, with its French labels. On publication, the `<source>` of such a
  `<picture>` gets a set of widths and the `sizes` of its `<img>`, like any picture
  of the library the author wrote with a media query. No schema change.
- **The list of sections follows the preview.** Scrolling the preview of a page marks the section on show in the list of the content screen and scrolls the list to it, unless a section is open for editing or being dragged; the scroll asked for by the locate button is not answered, and the motion is dropped under `prefers-reduced-motion`.

### Changed

- **The content screens of pages and catalogue items share one controller and
  one writer.** Routes, rights and behaviour do not change. For module code:
  `ItemContentWriter`, `PageVersions`, `CmsPageContentController` and
  `ItemContentController` are removed. `ContentWriter` (with `ContentTargets`),
  `ContentVersions` and `ContentController` replace them, and
  `PublishedRendering` moved to `TheliaCMS\Content\Editing`.

### Fixed

- **A screen of the CMS opened without being logged in leads to the login.**
  A screen under `/admin/cms` opened in the browser with no administrator
  logged in answered a bare 403, "Administrator authentication is required.",
  where the screens of the core send to the login of the back office. It now
  sends there too, with `_target_path`, so that logging in brings the editor
  back to the screen. A script (`X-Requested-With`, or a request that does not
  ask for HTML), a write and an administrator without the right still get the
  403.
- **The toolkit page of every section shows where each one starts and ends.**
  On `/toolkit/cms-all-sections`, the name of a section was smaller than the
  titles the section draws, and nothing closed its drawing. Each name is now a
  ruled heading of the toolkit, and each drawing sits in the dashed canvas of
  the toolkit, with no new style.

## [2.12.0] - 2026-11-04

### Added

- **A section takes a precise room, inside and outside.** The **Appearance** group
  of "Edit by hand" gains a room button, **Precise room**, which shows eight
  fields (another button hides them and leaves them out): room inside the
  section, top, bottom, left and right (`padding_top`, `padding_bottom`,
  `padding_left`, `padding_right`, the background goes with it), and room
  outside it on the same four sides (`margin_*`: above and below, it keeps the
  section apart from its neighbours; left and right, it narrows the band). On a
  screen narrower than 48rem, a side on the left or the right takes 5% of the
  width at most, so that a phone keeps room for the content. A value is a number of two decimals at most and `px` (0 to 320) or
  `rem` (0 to 20), such as `64px` or `2rem`; `calc()`, `var()`, negative numbers and
  other units are refused, and the style is built from the checked value only.
  The buttons Small, Medium and Large stay and set the room inside, above and
  below together; in a document, a precise side wins over them for that side.
  `padding` also accepts a length. A section holding either opens on **Precise
  room**, its room for both sides shown in the inside fields left empty. An empty field is the room of the component, as before. A
  section saved before writes the same markup, byte for byte. The generator is
  not offered the precise values: its schema and its catalogue sheet keep the
  three presets. An exported site carries them with the document; a site
  without this release refuses the document of a page that holds one (warning,
  page imported without its document).
- **The Image section takes a size of the editor's choosing.** A fourth width,
  "A size in pixels" (160 to 2400), sets the width of the picture; with a height
  (80 to 2000, a box no more stretched than 4:1 or 1:4) the picture sits in a
  box of those proportions, which it keeps as the screen narrows: it either
  fills the box around a kept part (horizontal and vertical position, zoom by
  steps of 10, 100 to 400) or is shown whole in it. The size, the fit and the
  kept part are for the editor alone: the generator is not offered them (see
  the entry on rewrites below). A section saved before keeps its exact markup. This
  release draws the box with CSS (`object-fit`, `object-position`,
  `aspect-ratio`) and, for a picture of the media library, serves the kept part
  itself (see the next entry).
- **A picture that fills a box is served as the part the editor kept.** On
  publication, the media library gives the zone of the file (`x,y,w,h` of the
  region of its image route) instead of the whole file, in the proportions of the
  box: the candidates of the `srcset` are strictly narrower than the zone, the
  last one is the zone at its own size, WebP and the original format alike, and
  `width` and `height` are those of the zone. A zone is never outside the file
  and never upscaled; a zone that is the whole file, a picture shown whole, an
  address kept elsewhere or a file of unknown size are served as before. The
  section says the kept part with `data-cms-crop` on the image, which is read
  and taken out on publication.
- **A zone written in the address of an image is served as the section's own.**
  An `<img>` whose address names a zone of the media library
  (`/image-library/{id}/{x,y,w,h}/…`) is rewritten on publication like the
  zone of an Image section: `srcset` candidates strictly narrower than the zone,
  then the zone at its own size, WebP and the original format alike, `width`
  and `height` of the zone. A zone outside the file, empty, or the whole file
  is ignored and the picture is served whole; a region other than four
  integers (`square`, `pct:`) is left to the library. Before, the region of an
  address was thrown away on publication. The zone of `data-cms-crop` still
  wins when both are there.
- **The pictures of the components of the theme and of the components made
  from a brief can be framed.** Each picture, at the top of the form and in the
  rows of a list, gets the proportions (the image as it is, 16:9, 4:3, 3:2, 1:1,
  3:4), a kept part (centre and zoom) and the "Crop the image…" dialog of the
  Image section. The component never sees these settings: the module hands it the
  address of the zone of the file (`/image-library/{id}/{x,y,w,h}/max/…`), which the
  publication turns into its set of widths. A component nobody framed gets the
  address it always got, so its markup does not change. The component keeps its
  own frame: if its box is shaped differently from the proportions chosen, the
  theme crops again. A picture kept elsewhere is drawn as it is, and the dialog
  says why it cannot crop it. The settings are named after the picture
  (`photo__proportions`, `photo__focus_x`, `photo__focus_y`, `photo__zoom`) and are
  never shown to the model.
- **A rewrite keeps the framing of each picture of a component.** The framing of a
  picture of a theme or site component is hidden from the model and given back
  to the section a rewrite replaces, picture by picture: a picture that stayed
  keeps its framing, one that changed takes the defaults, in the rows of a list
  too.
- **The size of an Image section is set in a form that follows the choice.** With
  "A size in pixels", the width and height fields show, with the proportions
  16:9, 4:3, 3:2, 1:1 and that of the picture (one click fills the height from
  the width); the fit shows once a height is set. The kept part (position and
  zoom) is no longer three bare numbers: it reads as a sentence ("Centre 35 % /
  20 %, zoom 150 %"), the three fields staying in the form. The caption and the
  text of the image each have a line of their own. Without the script, every
  field is shown and the form works as before.
- **The kept part of an image is set in a dialog of its own.** Next to the
  sentence that says it, "Crop the image…" opens a dialog above the section: the
  whole picture with the zone kept lit, what the box will show (made in the
  browser, no file is asked of the server while the zone moves), the width of the
  zone in the original against the width shown (sharp, sharp on a standard
  screen only, or soft), and the three numbers as sliders. The zone is dragged
  with the mouse or a finger, a click puts its centre there; with the keyboard,
  the arrows move it by 1 % (10 % with Shift), plus and minus or Page Up and
  Page Down zoom by 10 %, Home puts the centre back, Escape closes. Confirm
  writes the three fields of the form, which are saved with the section; Cancel
  leaves them as they were. The button is off, with its reason, with no image, with
  an image the media library of the CMS does not hold (removed, or without the
  tag of the CMS) or with an image whose size cannot be read; an image kept elsewhere takes its centre
  only (the zoom stays at 100 %). The sentence of the kept part now reads as a
  statement next to the button, not as a field.
- **A rewrite or a translation keeps the size and the framing of a picture.**
  The draft shown to the generator (revision, section written again,
  translation, and the model of a page type) no longer carries the width in
  pixels, the height, the fit, the kept part or the zoom, and a width set in
  pixels reads as the default width: the generator never sees, nor writes,
  anything of them. What an answer replaces gets them back from the section it
  replaces when the component and the picture are the same; another picture
  takes the defaults. A different preset width asked for wins over the size in
  pixels, which stays in the section. The description of the Image section tells
  the generator that the exact size and the kept part are the editor's.
- **The Image section takes proportions for its three widths.** A new setting,
  "Proportions", offers "Original image" (the default: the markup of before, to
  the byte), 16:9, 4:3, 3:2, 1:1 and 3:4 for the narrow, content and screen
  widths. With a ratio, the picture fills a box of that shape whose height
  follows the width (`aspect-ratio`) around the part kept; "Crop the image…" is
  there for every width, and with "Original image" a centre and a zoom can still
  be set (the zone has the proportions of the picture, zoom 100 is the whole of
  it). A size in pixels keeps its width and height and ignores the proportions.
  The setting is the editor's: the generator is not offered it.
- **The pictures of the Columns section take proportions too.** One setting for
  the whole section, "Proportions of the images": "Band (logos)" (the default,
  the markup of before to the byte), "Original image", 16:9, 4:3, 3:2, 1:1 and
  3:4. Outside the band, each picture takes the width of its column, in the
  shape chosen, filling it around the part kept, and the `sizes` announced is
  the one of the column. The part kept (centre and zoom) is set column by
  column, with the same dialog in each row of the list, also in a row added
  without reloading (the media picker of such a row is now bound too).
- **A rewrite keeps the part kept of each picture of a list.** The draft shown
  to the generator leaves out the proportions of the columns and the centre and
  zoom of each row; an answer gets them back row by row, matched by position,
  when the component and the picture of the row are the same, and the defaults
  otherwise.
- **The back-office documentation, the README and `docs/sections.md` describe the
  size and the framing of an image**: the widths, the size in pixels, the fit, the
  dialog and its keys, an image kept elsewhere, and the published pages that keep
  their files until `thelia_cms:render-published` renders them again.

### Changed

- **A component made by the generator is labelled in the default language of the
  shop.** Asked from the back office, made from a brief or prototyped from a
  page, a component takes its label, and the label, help and choice labels of
  its props, in the default language of the shop, whatever the language of the
  request: an editor reads them in the forms of the back office. Its example
  stays in the language of the request. The prompt of the component generator
  changes once (one sentence of rules, the default language in the request,
  the labels of the example in one language).
- **The Image section form reads in the order of the page, with a help written for
  the editor.** The image, its width, its caption, then the text of the image;
  each field explains itself in the words of an editor, apart from the
  instruction the generator reads. The generator's prompt lists the four props in
  the new order, and the description of the Image section gains one sentence (see
  the entry on rewrites above).

### Fixed

- **An SVG icon of the site no longer runs a script when opened at its
  address.** `/site-icon` served the uploaded file with its type alone, so an
  SVG carrying a script ran it with the origin of the shop. The response is now
  sent with `Content-Security-Policy: sandbox`, as the documents of a page are.
- **The picture of a row of a list takes the whole row.** Its card was squeezed
  into a half row, which cut the title and the badge, and the sentence that
  says the kept part sat against the link "Another source: a file or an
  address".
- **A choice drawn as buttons takes the whole line of the section form.** The
  width of the Image section, and the room and the alignment of the appearance,
  shared a line with a neighbouring field and wrapped their buttons on two rows.

## [2.11.0] - 2026-11-04

### Added

- **Remove the AI mark**, on the settings screen of a page the generator wrote:
  once a person has reviewed the text, it takes the mark off the draft and off
  what is online, in the language being edited. The previous drafts keep
  theirs.
- **Show a sentence under a generated page**, in CMS > Settings > Generator,
  and the `cms_ai_label()` Twig function that follows it.
- French names of the AI usages of the module in the AI settings of Thelia
  (shown once Thelia translates the purpose of a usage from its module).

- **`thelia_cms:render-published`**, the published counterpart of
  `thelia_cms:render`: it renders what is online again from the published
  documents (pages and contents of the catalogue), so a change of the module
  reaches pages already published without publishing them again. Only the
  published HTML is written, and only where it changed; the drafts, the kept
  versions, the date of the publication, the activity log and the checks of a
  publication are left alone, and the cache of a changed page is purged as a
  publication does. `--page`, `--all`, `--locale`, `--dry-run`, `--url`. See the
  README, "Rendering what is online again".

### Changed

- The sentence "Content generated by an AI (model) on date." under a generated
  page is off by default. The machine-readable mark is unchanged. A theme that
  overrides `cmspage.html.twig` and calls `ai_provenance_label()` itself keeps
  printing it; `cms_ai_label()` follows the setting.

### Fixed

- **A portrait image is no longer served narrower than announced.** Each
  candidate of its `srcset` asked the image route for a width alone, which it
  fits in a square: the `480w` file of a 1920 by 2715 portrait was 339 pixels
  wide, and looked blurred once stretched. Candidates now ask for the width and
  the height of the image's proportions. Pages already published keep the old
  addresses until they are published again, or rendered again with
  `thelia_cms:render-published`.
- **The editor of a free text no longer offers an image, the HTML source, the
  preview or a h1.** The `html` fields of a section form ask the Tiptap module
  (1.1.0 or later; before, its global toolbar applies) for a reduced toolbar and
  headings from h2 to h4. Whatever the editor, a `<h1>` in a `html` prop is now
  stored as a `<h2>`, attributes kept, when it is typed by hand, generated or
  imported; images already in a text are left alone.
- **A published image is announced at the width it is drawn at.** Its
  `sizes` said the viewport up to 1440px whatever the file, so a browser read
  a 308px pack shot at 1440px: a theme leaving an image at its own size
  (`width: auto`, as the columns of logos are) showed it stretched and
  blurred. Two changes. The default is now the viewport up to the width of the
  file, 1440px at most (the candidate picked does not change). And a section
  says the width of its images itself, with a `data-cms-sizes` attribute the
  publication reads and takes out: the image section (`narrow`, `column`,
  `full`) and the columns (one share of the grid, the gaps out) declare theirs,
  so a 1200px picture in one of three columns is announced at a third of the
  grid, not at 1200px. The images of the columns are drawn in a band of a
  fixed height, as wide as their proportions make them: `data-cms-height`
  says it, and the publication holds the width announced to the one that
  height gives. The generator of site components is taught both attributes
  (when to put them, with an example), so a component it makes declares the
  width of its pictures. The image of a card of the list of pages is served
  with its set of widths and its size, not at full size for every visitor
  (`cms_image_attributes()`, for a theme that draws its cards itself); the
  list is cached, so an update shows in it after the next page published.
  Components of the site, of a module or of the theme can declare it too, see
  docs/sections.md and docs/creating-a-component.md. The filter that runs on
  every page lets the attribute through. Pages published before keep the old
  `sizes` until they are rendered again: after the update, run
  `php Thelia thelia_cms:render-published --all` (see below).

## [2.10.0] - 2026-11-04

### Added

- **The generator proposes the title and the description search engines show.**
  A page written from a brief, and a page translated, are asked for
  `meta_title` (60 characters) and `meta_description` (155), two optional
  properties of the answer, and write them to the page in the language of the
  call only where it holds none yet: an editor's text is never replaced. One
  that is empty or too long is ignored, with no correction turn. Items of the
  catalogue, changes and sections are not asked. See docs/generator.md.
- **A Content-Security-Policy for the pages of the module**, under CMS >
  Settings > Security headers: where pictures and fonts may come from, and that
  only the site may frame a page. Off by default; report-only first, the
  reports written to the log of the shop, then enforced. The pages of the shop
  never get it, and a policy the theme already sends is kept. See the README,
  "Security headers".
- **What a theme does with a section that has an appearance**, written down.
  Such a section renders inside a band of the whole width, which becomes the
  child of the content in place of the component: a width the theme sets on
  the children of the content, or a grid it lays the sections out in, misses
  the section until its rule names `.cms-section-styled > …` too. Found on a
  site where a section given a background spread over the whole screen. See
  docs/sections.md, "What a theme does with the band".
- **A row of the ledger from the start of a call.** A generation (page,
  catalogue item, text or component) writes its row, `running`, before the
  first request, touches it at every turn, and completes it with the outcome.
  A call killed half way, or stopped by an error nobody foresaw, leaves a row
  instead of a gap: `failed` with a sentence for the editor when the error was
  caught, `interrupted` (read back by the history) when the process died and
  the row was not touched for 30 minutes. The screen shows both with their own
  label. See docs/generator.md, "The ledger".
- **What a call needed, on its history line**: `effort`, `corrections`,
  `retries` and `tools` (`{name: count}`) on `cms_page_generation`, shown as
  "2 corrections, 1 retry, read_url ×2, effort high". Needs the update of the
  module to 2.10.0 or later to add the columns.

### Changed

- **A second generation on the same page, in the same language, is refused
  while one runs** (page and catalogue item calls): "A generation is already
  running for this page in this language (started at 10:42 by …)". A call
  that died no longer holds the page after 30 minutes. A check then an insert,
  no lock: two calls a few milliseconds apart can both pass.
- **The generator writes answers up to 48000 tokens**, against 16000: thinking
  and the whole document of a long page count in the limit, and an answer cut
  there was paid and thrown away. Only what is written is billed, but the AI
  layer of Thelia reserves the budget of the whole limit on every call. The
  update raises a stored 16000, which the settings screen wrote on every save;
  any other value is kept. See docs/adr/0008.
- **The receipt of a form no longer repeats the answers.** It carries the
  subject and the message written in the settings of the form, or a short
  default, and nothing of what was posted. Its address is typed by whoever
  sends the form, so a receipt that repeated the answers let anyone make the
  shop write any text, in its own name, to any address. A site with a message
  of its own keeps it, without the answers below. The settings call it a
  receipt rather than a copy.
- **A page that shows a form is never kept by a shared cache.** It is served
  `private, no-store`, with the shared cache of Thelia on as well, so that each
  visitor gets a stamp of their own (see Security). A page of the shop that
  shows a form through the content of the module is treated the same.
- **The rate limit of the forms counts in a table of the module,
  `cms_form_throttle`.** The settings `form_firewall_active`,
  `form_firewall_attempts` and `form_firewall_time_to_wait` still apply; the
  window now opens with the first message and closes after the waiting time,
  rather than moving with every refused attempt. The rows the module kept in
  the core `form_firewall` table are deleted by the update. Needs the update
  of the module to 2.10.0 or later to create the table.
- **Translations of a page run under a usage of their own,
  `theliacms.page_translation`, declared at the standard range.** A
  translation only writes texts again, and went to the advanced range of the
  pages. The usage is on by default, like the others; a site that wants the
  stronger model for translations raises its range in the AI settings of
  Thelia. A budget, a rate limit or a model pinned on
  `theliacms.page_generation` no longer covers translations: set them on the
  new usage. Content translated from now on carries the new usage in its AI
  mark (`data-ai-usage`).

### Removed

- `CmsPageRenderer::THEME_TEMPLATE` and `CmsPageRenderer::MODULE_TEMPLATE`,
  deprecated since 1.2.0 and read nowhere: the template of a page depends on
  its type (`PageTemplateResolver`).

### Fixed

- **A generated page is checked against the site before it is kept.** The
  validator only reads the shape: an image id nobody uploaded, a form that does
  not exist or a guessed `/contact` went through, and a level 1 heading under
  the title of the page was found at publication. A step after the validator
  (`Generation\DocumentChecker`, every kind of request) now looks up the images of
  the media library, the records a reference names, the internal addresses
  (CMS pages online, URLs of the core, routes of the shop such as `/login`, views of the front theme such as `/contact`) and the headings of the rendering. A
  problem goes back to the model within the existing limit of corrections; past
  it the value is emptied (a row or a section that cannot do without it is
  dropped) and the page says it leaves it out, the generation still succeeding.
  A translation, which copies them from its source, is checked on its headings
  only.
- **A section written again is a section, a translation is grafted on its
  source.** Asking the generator to write one section again made it answer
  with the whole document, taken as the new one without checking the others;
  now the answer is one section (the id asked, any component), grafted in the
  document keeping the appearance of the section it replaces (unless the
  answer sets one), and the document goes through the validator again. A
  translation keeps the order, ids and components of its source (a difference
  is sent back for correction) and takes from the answer only the `text`,
  `html` and `json` props, the rows of a list included: images, references,
  addresses, figures, switches and choices are copied from the source.
- **The two reasoning effort fields of CMS > Settings say which models read
  them.** Their help promised a time spent thinking, while only Anthropic
  models read the effort, and think freely at the two highest values only;
  another provider ignores it. The field of the texts had no help at all.
  Setting the effort by range of models is asked of Thelia
  (thelia/thelia#4264).
- **A stream cut half way is asked again once**, as the same conversation,
  instead of failing the call: a provider that drops after the first tokens
  (retryable failure, or a stream that ends without its last event) costs one
  more request, shown on the screen ("The answer was cut half way: the model is
  asked again."), and counts neither as a turn nor as a correction. A failure
  before any event is still not asked again by the module: the core retried it
  already. A second cut fails with the usual message.
- **The cost of a request that failed or was cut is counted.** The row of the
  usage log of the core named by the exception (priced from the tokens
  received) is added to the cost of the call, so the ledger and the spend of
  the site no longer under-count a stream that aborted after it had been paid.
- **An unexpected error no longer breaks the stream of the screen half way.**
  The generators write `failed` and rethrow a `GenerationException` (the
  error stays as the previous one, and is logged with its trace); the runner
  answers with an error event, or a message, for anything else, such as a
  save that fails once the call is `done` (logged only, the row stays `done`).
- **An answer cut below the limit of the generator says where it was cut.**
  The AI layer of Thelia also holds a call under the limit of its usage and of
  its model: the message said "cut at 16000 tokens, raise the limit of the
  generator" when the answer stopped at 8191, where raising it changed nothing.
  It now gives the length written and points at the AI settings of Thelia.
- **The messages of the back-office forms read in the language of the back
  office.** Twenty messages stayed in English on a French screen though their
  translation was there: the brief, a change asked of a document, the language
  to translate from, the name of a snippet, the codes of a menu, a form and a
  field, the answers of a drop-down, the recipients of a form, the target and
  the address of a menu entry, the consent map, the maintenance allowlist and
  the description of an image. The validator looks a constraint message up in
  the `validators` domain and the form theme prints a form error as it is;
  each form now translates its own messages.
- **The reasons a page or a page type is refused read in the language of the
  back office too.** A fixed address or a slug that cannot be used, and a
  field of a page type or a value of a field that is not right, were shown in
  English: their sentence was built with its values before reaching the
  screen, and matched no translation. The sentence now travels with its values
  apart, and the screen translates it; the generator still reads it in
  English.
- **What the document of a page refuses reads in the language of the back
  office.** A section saved by hand with a link that is no address, a colour
  that is no colour or a list too long, and a page or the content of an item
  that can no longer be published, were told why in English. The screen now
  translates each problem, the number and the id of the section included; the
  generator, the logs and the command still read it in English.
- **A shared cache lets a page go on time.** A page was shared for the whole
  TTL even when it, or any page it shows in a menu or a list, was scheduled to
  go online or offline sooner: a page unpublished in five minutes stayed in the
  proxy for the rest of the hour. `s-maxage` now stops at the next scheduled
  date of a page of the site, the TTL of the shared cache of the core included.
- **Nothing is shared while the site is closed for maintenance.** An address
  on the allow list, without a session, was served the real site as `public`:
  a proxy kept it and served it to everybody in place of the closed site.
- **An import empties the kept sitemap.** It stayed as it was before the import
  until it expired.
- **`maintenance:purge --dry-run` no longer deletes the answers of the forms.**
  It reports how many would go, and deletes nothing.

### Security

- **A burst of messages from one sender gets no more through than the rate
  limit.** The count was read, then written: twenty messages posted at the same
  instant by a sender new to the form all read an empty count, and all twenty
  went through where six should. The count is now written in one statement.
- **A stamp carries one message.** The stamp of a form was valid for twelve
  hours and for any number of messages: one display of the page let a script
  post as often as the rate limit allowed, from as many addresses as it had.
  Each display now gets a stamp of its own, spent by the message it brings in;
  a replay is told the message was sent, as a robot is, and nothing is kept.
- **One address receives three receipts a day at most.** A script spread over
  many senders could have the shop write to one address as often as it liked.
  Beyond three, the receipt is skipped without a word; the message is kept and
  the recipients told.
- **The CSS a page keeps is read as CSS, and only what draws is kept.** The
  filter of `style` attributes and of the stylesheets of the components was a
  list of four patterns, which let through `url(javascript:…)`, a document or
  an SVG in a data URL, `url(https:host/…)` without slashes, `image-set("…")`,
  `src()`, `cross-fade()`, `attr()`, `-moz-binding`, any escape (`u\72l(`), and
  an `@import` or a `@font-face` inside a `@media` or a nested rule. It now
  reads the CSS in tokens: a value may call colours, arithmetic, custom
  properties, gradients, transforms, filters, easing, grid and counter
  functions only, `url()` names a path of this site or a raster picture in a
  data URL, no escape and no `<` are kept, and the only at-rules kept are
  `@media`, `@supports`, `@container`, `@layer` and `@keyframes`. A declaration
  that does not pass is dropped whole, the rest is kept as written. What a page
  already published keeps until it is published again; on the test site, none
  of the 38 `style` attributes and 5 component stylesheets stored changes.
- **Asking the generator for a component of the site takes the generator
  right.** The components screen asked only for the settings and the custom
  code rights, so an administrator without `admin.cms.generate` could spend
  the budget of the generator there. The request and change forms are hidden
  without it; keeping, copying and deleting a component, which call no model,
  still take the two rights alone.

## [2.9.0] - 2026-11-03

### Added

- **The document of a product or a category shows on Flexy.** The module prints
  it on the `product.content` hook of the theme, after the description of a
  product and before the products related to it (a Flexy with that hook), and
  on `category.bottom` at the bottom of a category. A document written and
  published in the back office showed nowhere on a theme that never calls
  `cms_item_content()`, and Flexy does not. On by default; a theme that prints
  the document itself turns `item_content_hook` off, or shows it twice. A
  content, a folder and a store have no hook in Flexy: their theme still prints
  their document.

### Changed

- **A list of the pages of a type or of a tag reads their titles and their
  addresses in one go.** A list of recipes asked the database for the
  translation and the address of each page one at a time; the cost no longer
  grows with the number of pages.
- **The checks that a page is online no longer read the page.** The language
  switcher, the hreflang tags, the menus, the home page and the privacy link of
  a form only need to know that the page opens; they no longer read its
  content, its image, its sharing image and its tag to find out.
- **The sitemap is read in a few queries and kept.** It asked the database for
  each page, each of its languages and each address; it now reads them together
  and keeps the answer until a page is published, hidden, renamed or moved, or
  until a scheduled publication date passes.

### Fixed

- **The free text of a section reads as a document on Flexy.** Its headings
  came out the size of body text, its paragraphs touching and its lists without
  a bullet: the reset of the theme undid them, and the stylesheet of the module
  styled only a page written without sections. A richtext section now gets the
  heading scale of the theme, the space between its paragraphs and its bullets.
- **The sitemap lists only pages that can be opened.** A page with a
  publication date but no content to show (what an import can leave) was listed
  and answered "not found" to the search engine that followed it.
- **The sitemap points only to the languages of the site that are active.** A
  page also published in a language since switched off still named it as an
  alternate version of itself.

### Security

- **The previews of a page, an item and a component send no referrer.** They
  answer with `Referrer-Policy: no-referrer`: their address, which carries the
  signature, no longer reaches the sites they load or link to.
- **After a form is sent, the visitor goes back to a path of the site only.** A
  Referer whose path started with two slashes or a backslash took them to
  another site.
- **A component of the site puts at most two loops inside each other.** A
  third one only multiplied a list by itself and could hold a page for
  seconds; the template is refused, the generator is told, and a stored
  component that holds three is no longer drawn.
- **An uploaded image is refused above 10 MiB or 25 million pixels.** In the
  library, on a page and in a section: a small file declaring a huge picture
  was accepted and decoded whole on every thumbnail. An image already stored
  stays as it is.

## [2.8.4] - 2026-11-03

### Fixed

- **The search engine and sharing settings of a page reach its head.** The
  canonical address typed in the tab is the one the page names, in
  `link rel="canonical"` and `og:url` (with SEOne). The tab gets a social
  image, picked in the media library or sent from the disk; a share shows it,
  else the image of the page, else the logo of the theme. The social title,
  the social description and the Twitter card are handed to the theme in the
  `og_title`, `og_description` and `twitter_card` blocks: they show once
  Flexy passes these blocks to the head and SEOne prints them; until then the
  title, the meta description and the card of the theme stay. A card other
  than `summary` or `summary_large_image` left in a row is ignored.
- **The image of a share is a full address.** `og:image` and `twitter:image`
  named the image of the page by its path, which a network fetching it from
  elsewhere cannot follow.
- The help under the image of a page is translated.

## [2.8.3] - 2026-11-03

### Changed

- **Every branch is checked, by static analysis and code style too.** The CI
  runs on a push to any branch, and on a pull request from a fork. Beside the
  tests, it validates `composer.json`, checks the code style against the rules
  of the core (`.php-cs-fixer.dist.php`), audits the dependencies the module
  would install, and runs PHPStan at level 5 (`phpstan.dist.neon`, with a
  baseline of the errors already there). The unit and integration suites keep
  their PHPUnit caches apart. How to run them: `docs/developing.md`.
- **The archive of a release holds what runs, and the CI tries the oldest
  Thelia accepted.** Tests, contributor documentation and the configurations
  of the checks are left out of the archive Composer installs
  (`.gitattributes`). The tests run once more on Thelia 3.3.0, the floor of
  `thelia/core ^3.3`. `composer test`, `stan` and `cs` run the checks from the
  module directory. The release workflow is pinned to a commit of the shared
  one, and the changelog has a section per tagged version.

## [2.8.2] - 2026-11-03

### Fixed

- The preview of the content screen keeps its place when a section is changed, moved, duplicated or removed by hand: the section stays where it stood instead of the preview going back to the top of the page.
- The button that opens a component of the site, on the components screen, reads "Modifier" in French instead of "Ouvert", the word the forms screen uses for a form that takes answers.

### Changed

- **The documentation of the back office covers the whole module.** It opens
  with the first steps from an empty page to a page in a menu, and ends with
  what to do when something does not work and a glossary. It now explains the
  duplication of a section, the copy, keeping and deletion of a component of
  the site, menus of their own per language, writing a language from another,
  unpublishing, the colors of a tag and the search of a showcase site, with a
  screenshot of four screens in French and in English, and a diagram of what
  happens between "Write the draft" and the draft on show: the module, the AI
  layer of Thelia, the provider, the tools and the corrections. "Help on this
  screen" opens the part on menus, forms, media or the bin rather than the
  section they share, and the content screen has the link too, at the top of
  its panel.

## [2.8.1] - 2026-11-02

### Fixed

- The button to the toolkit on the components screen also shows where the theme opens its toolkit with `THEME_TOOLKIT`, as the toolkit itself does, and no longer only in debug.

## [2.8.0] - 2026-11-02

### Added

- **A section is duplicated by hand.** On the content screen of a page or of
  a catalogue item, the button next to the arrows of a section puts a copy of
  it right after it in the draft: same component, props and appearance, under
  an id of its own. Only the draft of the language edited changes; it takes
  the right to change pages, like moving a section. A section of a component
  the site put away is not copied, as it would not be added.
- **A component of the site is copied into a new one.** On the screen of a
  component, "Copy into a new component" takes a code and a label and copies
  the template, stylesheet, props, sample and mark of provenance, without a
  call to the generator. The copy is a prototype: it is changed by "Ask a
  change", then kept for the whole site. It takes the right to make a
  component.

## [2.7.1] - 2026-11-02

### Fixed

- The color fields of a tag no longer read "optional": the color inputs always hold a color, the box says whether the tag has one, and its help sits under it.

## [2.7.0] - 2026-11-02

### Upgrading to 2.7.0

- The color of a tag is free. `cms_tag.color` holds the `#rrggbb` code of the
  background, and the update adds `cms_tag.text_color`, the code of the text,
  NULL to leave it to the contrast (`Config/update/2.7.0.sql`). A tag colored
  with a name of the former palette takes the codes of the badge it gave.
  Remove `var/propel/<env>` so the models are generated again.
- `tagColor`, on `PublishedPage`, `PageCard` and the result of
  `cms_pages_tagged()`, is now the code of the background instead of a name of
  the palette, and `tagTextColor` gives the code of the text. A theme that
  mapped the six names to its own design prints the codes instead, for
  example `style="background-color: {{ cms_page.tagColor }}; color: {{
  cms_page.tagTextColor }}"`.
- `PageTagColor` is a value object, no longer an enum: `PageTagColor::of('#dc4a7b', '#ffffff')`
  in place of `PageTagColor::Cyan`, `->background` in place of `->value`.

### Changed

- **A tag takes any color.** The tag screen gives a color picker for the
  background, and a second one for the text, or black or white chosen by the
  contrast. A site file keeps both, and a file written before still imports
  its names of the palette.

## [2.6.3] - 2026-10-30

### Fixed

- **A row field spelled in camelCase keeps its value.** A theme writes
  `imageAlt` or `buttonLabel` in a row; the form stored them as `image_alt`,
  `button_label` and dropped the values already written under the template's
  spelling, and the component read nothing back. A row is now read under
  either spelling, stored in snake case, and handed to the component under the
  names its template uses.
- **The rows of a list stay compact in the section form.** The fields of a row
  share a line (a name, a quantity and a picture), their labels show on the
  first row only, a row of one value takes the line, and a row holding a text
  of paragraphs folds under its first words. A form with a list opens wider.

## [2.6.2] - 2026-10-30

### Added

- **A list a theme component documents is drawn as rows of fields.**
  `{# @prop ingredients list<{name: string, quantity: string, image:
  image|null}> … #}` (or `array<{…}>`) gives the editor one row per item, to
  add, remove and fill, with the picker of the media library for an `image`
  field, in place of a JSON text area; `|null` makes a field optional. `list<string>`,
  `list<html>`, `string[]` and `html[]` give rows of one field and are still
  stored, and handed to the component, as a list of strings. Up to 50 rows.
  A row that holds a list or a record (`product`…) leaves the prop a JSON
  one, as `array`, `list`, `object` and `iterable` do. The generator is told
  such a list is an array of strings.
- **The picture of a row is drawn from its address**, as a picture prop of its
  own is, and an example of the theme written as a path of the theme
  (`images/…`) inside a row resolves the same way.
- **An image prop accepts the address the media library serves a picture
  from** (`/image-library/17/full/max/0/default.webp`) and stores the id of the
  picture.

## [2.6.1] - 2026-10-30

### Added

- **A theme shows pictures in its examples.** A picture prop of an entry of
  `sections.examples` may be the path of a file of the theme, as `asset()`
  takes it (`images/illustrations/banner.webp`). The toolkit draws it from the
  address the theme serves it from; the generator and a section added by hand
  never see it, `sample()` keeps the prop empty, and the format of the
  documents does not change.

### Fixed

- **The svg icons of the theme components survive the publication filter.**
  A page filter took out every `<svg>`: the arrow of a button, the arrows of a
  carousel, the chevrons of an accordion came out empty on a published page.
  Shapes (`svg`, `g`, `path`, `circle`, `ellipse`, `rect`, `line`, `polyline`,
  `polygon`) and the attributes that place and colour them are kept; `use`,
  `image`, `foreignObject`, `a`, `style`, `animate*`, event handlers, `href`
  and a `url(…)` in `fill` or `stroke` are not. The markup of a page is stored
  when it is saved or published, so a page gets its icons back when it is
  rendered and published again (`thelia_cms:render --all`, then publish).

## [2.6.0] - 2026-10-30

### Upgrading to 2.6.0

- The update adds `cms_page_document` and `cms_page_document_i18n`, and
  `cms_menu.menu_locale` (empty on every existing menu, which
  stays the shared menu of its code) and replaces the unique index
  `unq_cms_menu_code` by `unq_cms_menu_code_locale` on (`code`,
  `menu_locale`) (`Config/update/2.6.0.sql`). Update the module before serving
  pages, and remove `var/propel/<env>` so the models are generated again.
- `CmsMenuQuery::create()->findOneByCode()` may now answer with the menu of a
  language: code that reads a menu by its code goes through
  `CmsMenuQuery::findShared()` or `findServed()`. `MenuCache::storedEntryCount()`
  and `CmsMenuProvider::hasEntries()` take the locale.
- Going back to 2.5 after the update, which deletes the documents of the
  pages (their files stay under `local/media/documents/cms-page/`) and the
  menus of the languages:

  ```sql
  DROP TABLE IF EXISTS `cms_page_document_i18n`, `cms_page_document`;
  DELETE FROM `cms_menu` WHERE `menu_locale` <> '';
  ALTER TABLE `cms_menu` ADD UNIQUE INDEX `unq_cms_menu_code` (`code`),
      DROP INDEX `unq_cms_menu_code_locale`, DROP COLUMN `menu_locale`;
  ```

### Added

- **`cms_page_documents_block(page, locale)` draws the documents of a page.**
  It answers the markup of the list (heading, title, type and size of each
  file, the browser opening a pdf or a txt and saving the rest), or an empty
  string when the page has no document. A theme overrides the markup by
  shipping `cms-page-documents.html.twig` at its root; the template receives
  the one variable `cms_page_documents_block` (`documents`, `page`,
  `heading`), in the language of the page when a page is given. The page templates of the module
  call it after the content of the page.
- **A page offers files to download.** The **Documents** tab of the edit
  screen of a page takes several files per page (pdf, doc, docx, xls, xlsx,
  ppt, pptx, odt, zip, txt; the extension and the detected type must agree,
  html, svg and php are refused), up to what PHP allows (`upload_max_filesize`,
  `post_max_size`, `max_file_uploads`). Adding, describing, ordering and
  deleting are saved at once, draft or not; the tab waits until the page is
  saved. The Pages right is enough (UPDATE to add, describe and order, DELETE
  to remove), not the Media right. The file is shared by every language; the
  title and the description are per language, and a document without a title
  in a language shows its original name without the extension. There is no
  fallback to another language. Files are stored under
  `local/media/documents/cms-page/`, to back up with the media.
- **`GET /cms/documents/{id}/{file}` serves them** (route `cms.page_document`),
  also for a page offline or never published. It answers 404 for a name that
  does not match the pattern, an unknown row, an id and a name that disagree, a
  page in the bin or a missing file. The type comes from the extension, with
  `nosniff` and `Content-Security-Policy: sandbox`; pdf and txt open in the
  browser, the rest downloads under its original name. `Cache-Control: public,
  max-age=300, must-revalidate`, with `ETag` and `Last-Modified`: a deleted
  document can stay five minutes in a cache.
- **`cms_page_documents(page, locale)` lists them in a template.** `page` is
  a `PublishedPage` or an id. It is read when the page is served, so no new
  publication is needed, and returns data only: `id`, `title`,
  `description`, `url`, `extension`, `size` (bytes) and `originalName`. An
  unknown page or one in the bin gives an empty list. It declares the cache
  tag of the page.
- **Documents follow their page.** Duplicating a page or creating one from a
  model copies the documents and their files. Emptying the bin and deleting a
  page type (and so its model page) remove the files once the database change
  is committed; a file that cannot be removed is logged, without an error.
  The export and the import of the site do not carry documents.
- **The update to 2.6.0 adds `cms_page_document` and
  `cms_page_document_i18n`** (`Config/update/2.6.0.sql`).
- **A language may have a menu of its own.** Each code keeps a shared menu,
  read by every language without a menu of its own; detaching a language
  copies the shared menu (titles, entries, labels in every language) into a
  menu of that language under the same code, which `cms_menu(code)` then
  serves in that language. Realigning replaces the entries of a menu by a copy
  of another menu of the code (a language on the shared menu, on another
  language, or the shared menu on a language), never merged, titles kept.
  Back to shared deletes the menu of the language. An emptied detached menu
  stays empty in its language rather than show the shared one. Renaming or
  deleting the shared menu renames or deletes the menus of its languages. The
  services are `CmsMenuWriter::detach()`, `realign()`, `reattach()`.
- **The menu screen edits the menu of the language being edited.** It says
  whether that language follows the shared menu or has a menu of its own, and
  offers to give it one, to realign a menu on another one and to send the
  language back to the shared menu; realigning and going back show how many
  entries are replaced or deleted and wait for a confirmation. The list shows
  each code once, with a badge per language that has a menu of its own. New
  routes: `admin.cms.menus.detach` (POST), `realign` and `reattach` (GET for
  the confirmation, POST to do it); `{id}` is the row being edited.
- **The export carries the language of a menu** (`locale`, empty for the
  shared one) and the import matches menus on the code and that key; a file
  without it holds shared menus. The format is version 3: a module of an
  earlier version refuses the file rather than write the menu of a language
  over the shared one.

### Fixed

- **`ToolkitLinkTest` is skipped when the theme has no toolkit**, instead of
  failing on section stories that cannot load.

## [2.5.0] - 2026-10-30

### Added

- **A tag may have a color**, picked on its screen under **CMS > Tags** among
  six (`red`, `orange`, `yellow`, `green`, `cyan`, `dark`) or none. The list
  of tags and the list of pages show each tag in its color; a tag without one
  stays grey. A theme reads the name of the color as `tagColor`, beside `tag`,
  on the cards of `cms_pages_tagged()`, on `cms_page` and on the page handed
  to the `cmspage.*` hooks. The export carries it per page (`tag_color`); an
  import gives it to the tags it creates, and drops a name the palette does
  not have with a warning. The update to 2.5.0 adds `cms_tag.color`, empty
  for the tags a site has already.

## [2.4.0] - 2026-10-29

The first tag of the 2.x line. It ships 2.0.0 to 2.3.1, never tagged on their own: their entries carry their version in the heading.

### Added

- **A component is prototyped from the content screen of a page.** **Add a
  section > A new component** asks the generator of components in a few
  words; the answer is drawn in the preview in place of the draft, changed by
  asking again, discarded or kept. Kept, it is a prototype of the site:
  status `draft` in `cms_component`, shown and edited on the pages that hold
  it, offered to nobody else until **CMS > Components** keeps it for the
  whole site (**Keep for the whole site**). The same rights as **CMS >
  Components > New component** are needed.
- **A brief may make a component of the site**, when the new generator
  setting "Let a brief make a component of the site" is on (off by default)
  and the editor may make components: the generator writing a page calls the
  generator of components (`make_component`, twice per page at most, each
  call a row of its own in the ledger) when none of the catalogue shows what
  the brief asks, and names the prototype in the page.
- **The dialogs of the content screen are drawers** over the left panel
  (edit a section, rewrite, brief, add a section), the preview staying in
  sight; full width on a phone. The form of a section puts two fields on a
  line once the drawer is wide enough, a long text, a picture, a list of
  rows or a folded part on a line of its own; the help of a field is a
  tooltip on a "?" beside its label, so the labels and the fields of a line
  are level whatever the wording.
- **An export carries the status of every component of the site** (`draft`
  or `ready`), and an import keeps it: a prototype stays a prototype on the
  site it is imported into.
- **A component of the site may move.** Its stylesheet may hold `@keyframes`,
  named after the component when it is served (`cms-site--<code>--<name>`),
  as is every `animation` of the stylesheet that uses it, so two components
  may both call an animation `pan`. A rule that sets an animation sits in
  `@media (prefers-reduced-motion: no-preference)`, or the component is
  refused, and so is an animation set in a `style` attribute of the template.
- **A skill on motion** (`skills/motion/SKILL.md`, the eighth shipped): how
  a component moves or reacts with its stylesheet alone (a picture that
  scrolls, folds, cards that slide, hover effects) and what it leaves to a
  component of the theme. The generator of components reads it beside the
  skill on accessibility, which now says that what moves on its own can be
  paused and that nothing moves for a visitor who asks for less motion. A
  test holds the recipe of the skill to the validator, and the elements the
  prompt names as taken out to the filter of the page.
- **Generated content is marked** (article 50(2) of the AI Act). A page or the
  document of a catalogue item written by the generator keeps the mark of the
  core with its draft, its publication and its revisions, and through the
  export and the import of the site. The public page prints it as
  `data-ai-*` attributes on the content and as a sentence after it, in the
  language of the page; `cms_item_content()` wraps a generated document the
  same way. A text written beside a field carries no public mark: it stays in
  the AI log of Thelia, which records the usage, the model and the cost of the
  call.
- The history of a page points at the row of the usage log of the core that
  holds the cost of the call (`cms_page_generation.log_id`), and the requests of
  one call share a correlation id there.
- The prompts the ledger keeps are emptied by `maintenance:purge` when the core
  empties its own, so the module adds no retention of its own.
- When the AI layer refuses a call (budget spent, rate limit, jurisdiction,
  usage turned off, key unreadable, provider down, safety check), the editor
  reads one sentence, in French or English, that says what to change.

### Fixed

- **The generator of components leaves out a skill the site switched off**
  under CMS > Skills, as the generator of pages and `read_skill` do: it read
  the skill on accessibility whether it was on or not. Its heading is no
  longer written twice in the prompt.
- **A component is refused when it draws what a page does not keep.** The
  template was checked against a short list (`script`, `iframe`, `form`…)
  while the page filters against the elements of HTML text and layout: an
  `svg`, an `input`, a `data-` attribute passed, then went out without a word
  when the component was drawn. A component asked to make a picture scroll
  was kept with an animated `svg` and showed a still picture. The validator
  now runs the markup of every branch of the template through the filter of
  the page and names what it takes out, so the model draws it another way in
  the same call.
- **A document whose props carry a bare line break is read.** A model that
  escapes the JSON string of the props once rather than twice (seen with
  Mistral Large) left a line break of a text as a control character inside
  the inner JSON, and every section came back "not valid JSON" until the
  call failed. Such a break is read as a space; a props string that is no
  JSON at all is still sent back, with the rule to follow, and the refused
  answer now travels with the log entry.
- **A PDF a brief cites is read again.** The text of the PDF is extracted on the
  server (`smalot/pdfparser`) and goes the way the text of a page does; a PDF
  that is damaged, protected or longer than the ceiling is reported as not read.
- **The history of a generation says in words why a cited web page was not
  read**, in French or English, instead of showing the code (`url_not_accessible`).
- **The update to 2.0.0 keeps `cms_legacy_page_content` and
  `cms_legacy_page_revision` only where the pages or revisions held something
  written**, so a fresh install ends with no legacy table.
- **The update from 1.x to 2.0.0 no longer destroys what the former editor
  wrote.** It dropped the tables of the blocks and of the saved page templates
  and cleared the HTML, the editor project and the stylesheets of every page
  and revision, with no copy. The tables of the blocks and of the saved page
  templates are now renamed `cms_legacy_block`, `cms_legacy_block_i18n`,
  `cms_legacy_block_content`, `cms_legacy_item_block` and
  `cms_legacy_page_template`, rows and keys included (a table with no row is
  dropped, so a fresh install has none), and the content of the
  pages and revisions (HTML, editor project, stylesheets) is copied to
  `cms_legacy_page_content` and `cms_legacy_page_revision` before it is
  cleared. 2.x never reads those tables: they are there to get the old markup
  back by hand, and can be dropped once it is no longer needed. The update can
  be played twice. A site that already went through the former 2.0.0 update
  lost that content: restore it from an export or a backup taken before.
- **A component of the theme keeps its Stimulus controller once published.**
  The filter a page goes through before it goes online dropped
  `data-controller`, `data-action` and the targets of a component: a carousel
  went online with arrows that did nothing. A theme now names the controllers
  its components use under `sections.controllers` of `config/theliacms.yaml`;
  their attributes are kept, and a controller it does not name is dropped from
  the markup. `hidden` and `aria-roledescription` are kept too.
- **The `label` column of `cms_item_revision` reaches every site.** It was
  shipped as the update to 2.3.1, a version the module already carried: a
  site installed at 2.3.1 before that column existed never ran the update
  and then queried a column it did not have. The update moves to 2.3.2 and
  runs as a no-op where the column is already there.
- **A publication no longer empties the shared cache of the whole site.**
  Every change to a page purged `cms` and `cms-menu`, which every page of the
  site carries, the shop included when the shared cache of Thelia is on. A
  change now drops the page, the lists of pages, the pages under it when its
  title, its address or whether it is online changed, and the menus only when
  one of them links to it.
- **Hiding, binning or restoring a page, or changing the home page, reaches
  the caches.** Hiding a page left it in the menus for up to an hour and in a
  shared cache until its lifetime ran out; binning a page left the other pages
  linking to it; restoring a branch dropped its first page only; a new home
  page left the root of the site on the former one. Moving a page among its
  siblings now drops the lists of pages that follow the order of the tree.
- **A page showing a list of pages or a form is dropped when they change.**
  The page is filed under a `cms-partial-<name>` tag for each dynamic block it
  shows, read from its content or from its template, and the block purges it.
- **A content, a folder, a category or a product a menu links to reaches the
  shared cache.** Their events dropped the menus kept by the module, not the
  pages a shared cache kept with the menu in them. They now drop `cms-menu`
  when a menu links to the row they wrote, and only then: a catalogue import
  writes thousands of products no menu names.
- **An import drops the whole site from the caches.** It changed pages,
  menus, forms and settings, and dropped only the menus kept by the module.
- **Renaming or deleting a tag drops the lists of pages**, which show the tag
  of each page and list the pages of a tag.

### Upgrading to 2.4.0

- The module requires Thelia 3.3.0, whose AI layer the generator goes through.
- Set up the generator in the AI settings of Thelia: a connection to a
  provider, then a range of models, a budget and a turn-on for each of the
  three usages `theliacms.page_generation`, `theliacms.text` and
  `theliacms.component`. Until then the generator says it is unavailable and
  calls nothing.
- `ANTHROPIC_API_KEY` is no longer read by the module. The key lives in the
  connection of the AI settings.
- The update to 2.4.0 adds `cms_page_generation.log_id` and the columns that
  hold the mark of a generated content (`draft_provenance` and
  `published_provenance` on `cms_page_content` and `cms_item_content`,
  `provenance` on the revisions, `cms_component` and `cms_page_generation`).
- A theme that overrides `cmspage.html.twig` calls
  `ai_provenance_attributes(cms_page.provenance)` on the element that holds the
  content, and prints `ai_provenance_label(cms_page.provenance, cms_page.locale)`
  after it, or its pages lose the mark.

### Changed

- The generator, the texts written beside a field and the components made in the
  back office go through the AI gateway of Thelia: the provider, the key, the
  model and the budget are set in its AI settings, the Generator card of
  CMS > Settings keeps the effort, the length of an answer and the reading of
  web pages. `effort` is passed to the model as its reasoning effort and has no
  effect on a provider that does not read it.
- **Reading the web pages a brief cites is off until the site turns it on.**
  The page is fetched by the server of the shop, through the client of the core
  that refuses private addresses, and its text is sent to the provider of the
  range. The tool of the Claude API that read the pages from the provider side
  is gone with the SDK.
- The messages of a conversation that never answers (too many turns) are
  translated.

### Removed

- The Claude API SDK (`anthropic-ai/sdk`), `nyholm/psr7`, the price list and
  `ClaudeClientProviderInterface`.
- The settings `generator_model` and `generator_text_model`, and the model
  fields of the Generator card: the range of the usage chooses the model. A file
  written before 2.4.0 that carries them still imports, the keys are ignored.

### Security (2.3.1)

- **An import filters what it writes like a publication does.** The draft
  and the published markup of the pages and of the catalogue items were
  stored as the file gave them: an export edited to hold a `<script>`, run
  through `thelia_cms:import`, went online, past the custom code right. They
  now go through the sanitizer of a publication, iframes included unless the
  new `--allow-custom-code` option is given. Their documents are checked by
  the validator of the content screen, and one it refuses is left out. A form
  of the file is left out when its code is not one the form screen accepts,
  loses the recipients that are not email addresses, and gets a retention the
  screen accepts: a negative one kept the answers forever. The report names
  each of them. Tests import a hostile file and check what was stored.
- **A `style` attribute is filtered like a stylesheet.** The published
  content and every drawing of a component made for the site kept `style`
  attributes as they were: a component whose template prints a text prop in
  one, or a markup prop holding one, could load a picture from another host
  (a tracker that bypasses the consent of the visitor), which a stylesheet
  may not. What `css()` takes out of a stylesheet, a `url()` to another
  host, `expression()` and `behavior`, is now taken out of every `style`
  attribute as well. Tests cover what a template of a component can put
  together when it runs: tags, event handlers and schemes named at run time
  are all taken out of what it draws.

### Added (2.3.1)

- **A store of the Dealer module carries a document.** Up to 1.6.0 a store
  could carry a content block, which the update to 2.0.0 drops with the item
  blocks. While Dealer is active, its store screen gets the *CMS content* tab
  of the other catalogue items, the generator writes for the page of a store,
  a deleted store takes its documents with it, and an export names a store by
  the slug of its SEO record. The theme prints the document with
  `cms_item_content('dealer', id)`. Dealer stays optional: its classes are
  reached by name.

- **Several images of the media list are answered at once.** A box on each
  card and one for the page select images; a tag is given or taken off,
  they are marked decorative or not, or the unused ones are deleted, asked
  in a dialog first: an image a page or an item still shows is kept and
  named.
- **The image of a section is chosen in a picker of the media library.**
  The section shows the image it holds (thumbnail, name, whether it is
  described) with "Choose in the library" and "Remove"; the picker opens over
  the section: a grid of thumbnails, searched, filtered by what is still to
  describe or by a tag, by pages, and an image can be sent from it. It
  replaces a list of every title, which could not be read past a few dozen
  and lost an image older than the 200 newest. The image of a page, on its
  settings, is picked the same way instead of a grid of radio cards. An id
  sent for an image of a section or of a page is kept only when the CMS owns
  that image.
- **A choice with pictograms is chosen on buttons.** A choice prop may give a
  pictogram per value (`Prop::choice(..., icons: [...])`): the width of the
  Image section shows its three widths as shapes, and the room above and
  below and the alignment of a section are buttons too.
- **The sheet of an image says where its description stands in every
  language**, each language a link to the sheet in it. Tags are chips
  suggested from the tags of the library, written as the library has them; a
  file chosen to replace the image is shown, with its size, before the sheet
  is saved; deleting is asked in a dialog of the screen instead of the
  browser's confirm(). The usage of an image says "pages or items": it
  counts the catalogue items showing it too.
- **Images are dropped on the media list and sent one by one.** The upload
  form of the list takes files dropped on it; with JavaScript each file is
  sent on its own, with its progress, then "added" or why it was refused
  (format and size checked before sending, then by the server). Once every
  file is answered, the describe queue opens on the images just added; when
  one was refused, the screen stays and offers the queue. Without
  JavaScript, several files sent at once lead to the same queue.
- **The images still to describe can be described one by one.** "Describe
  them one by one", above the media list, shows the first image of the list
  that says nothing in the language edited, large, with its usage and one
  field: Enter saves its alternative text and shows the next one, "It is
  decorative" is an answer of its own, "Skip" leaves it for later. The queue
  follows the search, the tag and the order of the list it was opened from,
  and goes back to it once everything is described. The cards of the list are
  denser (six a row on a wide screen), with the size and weight on hover, and
  an image opened from a filtered list leads back to that list, from its
  sheet and once deleted.
- **The media list is paged, filtered and ordered.** 48 images a page, the
  last page when the address asks for one past it. It shows what is still to
  describe in the language edited, what no page and no item uses, the
  decorative images, or one tag (offered with its count); newest, oldest, by
  title or heaviest first. The list is an address: a filtered list can be
  bookmarked and shared. The search reads the alternative text and the
  caption as well as the title and the tags, and so does `search_media` for
  the generator. It replaces the list of the 200 newest images, past which an
  image could only be found by searching.
- **The images of the catalogue can be copied into the media library.**
  `thelia_cms:media:import-catalogue` copies the images of products,
  categories, brands, folders and contents with their titles, alternative
  texts and decorative flag in every language, tagged with their kind of item
  and, for a product, its default category. Run again, it copies only the
  files the library does not hold yet; `--type` narrows it, `--dry-run`
  counts.
- **The draft a generation replaces can be put back.** Before a brief, a
  change, a section rewrite or a translation writes the draft of a page or of
  a catalogue item, the draft as it was is kept as a revision labelled
  "draft" (the last five, apart from the twenty publications). The content
  screen lists them under *Previous drafts*, with the date and the number of
  sections; *Put it back* makes one the draft again and keeps the one it
  replaces in its place, so a restore is undone the same way. The update to
  2.3.2 gives `cms_item_revision` the `label` column `cms_page_revision` had.

- **Free text is edited where it stands.** A double click on a section of
  free text in the preview mounts the editor of the back office on it, in
  the page, with its toolbar; *Save* (or Ctrl+Enter) posts the section the
  way its form does, appearance included, and *Cancel* (or Escape) puts the
  text back. Without the Tiptap module the text is edited as it is, with no
  toolbar. The other components keep their form.

- **The edit screen of a page proposes its address.** Under the *URL slug*
  field, the whole address the page would answer on, under its parent pages
  and in the language being edited, follows what is typed in the title, the
  slug and the parent. *Regenerate from the title* fills the slug from the
  title. The screen says when the slug no longer follows the title, after a
  rename, and when a number was added because another page or another
  language already answers on the address. Nothing is written until the form
  is saved: a published address moves only on purpose. The *Fixed address*
  field says, as it is typed, whether the address is free. Both are answered
  by `POST /admin/cms/pages/address-proposal` and
  `POST /admin/cms/pages/fixed-address-check`, which write nothing and take the
  token of the back office like any write of the module.

- **A rich-text field is written from a brief.** Beside the summary and the
  detailed description of a page, and beside the summary, the description
  and the conclusion of a product, a category, a brand, a folder and a
  content, *Write from a brief* asks the generator for the text of that field.
  It reads the brief, the title, what the field holds, and may search the
  products, categories and pages of the site. The text comes back as
  formatted text only (paragraphs, emphasis, lists, links, subheadings,
  quotes), is shown with its cost, then replaces the text of the field or is
  inserted at the cursor. Nothing is saved before the form is. The button
  takes the right to use the generator; each call is in the ledger of the
  generations, kind `text`, and in the history of the content screen of a
  page. CMS > Settings gives these texts a model, an effort and a length of
  their own (by default the model of the pages, effort `low`, 2,000 tokens).
  The button is drawn by a hook on `wysiwyg.js` and reaches the editor of the
  Tiptap module through the element it draws: an update of that module that
  stops leaving its instance there leaves the field without the text, and
  says so.
  A text is written to the end even when the connection is cut before the
  answer arrives: the server keeps it ten minutes under an id the screen
  gave the call, and the screen asks for it again by that id
  (`POST /admin/cms/text/outcome`, for the administrator who asked only)
  rather than having it written and billed a second time.

### Changed (2.3.1)

- **An image of the media library is drawn with the text it has in the
  library.** The Image and Columns sections published the text they held
  for the image, whatever the library said: an image described once in the
  library was published without it, or with another text. An image of the
  library is now drawn with its text in the language of the page, empty when
  it is decorative; the text of the section answers for a picture kept
  elsewhere, and for an image not described yet. In the form, the field of
  the text shows only for a picture kept elsewhere; for an image of the
  library, its text in the library is shown, or a link to describe it.
- **The appearance of a section is laid out as one.** The two colours stand
  side by side with their help under their field, a colour not set shows a
  crossed swatch instead of black, and the room and the alignment are each a
  row of buttons of one width.
- **The props of the components of the theme read in the language of the
  back office.** Their labels and help come from the comments of the theme,
  in English and sometimes in the words of a developer. The form of a section
  passes them through the translator, and the module ships the French and a
  plainer English for the components of Flexy it offers. The sheet the
  generator reads keeps the words of the theme: the prompt does not change.
  A theme of its own gets the same by adding the keys to `I18n/`.

- **The components to add a section from read better.** The dialog groups
  them by what they are for (text and media, shop, reviews and reassurance,
  content of the site, blocks of the site), gives each an icon, a name and
  one line in the language of the back office, and no longer shows the name
  a document refers to them by (it stays in the tooltip). The components of
  the theme the module does not know keep their own label and description,
  under the icon of their family. What the generator reads of a component
  does not change.
- **The form of a section reads better.** Its title names the section the
  way the list does, with no component name. A prop of the theme with no
  label of its own reads as words (`max_note` is "Highest score",
  `product_ids` "Products"). The file and the address that can replace a
  picture of the library fold under one line, the appearance of the section
  under its heading; both open by themselves when they hold a value. The
  rows of a list are numbered, number fields are narrow, "(optional)" is no
  longer written on every field, and a product or a category is picked by
  its name, the number field behind the picker showing again only when the
  list cannot be read. The title, the close button and the save button stay
  in view while the form scrolls, and Escape closes it, from the rich-text
  editor too.
- **Ask for a change comes before the sections of the draft** on the content
  screen, right under the brief: the change of the whole page is offered
  before the list that changes one section at a time.
- **The generator gives the same page for the same brief more often.** Two
  rules of the shipped skills, measured on the determinism corpus
  (`docs/evaluation/determinism/results.md`): every section takes its id from
  a fixed list (`intro`, `features`, `offer`, `practical`, `contact`,
  `form`…), and a page carries a form, a contact text or a call to action
  only when the brief asks for one. The same structure in four runs out of
  five went from 7 briefs of 16 to 13, the quality of the evaluation
  unchanged. A site that edited these skills keeps its own text.
- **The skills say which component to take when two fit.** A testimonial
  with no score goes in free text, since the review components draw a score
  and an empty one reads as zero; points of the same kind side by side go in
  columns when there are two to four short ones, in free text otherwise.
- **The content screen names the template of the page.** Next to the status
  of a page of a template, the bar shows the template and links to its
  settings.
- **Writing from a brief says it replaces the draft.** When the draft holds
  sections, a warning above the brief counts them and says nothing of them
  is kept, the button reads *Replace the draft*, and the screen asks once
  more before sending.
- **The preview of a CMS page shows the whole page or the content only**, as
  it did for the catalogue: the content only draws the sections in the layout
  of the theme, without the header, the footer, the title or the fields of
  the page. Its signed link is the one of the whole page with `?content=1`;
  a theme draws it its own way with `cms-page-content-preview.html.twig`.
  The screen of a component made for the site, and of a candidate, offers
  the same choice for its preview; both screens share the choice.
- **A section is deleted from the preview.** Hovering a section shows
  *Delete* next to *Edit*; it asks for the same confirmation as the bin of
  the list.

### Fixed (2.3.1)

- **A draft the validator refuses no longer goes online.** A publication
  rendered the draft as it was stored, without the check the editor and
  `thelia_cms:render` run. A draft written outside the editor, by a script or
  an import, holding props the catalogue refuses (`"product_ids": [27, 33]`
  instead of a list of `{"id": 27}`) was announced by
  `thelia_cms:publish --dry-run` and put online by a real run. It is now
  validated before it is rendered, and what is rendered is the validated
  document. The command reports the page under "Refused", and the editor
  buttons name the problems. The CMS content of a product, a category, a
  content or a folder is validated the same way before it goes online.
- **A long page goes online whole.** The published content went through the
  sanitizer of Symfony with its default limit of 20 000 bytes, and whatever
  came after was dropped without a warning: terms of sale, a long FAQ, or a
  recipe whose components are drawn with their classes went online cut in
  the middle of a sentence. The limit is now 1 000 000 bytes
  (`PublishedContentSanitizer::DEFAULT_MAX_LENGTH`), and a shop can pass its
  own length to the service (`$maxLength`). The text the generator writes for
  a field had the same limit and reads the same length now.
- **The media list refuses a file on its own, and only the formats it
  says.** A file the library could not decode stopped the whole sending with
  an error, after storing the files before it; and a GIF or a BMP was stored
  although the screen accepts JPEG, PNG and WebP only. Each refused file is
  now named with why, and the others are stored.
- **An image with no row in the language edited is counted as still to
  describe.** It showed the "no alternative text" badge and was left out of
  the count above the list: an image uploaded in one language was never
  counted in the others.
- **The number that keeps an address unique stays out of the slug.** A page
  titled alike in two languages answers on `cookies` in one and `cookies-2` in
  the other, and the `-2` was stored as the slug of the second: shown in the
  field, and brought back by every rebuild of the addresses. The slug keeps the
  segment asked for. A slug stored that way before keeps it: the edit screen
  points it out, and *Regenerate from the title* then a save clear it.
- **A page translated before its parent moves under it.** A child written in a
  language its parent did not have yet answered at the root of that language,
  and stayed there once the parent was translated. Giving a page its first
  address in a language now puts the pages under it, the ones already written
  in that language, under that address. Their former address redirects.
- **The model of a page type stays off the front.** The front left the model
  out only because the back office never publishes it. Set visible from its
  edit screen and imported with a published version, from a file edited by
  hand or written elsewhere, it was served on its address. The pages, the
  menus, the breadcrumb, the lists of a tag, the search, the TntSearch index
  and the sitemap now leave a model out on their own account, and an import
  keeps the draft of a model and drops the published version the file gives
  it. A model can no longer be set online nor moved to the bin: its edit
  screen shows *Online* set to *No* and greyed out, saving it puts it offline,
  an import brings it in offline, and posting to its bin or visibility action
  is refused with a message. It still goes with its template when the
  template is deleted.
- **`cms_is_home()` answers false on a site with no home page.** Without a
  home page set, it took the page of id 0, and an id the theme did not have
  (a product page passes none), for the home page; it also refused a null id.
  It takes a number, a string or null, and answers a boolean.
- **A reserved slug is refused on its field.** Saving a page with the slug
  `admin`, `api` or another reserved path answered an error 500; the form now
  shows why under the field.
- **A picture reaches a component of the theme as its address.** A prop
  documented `image` was handed to the component as stored, the id of the
  media library, which the component printed in a `src`: the picture did not
  show. The section turns it into the address of the picture, as the
  components made in the back office already did; an `https://` address is
  kept, and an image gone from the library leaves the prop to the default of
  the component.
- **A prop of a component of the theme may be `html`.** Any type the reader
  did not know read as a line of text of 500 characters at most, so a
  component documenting a prop as `html` refused a text of a few paragraphs
  and had it written as plain text. It is now markup, without a length
  limit, filtered by the writer on the rendered page like the text of
  `cms:richtext`.
- **The right to generate reaches a site that updates the module.** The rows
  of the rights were only written when the module was activated, so a site
  that had it active before `admin.cms.generate` existed, and only ran
  `module:refresh` since, never got the row: generating was refused to every
  profile but the super administrator, and the profile screen could not grant
  it. An update now seeds the missing rights, existing profiles without
  access, like an activation; the right is labelled "CMS: page generation".

### Added (2.3.0)

- **The generate command writes items, translations and at an effort of its
  own.** `thelia_cms:generate` takes `--item <type>:<id>` to write the
  document of a product, a category, a content or a folder, `--translate-from
  <locale>` to write a language from the draft of another, and `--effort` to
  set the effort of one call without changing the settings. What the
  evaluation of the generator runs without a browser.
- **Menu entries pointing at a category or a product.** An entry of a menu
  can point at a category or a product of the catalogue (`target_type`
  `category` or `product`, `target_id` the id of the item). It links to the
  address the shop serves the item on in the language being read and, with no
  label of its own, shows the title of the item in that language. A hidden or
  deleted item, or one with no title or address in that language, is left out
  of the menu and reported on the menu screen with the reason. The entry form
  lists the categories as their tree and the products grouped by their default
  category. The cached menus are dropped when a category or a product is
  created, changed, hidden, given a new address or deleted. The export keeps
  the id of the item, and the import reports it, the way it does for contents
  and folders.
- **The import says when a menu entry points at an item this site does not
  have.** An entry pointing at a content, a folder, a category or a product
  whose id the importing site lacks is kept, and the report says so rather
  than asking to check that the id is the right one. `cms_menu()` is unchanged; `source` says `category` or
  `product`. No schema change.

### Fixed (2.2.1)

- **The column of the writing instructions reaches a site already on 2.2.0.**
  `cms_page_type.instructions` was added by `update/2.2.0.sql`, which a site
  already on 2.2.0 never plays again: reading a page template then failed.
  The column moves to `update/2.2.1.sql`, replayable, so a site on 2.1.0 or on
  2.2.0 gets it, and one that already has it is left alone.
- **A new skill goes after the site's skills.** On a site that already has
  skills, a newly shipped skill took its rank among the files, a position
  another skill already held (two skills shown at the same position). It now
  goes after the last position, in the order of the files when several
  arrive; an empty table still takes the order of the files.
- **The intro of a page template mentions its writing instructions.** The
  sentence above the form says the pages follow the writing instructions of
  the template, next to its brief and fields.

### Added (2.2.0)

- **Writing instructions for a page template.** A page template gains
  **Writing instructions** next to its brief: how every page of it is
  written (tone, length, expected sections, mandatory mentions). The
  generator reads them as rules for every page of the template, after its
  brief, whether it writes, revises, rewrites a section or translates; they
  travel in the export. The update to 2.2.1 adds `cms_page_type.instructions`;
  `PageTypeWriter::describe()` takes them as a last, optional argument.
- **Claude Code and Chrome.** `docs/claude-code-and-chrome.md` tells how to
  let Claude Code work in the back office through a Chrome window and check
  the pages as a visitor; `docs/plans/deterministic-skills.md` plans how to
  measure and tighten the skills so one brief gives one page.
- **Comparing runs of the generator.** `thelia_cms:compare-runs <round>`
  reads the outputs of `thelia_cms:generate --dry-run` kept per brief and says,
  in Markdown, how much the runs of each brief agree: structure, shape,
  length of each section, figures. It calls no API.
- **Two skills: translation and the design system.** `translation`, always
  read: a page written from another language keeps its meaning, its order,
  its names and its figures as written, and follows the typography of its
  language. `design-system`, always read: every page uses the components of
  the catalogue rather than free text imitating them, with no style of its
  own. Existing sites receive them on the next read of the skills.
- **Reordering the skills from their list.** **CMS > Skills** shows arrows
  that move a skill one slot up or down, and numbers the positions again from
  1; the generator reads the skills in that order.
- **Documentation in the back office.** **CMS > Documentation** tells an
  editor, in French or in English after the language of the back office, what
  the module lets them do: pages and their addresses, content and the
  generator, page templates, tags, the content of catalogue items, components,
  skills, menus, forms, media, settings and permissions. It takes the right to
  see the pages. Every screen of the CMS links to the section explaining it.
- **Fixed addresses.** A page can keep, in a language, a whole address that
  does not follow the tree (`about-us.html`), typed in the new **Fixed
  address** field of its edit screen: a site taken over keeps the addresses
  search engines know. Rebuilds keep it, it travels in the export, and the
  update to 2.2.0 adds `cms_page_i18n.fixed_url`.

### Added (2.1.0)

- **A document for each catalogue item.** A product, a category, a content
  and a folder carry a document of sections in each language, written on the
  content screen of a page (from a brief, by asking or by hand) and reached
  from a **CMS content** tab of the edit screen of the item. A theme prints it
  with `cms_item_content(type, id)`. The update to 2.1.0 adds the
  `cms_item_content` and `cms_item_revision` tables (docs/adr/0011).
- The documents of the catalogue items count as uses of their images and
  components, travel in the export (section `catalogue_contents`) and go back
  on import on the item with the same reference or address.
- The preview of the document of an item shows the **whole page** of the item
  with the draft in place, or the **content only**, without the header and the
  footer of the theme. A list sets the width of the preview to a breakpoint of
  the theme, as its toolkit does.

### Changed (2.0.0)

- A record or a picture the PHP class of a theme component takes
  (`{# @prop productId product #}`, documented and not declared in
  `{% props %}`) is required unless its type says `|null`: a product card
  saved without its product rendered an empty card. Scalars the class takes
  stay optional. A page holding such a section without the record keeps
  rendering, and is refused at its next save until the record is picked.
- **Blocks are gone.** The reusable blocks (**CMS > Blocks**) and the blocks
  of catalogue items (the "Content block" card of a product, a category, a
  brand or a store) are removed. Their tables are renamed by the update to
  2.0.0, rows included: `cms_block`, `cms_block_i18n`, `cms_block_content` and
  `cms_item_block` become `cms_legacy_block`, `cms_legacy_block_i18n`,
  `cms_legacy_block_content` and `cms_legacy_item_block`, which the module
  does not read. A theme that called `cms_item_block()` removes the call. An
  export taken before the update keeps the blocks; a 2.0.0 module imports the
  rest of the file and warns that the blocks were left out.
- **Saved page templates are gone.** The **CMS > Templates** screen, the
  `cms_page_template` table (renamed `cms_legacy_page_template` by the update
  to 2.0.0, rows kept) and the code that
  saved a page as a template and started a page from one. A page is started
  from a brief, or duplicated from another page.
- **The content of a 1.x site is not carried over.** The content of a page
  is now a document — an ordered list of sections, each a component
  of the catalogue with its props — rendered to HTML on save and on publish.
  The markup the former editor stored has no document to be read back from, so
  the update to 2.0.0 puts every page back to draft, without content,
  and empties their revisions and search entries. Pages
  keep their tree, addresses, SEO, tags and types. What the former editor
  wrote is copied first to `cms_legacy_page_content` and
  `cms_legacy_page_revision` (HTML, editor project, stylesheets), which the
  module does not read. **Export the site before updating** all the same
  (`php Thelia thelia_cms:export`): the file keeps the HTML, and a 2.0.0
  module imports everything in it but the contents.
- `cms_page_content` carries `draft_document` and
  `published_document` and lose `draft_project_data`, `draft_css` and
  `published_css`; `cms_page_revision` carries `document` and loses
  `project_data` and `css`. A page has no stylesheet of its own any more: its
  look comes from the components it is made of and from the stylesheets of the
  site.
- The export format is version 2: contents are `draft_document`, `draft_html`,
  `published_document`, `published_html`. A version 1 file still imports its
  pages, menus, forms, settings and media, with a warning that the contents
  were left out.
- `TheliaCMS\Page\PublishedPage` loses `css` and the `layout` deprecated in
  1.2.0. A theme that printed `cms_page.css` removes the line.
- The heading check (`heading_check_mode`) runs wherever a page goes online —
  the editor, the settings screen, `thelia_cms:publish` — and no longer in the
  editor alone. In `block` mode the writer refuses the page with
  `HeadingStructureException`; in `warn` mode the editor still shows the
  problems after publishing.
- `CmsPageWriter::saveContent()` takes a
  `TheliaCMS\Content\Document`; `BuilderContent` is gone. A document whose
  sections name no component of the catalogue, or carry props a component
  refuses, is rejected with `InvalidDocumentException` listing every problem.
- Unpublishing a page drops its published snapshot along with the date.

### Added (2.0.0)

- **A page under Flexy is laid out like a content of the shop**
  (docs/adr/0010): the module ships `templates/frontOffice/flexy/cmspage.html.twig`
  with the heading band of the theme (title, summary, breadcrumb), the fields
  of the page type, then the sections. Every template of a page draws its
  title as the level 1 heading, the fallback of the module included: the
  generator no longer writes an `<h1>` nor opens with a heading band, the
  heading check reports a level 1 heading in the content of a page, and the
  legal pages are seeded without one.
- **A page type is a template of pages** (docs/adr/0009). Its screen under
  **CMS > Page templates** (formerly **CMS > Settings > Page types**, the
  same screen, now also in the menu) names it in each language, gives the brief
  the generator reads before the brief of every page of the type, defines
  the fields its pages carry (whole number, decimal, duration, short or long
  text, yes or no, a word from a list, a date; with a unit, bounds, one value
  per language for a text, required to publish) and creates its model page,
  the demo content written like any page and never published. **Start a
  page from a template** on the page list copies the model under the title
  typed; the "Fields" tab of a page holds its values, which the generator
  writes along with the content (a `fields` string in the envelope, checked
  and sent back for correction like the props). A theme reads
  `cms_page.fields.<code>`, `cms_page_fields(page)` and lists the pages of a
  type with `cms_pages_of_type(type, {sort, direction, filter, limit})`; an
  editor places the list with the **Pages of a type** section
  (`Partial:cms-page-list`). The description of the types, the model flag
  and the values travel with the site export. The update to 2.0.0 adds
  `cms_page_type_i18n`, `cms_page_type.brief`, `brief_tone`,
  `brief_audience` and `fields`, `cms_page.model` and `fields`, and
  `cms_page_i18n.translated_fields`. A page of a type left without a
  required value is refused at its publication, the model of a type always.
- A section may carry an appearance besides its props: a background, a
  colour of text (`#rrggbb`), room above and below (`small`, `medium`,
  `large`) and an alignment. A brief or a change asks for it in words ("on a
  pastel red background") and the generator writes the values; the
  **Appearance** group of "Edit by hand" sets them too. Nothing else about
  the look of a section can be set: the values are checked when the document
  is read, a colour written otherwise is sent back to the model to be
  corrected, and the module writes the style itself. A section with an
  appearance is wrapped in a `div.cms-section-styled` of the whole width that
  carries `data-cms-section` and the style; a section without one renders and
  is stored as before. A heading a component colours itself (`text-black`)
  keeps its colour. A translation keeps the appearance of each section of
  the source, whatever the model writes back. In "Edit by hand", a colour
  picker sits next to each colour field and writes into it.
- "Edit by hand" and "Rewrite this section" open in a dialog rather than
  under the section in the list.
- **Components made in the back office.** **CMS > Components > New
  component** asks the generator for a component in a prompt; the site checks
  it, draws its sample in the theme at the width of a phone, a tablet or a
  screen, and keeps it once the editor says so, after as many changes as
  asked. A kept component is `Site:<Code>`, a component like any other:
  offered to the generator of pages, edited by hand, on the toolkit page,
  switched off on the list. It is props as data, a Twig template rendered in
  a sandbox of its own and given the props alone, with no text of its own,
  and a stylesheet scoped to the component and served at
  `/cms/site-components.css`. A new version that would break the pages using
  it is refused, the pages named; keeping one draws their drafts again. A
  component in use cannot be deleted. Making one takes the settings and the
  custom code permissions. Stored in `cms_component` (created by the update
  to 2.0.0); the calls go in the ledger with the pages; the components
  travel with the export of the site and are checked again on import.
- A **Columns** section (`cms:columns`): two to four columns side by side,
  each an image over an optional heading and a short text, as many columns as
  items. The image is one of the media library or the `https` address of a
  picture kept elsewhere; the text is written as text. The module draws it
  with a grid of its own (`blocks.css`), one column on a narrow screen, so it
  needs nothing from the theme. A brief asking for "three columns with a logo
  and a short text" now has a section to write it in.
- An **Image** section (`cms:image`): one picture on its own, of the media
  library or the `https` address of a picture kept elsewhere, with an
  optional caption and three widths (reading column by default, content,
  whole screen).
  Free text holds no image: a brief asking for "a photo of the tart out of
  the oven" now has a section to put it in.
- The generator says what a draft leaves out of the brief: an image the
  media library does not hold and the brief gives no address of, a product
  the search does not find, a page that could not be read. The answer
  carries an optional `left_out` list, stored in a new column of
  `cms_page_generation` (added by the update to 2.0.0), shown as a warning
  that stays until it is closed when the draft comes back, in the history of
  the page and by `thelia_cms:generate`. A brief asking for a photo the site
  does not have used to give a page without it, and no word about it.
- `thelia_cms:render` renders the drafts of pages again from their documents,
  without publishing them (`--page`, `--all`, `--locale`): a change of the
  module or of the theme reaches every draft at once instead of at its next
  save. A draft whose custom code the new rendering would take out is left
  alone and listed, the command holding nobody's right to it.
- `thelia_cms:publish`, `thelia_cms:generate` and `thelia_cms:render` render a
  page holding a component of the theme that reads its records through the
  API (a product card): from the command line, it failed with "No current
  request found", then "URL instance is not initialized". The commands run
  the rendering as a visit to the address of the shop, `url_site` or `--url`;
  without one, the page fails with a message naming them.
- A theme names the classes it styles written text with,
  `sections.richtext_class` in its `config/theliacms.yaml`, and free text
  sections carry them next to `cms-richtext`: on Flexy, `wysiwyg
  wysiwyg-theme` gives their lists and headings the look of the other texts
  of the shop. A page saved before keeps its old rendering until its next
  save or publication.
- Once a page has a brief, the content screen recalls it in three lines,
  with its tone and audience, and **Change the brief** opens the form in a
  dialog: the room goes to the sections. A page without a brief keeps the
  form in the screen.

- A brief may cite web pages by their address: the generator offers the
  `web_fetch` tool of the Claude API for that request, and the model reads
  them before it writes (five pages at most, 20,000 tokens each). A brief
  that cites none sends the request it always sent. The pages are read by
  Anthropic, never by the server of the shop; the tool has to be allowed
  for the organisation of the key, and **Read the web pages a brief cites**
  in the Generator card of CMS > Settings turns it off (`generator_web_fetch`,
  on by default). The history of the content screen lists the pages a call
  read, with the tokens of each and what one reading costs; the ledger keeps
  them in `cms_page_generation.web_pages`, which the update to 2.0.0 adds.
- A theme gives a component the props it is shown with under
  `sections.examples` of `config/theliacms.yaml`: the toolkit, the generator
  and a section added by hand start from them. For a component whose defaults
  render nothing, such as Flexy's `Organisms:VideoPlayer:Base`.
- Hovering a section in the preview of the content screen shows **Edit**,
  which opens the form of that section in the list.
- **Add a section** in the preview of the content screen, after the last
  section: a component the site offers is added at the end of the draft with its example props, then
  edited by hand. A page with no content yet can be started that way, without
  the generator.
- The sections of a draft are **reordered by hand** on the content screen,
  dragged by their handle or moved one place up or down with the arrows, with
  the right to change pages. The move is
  saved in the draft and written to the ledger like any change by hand.
- **CMS > Components** lists the components of the catalogue, where each
  comes from and how many pages use it, and switches one off for the
  generator. A component switched off is left out of what the generator reads
  and may write, except on a page that already uses it; pages keep rendering
  it. `SectionCatalog::offered()` is the catalogue the site offers,
  `SectionCatalog::all()` stays everything a page may name.
- `TheliaCMS\Content`: `Document`, `Section`, `SectionDefinitionInterface`
  (implementing it registers a component), `SectionProviderInterface`,
  `SectionCatalog`, `Prop` and `PropSchema` (typed props, checked on the way
  in, described as a JSON schema), `DocumentValidator`, `DocumentRenderer`,
  and the one built-in component, `cms:richtext`, a piece of free markup held
  at the width of the sections by `.cms-richtext`. See `docs/sections.md`.
- Every partial of the site is a section, `Partial:<name>`, with the settings
  of the partial as props and the placeholder the page serves through as
  rendering.
- A theme opens its Twig components to pages with `sections.components` in
  `config/theliacms.yaml`; their props are read off the `{# @prop #}` comments
  and the `{% props %}` tag of the template, and they render through the
  component renderer of Symfony UX. Nothing is opened by default.
- `cms_section(name, props)` renders a section from a template, with its
  sample props when none are given. Every section of the catalogue is listed
  on one page of the toolkit of the Flexy theme, under "CMS".
- **The generator** (`TheliaCMS\Generation`): a page is written from a brief
  by the Claude API, as a document constrained to the JSON schema of the
  catalogue, validated and rendered by the site. The model reads the skills
  and the catalogue (cached), may search the products, categories, pages and
  images of the site, and sends an invalid document back once. Every call is
  written to the ledger `cms_page_generation` with its tokens and an estimate
  of its cost. The key is read from `ANTHROPIC_API_KEY`; the model, the effort
  and the token limit are settings. Command `thelia_cms:generate`. See
  `docs/generator.md`.
- **The content screen** (`/admin/cms/pages/{id}/content`): the brief on the left, the draft on the right as the theme
  shows it; a form to rewrite each section alone, one to ask a change on the
  whole draft, the history of the calls with their cost, and the publish
  button. The forms post in the background, the progress of the call is
  streamed as server-sent events, and the screen is drawn again in place
  when the call ends, without a reload; without the script, every action
  still posts and comes back with a message. "Edit content" everywhere in
  the back office now leads here rather than to the visual editor.
- On the content screen, every section is also **edited by hand**: a form
  read from the schema of its component, the `html` props in the editor of
  the Tiptap module when it is there (`thelia/tiptap-module`, suggested),
  lists as rows added and removed, images from the media library, references
  from their picker. Stored through the writer like a generated document and
  written to the ledger as a `manual` row, so the next request starts from
  the corrected text. Costs nothing, takes the right to change pages. Saved
  in the background, the screen drawn again in place with the panel open.
- `GenerationTarget`: what the generator writes for, a page.
  `GenerationObserverInterface` reports each turn to whoever listens.
- **Skills**: the instructions the generator reads, shipped as
  `skills/<code>/SKILL.md` by the module (five) and the theme, sown into
  `cms_skill` at the first read and edited there by the site, under
  **CMS > Skills** (the settings right): rewrite, switch off, reorder, put
  the shipped text back.
- The **Generator** card of CMS > Settings: the model, the effort and the
  token limit.
- The answer of the API is read as a stream: the screen shows how much of
  the document is written as it comes (`GenerationObserverInterface::onText`,
  event `delta`). Measured on 2026-10-20: an answer constrained to the schema
  is delivered by the API in a burst at the end of the turn (first delta at
  4.3 s, last at 5.3 s on a 5.3 s call), so the turns stay the progress a
  reader sees; the stream pays off on the day the API sends constrained
  answers as they are written.
- The tool `search_forms`, so a contact page finds the form of the site
  rather than guessing its id.
- An `image` prop holds the id of a library image or the `https://` address
  of a picture kept elsewhere; the hand-edit form previews the picture,
  replaces it by a file that joins the media library, or takes an address.
- **The components of the theme come first.** A `@prop` of a theme
  component may name what the site holds — `image`, `product`, `category`,
  `folder`, `form`, `menu`, or a list such as `product[]` — and the
  parameters the PHP class of the component takes (`productIds`) are read
  when they are documented; the component receives ids, as a template of the
  theme would pass them. The theme puts the module's sections it replaces
  away under `sections.hidden` of `config/theliacms.yaml`, and the
  `catalogue` skill sends the model to a component of the theme before a
  block of the module. With that, Flexy's `Layouts:CrossSelling`,
  `Organisms:VideoPlayer`, `Organisms:CategoryCard` and the like are used on
  a page as they are, without a theme template over a partial of the module.
- The toolkit of the Flexy theme gets a page with every section of the
  catalogue (`/toolkit/cms-all-sections`, `cms_catalogue()` in Twig), and
  the content screen links to it in debug, so a brief names what it wants.
  Every CMS story of the toolkit now loads the stylesheet of the sections.
- On the content screen: the brief the page was last written from is back
  in its form (the ledger keeps it, column `brief` of `cms_page_generation`);
  every section has a number, a button that scrolls the preview to it and a
  button that removes it; the preview and the list point at each other. A
  page that holds a draft in another language and nothing in this one offers
  to be written from that draft (`GenerationRequest::translation`, kind
  `translation` in the ledger) rather than announcing an empty page. The
  renderer marks the first element of every section with
  `data-cms-section="<id>"`.
- `docs/adr/`: the eight decisions behind the generation of pages;
  `docs/evaluation/`: ten briefs, a rubric and the scores of the shipped
  skills (7.1 / 8 on 2026-10-20).
- The right `admin.cms.generate`, given to the seeded editor profile.
- The module requires `anthropic-ai/sdk` and `nyholm/psr7`.

### Removed (2.0.0)

- **The visual page builder** (GrapesJS, `openstudio/page-builder-bundle`):
  the screens `/admin/cms/pages/{id}/builder` and
  `/admin/cms/blocks/{id}/builder`, their bundled editor, the routes
  `openstudio_page_builder_image_*` the editor uploaded and listed images
  with (the media screens of the module have their own), the "Blocks offered
  in the editor" card of the settings with the `disabled_blocks` setting (the
  catalogue a page is made of is the allowlist of the theme, see
  `docs/sections.md`), the canvas stylesheet of the theme
  (`config/theliacms.yaml` key `editor`, `builder_stylesheet`) and
  `docs/editor-canvas.md`. The module needs no npm build any more.
- `TheliaCMS\Builder`: `CmsBuilderConfig`, `ThemeCanvas`, `ActiveThemeCanvas`,
  `CanvasStylesheetProviderInterface`, `BlockCatalog`, `CoreBlockCatalog`,
  `CatalogBlock`, `CatalogBlockProviderInterface`, `EditorBaseBlocks`,
  `Admin\CmsPageBuilderController`, `Admin\CmsPageContentType`;
  `TheliaCMS\Block\Admin\CmsBlockBuilderController`;
  `TheliaCMS\Media\LibraryImageUploader`, `LibraryImageCatalog`,
  `Admin\CmsMediaController`; `TheliaCMS\Settings\DisabledBlocks` and
  `CmsSettings::disabledBlocks()`; `PartialRegistry::toEditor()`;
  `PageContentNormalizer::css()`. The export format no longer carries
  `disabled_blocks`; a file that does is read without it.
- The site styles screen (`/admin/cms/settings/styles`, closed since 1.4.0)
  and the colour palette of the editor (`builder_palette`). The typography
  of the site is still served (`SiteStyles::typography()`).
- The webmaster guide (`docs/guide/`, PDF and screenshots): it described the
  editor of 1.x. A guide for the content screen is to come.
- **Blocks**: `TheliaCMS\Block` (screens, writers, repositories, the hook of
  the "Content block" card, the purge on deletion of an item), the models
  `CmsBlock*` and `CmsItemBlock*`, `Generation\Admin\CmsBlockContentController`,
  `Preview\CmsBlockPreviewController` and `PreviewLink::urlForBlock()`, the
  Twig function `cms_item_block()`, the source `admin.cms.partials.sources.blocks`
  and the `block` type of a `@prop`, `GenerationTarget::block()` and the
  components a target excludes, `CacheTags::block()` and `::item()`,
  `PartialCache::invalidateBlocks()`, the blocks of the export format.
- The partials a component of the theme replaces: `Partial:cms-block`,
  `Partial:product-card`, `Partial:product-strip`, `Partial:category-card`,
  `Partial:category-strip` and `Partial:video-facade`, with their templates,
  their styles in `blocks.css`, `Partial\Catalogue\ProductSummary`,
  `ProductCardPrice`, `CategorySummary`, `CatalogueImage` and the image
  source `admin.cms.partials.sources.images`. A product, a category or a
  video on a page is a component of the theme (`sections.components`).
- **The page blocks of the module** (hero, text and image, call to action,
  quote, testimonials, key figures, logos, gallery, questions and answers,
  section) have no section of their own: a page is built from the
  components the theme opens, free text (`cms:richtext`) and live content.
  `blocks.css` keeps the styles of the free text, the partials and the forms.
  A module adds a component by implementing `SectionDefinitionInterface`
  (see `docs/creating-a-component.md`); there is no `AbstractTemplateSection`
  and no `cms/sections/<slug>.html.twig` to take over.
- The click-to-load embeds: `Partial:map-facade` and `Partial:social-facade`
  (`MapFacadePartial`, `SocialFacadePartial`), the shared `_facade.html.twig`
  and its script, and their styles. A map or a social post on a page is a
  component of the theme. An iframe written in a page is still refused unless
  the author may write custom code.
- `Partial:latest-contents` and `Partial:cms-menu` (`LatestContentsPartial`,
  `MenuPartial`), with their templates and their styles. The live content of
  the module is a form; the latest news or a menu on a page is a component of
  the theme. `cms_menu()`, the footer menu hook and the menus themselves are
  untouched.
- Saved page templates: `ImportExport\Admin\CmsTemplateAdminController`,
  `PageTemplateWriter`, `SaveAsTemplateType`, `PageFromTemplateType`, the
  models `CmsPageTemplate*`, `SiteExporter::exportPage()` and
  `SiteImporter::importPageFrom()`. `Page\PageTemplateResolver`, which picks
  the Twig template of a page by its type, is unrelated and stays.
- The pipeline a page goes through before it is stored or published moves
  from `TheliaCMS\Builder` to `TheliaCMS\Content\Pipeline`:
  `HeadingChecker`, `EmptyContentChecker`, `PlaceholderContentChecker`,
  `ImageRewriter`, `PublishedContentSanitizer`, `PageContentNormalizer`.

## [1.6.2] - 2026-10-28

### Fixed

- **No more `Vary: Accept-Language`.** The language of a page comes from its
  address, never from that header: it only multiplied the copies of a page.

## [1.6.1] - 2026-10-28

### Security

- **The guard of the back office reads the path the way the router does.**
  It compared the prefix `/admin/cms` with the path as the client wrote it,
  while the router decodes the path before it matches a route: with one
  letter percent-encoded (`/admin/c%6Ds/forms`), a request reached the screens
  of the module without logging in, including the export of the submissions
  of a form, skipped the token checked on a write, and was granted the right
  of the pages whatever its section. A proxy that normalises the path in front
  of the site hid it; one that passes the path as it came did not. The guard now
  compares the decoded path (`RequestPath::decoded()` of the core), and so
  does the showcase mode for the paths it closes (`/cart`, `/order`, `/checkout`). Tests send encoded
  addresses anonymously, with a profile limited to the pages, and to a hidden
  screen.

### Fixed

- **The menus follow a content or a folder published on schedule.** The core
  dispatches `CONTENT_UPDATE_PUBLICATION` and `FOLDER_UPDATE_PUBLICATION` when
  a content or a folder is published, scheduled or taken offline, and the
  menus did not listen to them: an entry pointing at such a content kept its
  former state until the cache of the menus expired (one hour by default).

## [1.6.0] - 2026-10-27

### Added

- Twig function `cms_is_home(id)`: tells whether a page is the one the module
  shows as the home page.

## [1.5.0] - 2026-10-20

Ships 1.1.0 to 1.4.0, never tagged on their own: their entries carry their version in the heading.

### Added

- On a Thelia core that brings the shared cache of the public pages
  (`shared_cache_mode`), the pages of the module are shared like the
  catalogue pages once the shop turns it on: the `cmspage` view, and so the
  home page the module takes over, is declared to the core, which then
  decides whether an answer may be kept and writes its headers. The module
  steps aside from its own `Cache-Control`, `Cache-Tag` and `Surrogate-Key`
  while the core shares, and files every page under `cms` and `cms-menu`
  (the menus and settings of the module can show on any page), a page of the
  module under `cms-page-<id>` as well. Every purge of the module (a page
  published, a menu or the settings saved, a block edited) is handed to the
  proxy of the core, and publishing the block of a catalogue item purges the
  page of that item (`product-42`, `category-7`, `brand-3`). On an older core
  nothing changes: the module keeps its own headers and `http_cache_ttl`.
  Publishing a page purges `cms` like before, which on a shop that shares
  means every page.

- A page has a type, which picks the template it is displayed with:
  `cmspage-{type}.html.twig` from the theme, then from this module, before the
  usual `cmspage.html.twig`. The types are listed, added and deleted under
  **CMS > Settings > Page types**, which says for each one the template it
  gets. A type is a code and nothing else; the three former layouts become the
  types `default`, `full-width` and `landing`, and each page keeps its own. A
  type a page has, the bin included, cannot be deleted. Themes read the code as
  `cms_page.pageType`, the modules hooked on `cmspage.*` as `page.pageType`.
  The type follows a page when it is duplicated, travels with the site export
  and with saved templates. An import of a site creates the types the site
  lacks; a page started from a saved template whose type the site no longer
  has gets the default type, since starting a page needs no right on the
  settings. Files and templates written before 1.2.0 are read through their
  `layout`.
- A page carries a summary and a detailed description per language, the same
  pair as a core content, typed under its address in the "General" tab with
  the back-office rich text editor when Tiptap is installed. They are not the
  body of the page, which the builder owns, and nothing renders them on their
  own: a theme reads them as `cms_page.chapo` and `cms_page.description`, and
  the modules hooked on `cmspage.*` as `page.chapo` and `page.description`.
  Both are HTML filtered on save like the content of the page: no script, and
  no iframe without the custom code right; an imported file gets no iframe
  either. Both follow the page when it is duplicated and travel with the site
  export.
  The update to 1.1.0 adds the two columns to `cms_page_i18n`.
- An "Image" tab gives a page one image, the same in every language, chosen
  from the CMS media library or uploaded into it, and saved with the rest of
  the page. Uploading takes the right to create media, as on the media screen:
  without it the tab only offers the images already in the library. A theme reads it as `cms_page.image` (`url`, `alt`, `width`,
  `height`); an image deleted from the library leaves the page without one.
  It follows the page when it is duplicated, travels with the site export
  like the images of the content, and counts as a use on the media screen, so
  the image of a page cannot be deleted from under it. The update to 1.1.0
  adds `image_id` to `cms_page`.
- **CMS > Settings** lists every block the editor can offer, group by group as
  the panel shows them — the basic, layout and advanced blocks of the editor
  itself, the page blocks of the catalogue, the live content — and lets an
  administrator untick the ones a site has no use for. Until now every block
  was offered to every editor, so a theme that styles no gallery still had a
  gallery in the panel, and the "Video" and "Map" blocks of the editor sat next
  to the facades that replace them. A block is offered until it is unticked,
  which is why the setting stores the blocks that are off: one a module or the
  editor adds later shows up on its own. Unticking changes the panel and
  nothing else: a page that already holds the block keeps it, previewed and
  editable in the editor, rendered on the front. The list travels with the
  site export as `disabled_blocks`.

### Upgrading to 1.4.0

- The update adds the `cms_item_block` table. Update the module before
  opening a catalogue screen, and remove `var/propel/<env>` so the models are
  generated again.
- Going back to 1.3 after the update:

  ```sql
  DROP TABLE `cms_item_block`;
  ```

### Added (1.4.0)

- **Product** and **Category** blocks put one item of the catalogue on a
  page, searched by the editor (a product by reference, name or barcode, a
  category by name, shown with its parent) among the items online. The page
  stores the id, the item is read on every visit and the block leaves the page
  once the item goes offline. The module's cards show the picture, the name,
  the taxed price of the default sale element and a link; a theme draws its own
  through `cms/partials/product-card` and `cms/partials/category-card`.
- A reference setting of a dynamic block can be `searchable`: the editor
  shows a search box rather than a select holding the whole source, and the
  source answers `?q=` with what matches and `?id=` with the record chosen.
  Made for sources too long to list at once, such as the products of a
  catalogue. See "Picking from a long list" in `docs/creating-a-block.md`.
- A product, a category, a brand and, when the Dealer module is active, a
  store can each carry one content block: a free layout built with the page
  editor, the way a reusable block is, and shown by the theme on the page of
  the item. The block is created, opened and deleted from a "Content block"
  card in the right column of the product, category and brand screens, and
  from a tab of the same name on the store screen; its builder leads back to
  the item. It is published per language like any block, and the theme reads
  it with `cms_item_block('product', product.id)`, which answers the published
  HTML and CSS or null — or from PHP with `PublishedItemBlockRepository`. The
  dynamic blocks of a page are offered in it, the reusable block excepted.
  The block goes with its item when the item is deleted. The blocks of items
  are kept out of **CMS > Blocks**, of the reusable blocks a page may embed
  and of the site export, and an import leaves their codes alone. Editing
  them takes the right to edit pages, on any item. The module purges the
  cache of its own pages only: the page of the item follows its HTTP cache.

### Upgrading to 1.3.0

- The update adds the `cms_tag` table and `cms_page.tag_id`, with a constraint
  that detaches the pages of a deleted tag. Update the module before serving
  pages, and remove `var/propel/<env>` so the models are generated again.
- Going back to 1.2 after the update:

  ```sql
  ALTER TABLE `cms_page` DROP FOREIGN KEY `fk_cms_page_tag`, DROP COLUMN `tag_id`;
  DROP TABLE `cms_tag`;
  ```

### Added (1.3.0)

- A page carries one tag or none, picked in the "General" tab among the tags
  of the site, which are listed, added, renamed and deleted under
  **CMS > Tags** with the right to edit pages. A tag is a name shown as it is
  in every language. Deleting a tag leaves its pages without one, the bin
  included, and the screen says how many. Themes read the tag of the page
  served as `cms_page.tag`, the modules hooked on `cmspage.*` as `page.tag`,
  and list the pages online carrying a tag with `cms_pages_tagged(tag)`, the
  most recently published first; from PHP,
  `PublishedPageRepository::withTag()` answers the same. The tag shows in the
  page listing, follows a page when it is duplicated, travels with the site
  export and with saved templates, and an import creates the tags the site
  lacks. Files written before 1.3.0 import pages without a tag.

### Upgrading to 1.2.0

- The update renames `cms_page.layout` to `page_type` and adds the
  `cms_page_type` table. Update the module before serving pages, and remove
  `var/propel/<env>` so the models are generated again: until both are done,
  every CMS page fails on `Unknown column 'page_type'`, or on `layout` the other
  way round.
- Going back to 1.1 after the update needs the column back under its former
  name, kept at 50 characters so no code is cut. Export the database first:

  ```sql
  ALTER TABLE `cms_page` CHANGE COLUMN `page_type` `layout` VARCHAR(50) NOT NULL DEFAULT 'default';
  DROP TABLE `cms_page_type`;
  ```

### Changed

- The constructor of `PublishedPage` takes `string $pageType` where it took
  `PageLayout $layout`. The module is the only one meant to build that object;
  a hook reading `$layout` keeps working.
- Saving the settings of a page drops it from the shared cache. Its type, its
  title and its SEO fields are live as soon as they are saved, and a cached copy
  kept showing the previous ones until it expired.

### Deprecated

- `CmsPageRenderer::THEME_TEMPLATE` and `MODULE_TEMPLATE`: the template of a
  page now depends on its type, which `PageTemplateResolver` resolves. Both go
  in 2.0.0.
- `PublishedPage::$layout` and the `PageLayout` enum, replaced by
  `PublishedPage::$pageType`. `layout` is still filled for the hooks written
  against 1.x, from the type: `full-width` and `landing` read as before, any
  other type as `Default`. Both go in 2.0.0.

### Fixed

- The media screen counted a page once per language using an image, so an
  image on a page in French and English read "Used in 2 page(s)".

## [1.0.0] - 2026-10-02

### Added

- A theme declares the canvas of the editor in `config/theliacms.yaml`: the
  stylesheets it loads, written the way its templates pass them to `asset()`,
  and the classes of `<html>`, `<body>` and of the container around the page
  content. The canvas loaded one stylesheet and wrapped the page in
  `cms-page-content` only, so a theme styling its text through a class on
  `<html>` or through its own content container was previewed in the wrong
  font and without its columns. Declared stylesheets replace `styles/app.css`
  and are resolved at every opening, so a new build is picked up; the
  container classes are shown in the canvas and never saved with the page. A
  child theme inherits what it does not declare. Without the file, nothing
  changes. See `docs/editor-canvas.md`.
- `CanvasStylesheetProviderInterface` hands the editor canvas the stylesheet
  of the blocks a module contributes. The canvas only loaded the socle of the
  catalogue and the theme, so a block from another module was laid out once
  published and unstyled while an editor dragged it in. The stylesheet is
  loaded after the socle and before the theme, as on the front.
- `footer_menu_hook` renders the `footer` menu of the CMS into the footer of a
  theme that never calls `cms_menu('footer')`. Until now a CMS page on such a
  theme was reachable by its address alone: the footer of the demonstration site
  listed five native contents and no CMS page. Off by default, since a theme
  that already renders the menu would show it twice and nothing in a template
  says whether it does.
- The module ships the stylesheet of its own block catalogue, served on
  `/cms/blocks.css` and linked on every page. A page opened on a theme that
  knows nothing about the CMS used to come out as unstyled markup, which is what
  happened on the demonstration site: the socle lived in the companion theme,
  and nothing else had it. Colours go through `--cms-*` tokens that borrow the
  palette of the theme when it names its colours the way Flexy does, and fall
  back on neutrals otherwise. The socle sits in a cascade layer, so any rule a
  theme writes wins over it whatever order the stylesheets load in, and the
  companion theme now keeps nothing but its four accent tokens and one rule of
  its own.
- An address that differs from a real one only by a trailing slash answers 301
  towards the form without it. It used to answer 404, which costs a site taken
  over from WordPress, Drupal or Prestashop nearly every address it had indexed:
  those serve theirs with the slash. Nothing is redirected unless the address
  without the slash answers, the query string travels, and the root, the back
  office and the API are left alone. Decided on the request, above the router:
  with `allow_slash_ended_uri` on, `/contact/` otherwise reached the `/{_view}`
  catch-all of the Front module and rendered the contact template of the theme
  with a 200, so no 404 was ever thrown and the page answering on `/contact` was
  never reached.
- `cms_is_showcase()` in Twig, so a theme can leave out what a showcase site does
  not answer for. The cart and the checkout answer 404 in that mode, and the
  header of the companion theme linked to them all the same: a dead link on every
  page of the site, found on a real takeover.
- The slug of a page is stored per language, next to its title. Until now it only
  existed inside the address, in a core table the module has to clear when it is
  switched off. Existing sites keep the addresses they answer on: the migration
  reads each slug back from the address in use.
- `thelia_cms:publish` puts drafts online from the command line, through the same
  pipeline as the back-office button: sanitiser, responsive images, search index
  and revision. A site whose content has just been imported or rewritten in bulk
  had no way to publish other than clicking each page, and writing the published
  column from a script skips all four steps without saying so. Takes `--page`,
  `--all`, `--locale` and `--dry-run`, and reports the pages it refused.
- The page list and the showcase dashboard name the pages that are online with
  the example text of the seeded legal pages still on them. Publishing one of
  those is refused, but a site set up before that refusal existed has them live,
  in its sitemap and in search results, and nothing said so: seven of them were
  found that way on a real site. Each one is a link to the page and the language
  that needs writing. The lookup asks the database for a single word of each
  sample sentence and then confirms the answer with the same check publishing
  uses, so it never reads the published content of the whole site.
- The accessibility suite signs in and scans the page listing, the results of a
  search, the results in each publication state and the bin. It also checks the
  two things a scan cannot: that a branch of the tree opens with the keyboard
  alone, and that no control of the screen is smaller than the 24 by 24 pixels
  WCAG 2.2 asks for. Colour contrast is judged against the palette of the
  back-office theme, which fails on every screen of it, so what the test refuses
  is a colour pair this screen adds.

### Changed

- Every write of the back office under `/admin/cms` requires the back-office
  token, in the `_token` field of the body or in the `X-CSRF-Token` header. A
  script of your own that posts to these routes now gets a 403 until it sends
  the token. Saving a block also needs the right to change pages.
- **CMS > Pages** shows the tree branch by branch, with a search box and filters
  on the publication state and on the visibility. It used to list every page of
  the site flat, with nothing to search: on the pilot of a takeover that meant 128
  rows, 100 of them nested, so the structure of the site was unreadable and
  finding a page meant scrolling. A page holding others now carries the number it
  holds and opens on a chevron, what is open travels in the address, and a search
  gives a flat list of fifty results at a time where each row names the pages it
  sits under. Reordering is offered on the tree only.
- A site of fewer than forty pages opens whole, so nothing changes for a site that
  never needed folding.

### Fixed

- The page form no longer raises a Symfony deprecation each time it is built.
  Its canonical URL field left the default scheme unset; a canonical address
  typed without its scheme is now read as `https://`, as in SEOne.
- Deleting an image from the image library of the editor works. The editor
  sent the deletion to an address no route answered, so it got a 404 and the
  image stayed in the library. The deletion now follows the rules of the media
  screen: an image a page or a reusable block still shows is kept and the editor
  says why, and only images of the CMS can be deleted, not those uploaded for a
  product.
- The media screen keeps an image a reusable block still shows. It only looked
  at the pages, so deleting the image left a broken picture on every page using
  the block.
- The `srcset` of a published image offers the file at its original width. It
  only listed the fixed widths below it, so an image of 900 px shown at 1440 px
  was served at 480 px and came out blurred; the same held for every image
  between two widths. The original is the last candidate of the `<img>` and of
  each `<source>`, never repeated when it equals a fixed width, and nothing is
  enlarged past it.
- A menu entry pointing at another site is never current: an external link
  served on `/` was marked current on the home page. An entry pointing at the
  home page links to the root of the site, where the page is served, instead of
  its own address, which redirects there; it is current on the home page now.
  Choosing another home page drops the cached menus.
- An import file listing the choices of a form field (`"choices": ["A", "B"]`)
  imports like one writing them as text. The list reached the model as an
  array and stopped the whole import on a type error.
- A menu entry that carries a query string is current only when the page has
  the same parameters. Entries pointing at `/search?q=recipes` and
  `/search?q=labels` were both marked current on any search.
- The canvas of the editor loaded no stylesheet of the block catalogue, so an
  author built a page as a stack of unstyled paragraphs while the published page
  came out laid out. It is handed the same sheets as the front now, in the same
  order: the socle of the catalogue, the stylesheet of the theme, then the styles
  configured for the site.
- Opening a page in the editor and saving it could empty it. A project saved
  while the canvas was empty is stored as `{"pages":[]}`, and the editor loaded
  it, dropped the markup the canvas had been given, then wrote that emptiness
  back over the HTML and the CSS of the page on the next save. Such a project is
  now read as nothing to restore, so the canvas keeps the markup it was handed,
  and the CSS of a page written outside the editor is loaded with it instead of
  being overwritten. The second half of the fix belongs to
  `openstudio/page-builder-bundle` and is already in the compiled editor shipped
  here, so nothing waits on a release of the bundle.
- The tables of the module are created with the same storage settings as the core
  ones (`utf8mb4`, `utf8mb4_general_ci`, DYNAMIC row format) instead of taking
  whatever the database server defaults to. On a host whose default is not
  `utf8mb4`, a join between a page and a core table was refused outright (MySQL
  error 1267, illegal mix of collations) and anything the server charset does not
  cover was truncated on the way in. Sites already installed are converted table
  by table, and a site already in `utf8mb4` pays nothing.
- The migration that reads a slug back from the address it answers on compared the
  page identifier as text, through a cast that carries the collation of the
  connection. As soon as that collation differed from the one of the column, the
  statement was refused and the migration ran without filling a single slug.
- The block panel of the editor read French on an English back office. The panel
  is filled while the editor still runs in the language the bundle ships as its
  default, and a block keeps the label it was drawn with, so every block and
  every category sat in the wrong language next to a screen in the right one. Two
  labels the bundle spells out in French whatever the locale, the list block and
  the icon setting, now come from the server like the rest of the wording.
- The name of a block of the catalogue, and the name of the category they are
  filed under, were read in the language of the page being written instead of the
  language of the back office: editing the English version of a page on a French
  site put "Page blocks" in the middle of a French panel. The sample text inside
  a block still follows the page, which is the point of it.
- The page listing asked the database for the publication state and the address of
  every page, one row at a time. On a tree of 637 pages over four levels that was
  1334 statements, 375 ms and 1.65 MB of HTML for a screen nobody could read; it
  is now 63 statements, 130 ms and 173 KB, and the count no longer depends on how
  many pages the site holds. A search costs 69 statements whatever it finds.
  `PageListingCostTest` compares the cost of the same read on two trees of
  different sizes, so a per-row query coming back is a red test and not a screen
  that slowly gets worse.
- The edit form of a page asked for the publication state of every page on the
  site to build its **Parent** list, and threw all of them away. It no longer
  asks.
- A page with no title in the language being edited was listed as `#28`. It shows
  its title in another language, marked as still to be translated.
- A page with no address in the language being edited was listed as `/`, which is
  the address of the home page.
- The **Scheduled** badge was white on cyan, 1.95:1 where AA asks 4.5:1 of text.
- A page hidden from the site was drawn faded, which took its text down to 1.96:1.
  It carries a mark down its left edge instead. The row also said "hidden" twice,
  once as a badge and once as its state.
- The chevron that opens a branch was an 18 by 18 target. WCAG 2.2 asks for 24 by
  24, and a test now measures every control of the screen.

- An emoji can be typed anywhere in a page. Thelia opens its database connection
  with `SET NAMES 'UTF8'`, which MariaDB and MySQL read as three bytes per
  character, and every emoji needs four: the statement was refused with
  `Incorrect string value` whatever the column was declared as, because the
  character set of the connection is settled before the one of the column. The
  editor made this worse than a plain refusal, since the sanitiser turns
  `&#128247;` into the character it stands for, which is how WordPress stores an
  emoji: a draft was accepted and then failed at publication. Characters above
  that plane are now written out on the way to the database and read back on the
  way out, on the Propel save and hydrate hooks, so every path stores them, from
  the draft and the autosave to publication, revisions, duplication, import and a
  visitor filling in a form. A stylesheet gets the escape a stylesheet
  understands. The fix belongs to the module; the connection belongs to the
  framework, and is reported there.
- Binning a page bins every page under it, however deep. The walk stopped after
  twenty levels, so anything below that stayed online, on the address it had,
  under a parent that had just left the tree. Two other walks counted levels the
  same way: the address of a page rebuilt from nothing lost the top of its path,
  and the breadcrumb of a page nested that deep started in the middle of the
  site. All three now stop on the pages they have already seen, which also ends
  the walk on a tree where a page has become its own ancestor.

- Switching the module off and on again no longer renames the pages. The
  addresses were rebuilt from the titles, so every page whose address had been
  edited by hand moved, with no redirection left behind, and the children moved
  with their parent. A site of three hundred pages lost every indexed address it
  had, and an integration test run against the wrong database was enough to do
  it. The addresses are now put back from the stored slugs.
- Restoring a page from the bin puts it back on the address it had, instead of
  one derived from its title.
- The edit screen shows the segment of the address a page owns rather than its
  whole path. Opening a child page and saving it unchanged used to fold the path
  into the segment and move the page to `parent/parent-child`.

- The media screen counts the whole library rather than the images it has loaded.
  The grid stops at two hundred images, so any library larger than that announced
  the size of one page as the size of the library, and the images still to
  describe beyond it appeared nowhere. The count now comes from the database, and
  the screen says how many of them it is showing.

- Publishing a page that has nothing on it is refused, and said so on both
  screens that publish. It used to be accepted: the snapshot was stored empty,
  the back office read "published", and the visitor got a 404.
- The page served on an address that does not exist now names itself. On an
  address that used to hold a page, the breadcrumb, its `BreadcrumbList` and the
  title kept describing the page that was asked for instead of the one shown.
- Moving a page in the tree now takes the addresses of its descendants with it.
  Only the page itself was re-addressed, so every page under it kept announcing
  the path of a parent it no longer had, at any depth and in every language, with
  no redirection from the old address and none towards the new one. Reorganising a
  tree is what the page list is for, so the defect was reachable from the first
  afternoon on a site. Each descendant keeps the slug it was given and each former
  address stays as a 301, inside the transaction of the save.
- `thelia_cms:publish --all` no longer publishes the seeded legal pages. They are
  created as drafts holding instructions on purpose, and a run of the command put
  seven of them online on a real site, sample text and all, in the sitemap
  included. Publishing a draft that still holds that text is now refused wherever
  it comes from, the back-office button included, and `--dry-run` reports the
  refusals instead of counting the pages it would then turn down.
- An address whose first segment merely begins with the letters of the back office
  gets the 404 page of the site. `/administration-des-ventes` was compared as a
  prefix against `/admin`, so it was taken for the back office and served the bare
  404 of the theme. The same comparison in the back-office sidebar is read by
  segment too.
- The "Text section" block of the webpage preset starts on a level 2 heading.
  Dropped under the title of a page, its level 1 competed with it, and the check
  run at publication reported a problem the author had not caused. The level is
  still changeable from the Tag setting of the heading.

## [1.0.0-alpha.1] - 2026-08-07

First tagged version. The database schema has been stable since 0.6.0, and this
release closes the last known defects found by reading the code and by scanning
the published pages.

### Added

- Page tree in the back office: hierarchy, ordering, visibility, duplication,
  a bin with restore, and "set as home page".
- Hierarchical rewritten URLs per language, with a 301 kept on rename, a
  denylist of reserved prefixes, and a byte limit matching the column.
- Front-office rendering through `cmspage.html.twig` from the active theme, with
  a fallback shipped by the module, both emitting the `cmspage.*` theme hooks.
- Visual page builder on `/admin/cms/pages/{id}/builder`: one canvas per
  language, autosave every 30 seconds, a guard against leaving unsaved work,
  revisions snapshotted at publication, and signed 72-hour preview links for
  clients without a back-office account.
- Publication pipeline: server-side HTML and CSS sanitizer, responsive
  `<picture>` rewriting, plain-text extraction into a FULLTEXT index, and a
  heading-structure check (`heading_check_mode`, `warn` or `block`).
- Menus, called by code from a theme (`main` and `footer` seeded): entries
  pointing at a CMS page, a content, a folder, a web address or a label alone,
  nested up to three levels, reordered with buttons or by dragging. An entry
  whose target is missing, offline or unpublished in the language being read is
  left out of the menu and reported in the back office.
- `cms_menu(code)` and `cms_page_alternates()` for themes, returning data rather
  than markup. Menus are cached per code, language and host, and the cache is
  dropped when a menu or a page it points at changes.
- Language switching that keeps the visitor on the page they were reading, and
  `hreflang` alternates limited to the languages a page is published in.
- Settings screen: what the site is, the page shown when an address does not
  exist, and maintenance.
- Showcase mode: 404 on the cart and the checkout, CMS first in the back-office
  menu, and an "Editor" profile seeded with the content permissions and none of
  the shop.
- Maintenance mode: 503 with `Retry-After`, an IP allow list, a bypass for the
  signed-in administrator, and a maintenance page editable as a CMS page.
- The page shown when an address does not exist can be a CMS page, served with
  its 404 status.
- Media library: grid, multiple upload, search by name or tag, per-language
  alternative text (mandatory unless the image is decorative) and caption,
  dimensions, weight and format, file replacement, and the pages using an image.
- Six ACL resources, declared to the container and seeded into
  `resource`/`profile_resource` so a profile can actually be granted them.
- SEO metadata per language, exposed to SEOne when it is installed: title,
  description, `WebPage` microdata, breadcrumb and `hreflang` alternates.
- Page changes recorded in the core admin log, so the existing System logs
  screen answers who published or unpublished what.
- Four unpublished legal pages seeded as placeholders in every active language.
- Activation guards: URL rewriting and FULLTEXT support are required, and
  deactivation removes the rewritten URLs the module owns.
- Block catalogue: ten blocks to start a page from (hero, text and image, call
  to action, quote, testimonials, key figures, logos, gallery, questions and
  answers, section), described on the server so their sample text follows the
  language of the page. A module or a bundle adds blocks of its own by
  implementing one interface.
- Reusable blocks: content written once in the same builder and placed on any
  number of pages, which all update when it is published. A block still used by
  a page cannot be deleted.
- Live content rendered on every visit rather than stored in the page: latest
  news, a menu, a reusable block. Each one caches its own fragment, so a block
  that changes often does not force the page out of the cache.
- Click-to-load embeds for video, maps and social posts: nothing is requested
  from the platform until the visitor presses the button, and the button is a
  link to the platform when JavaScript is off.
- Editor messages shown as notices over the canvas: they dismiss themselves,
  can be closed by hand, and an error waits until it is.
- A guide for writing a block, with two commented examples.
- Forms built in the back office: nine kinds of field (single line, email,
  several lines, drop-down list, tick box, one choice among several, phone,
  date and an agreement to be contacted), each with its label, help text and
  answers written per language. A field left untranslated is kept out of the
  form in that language and reported in the back office.
- Per form: who the answers are emailed to, a legal notice with a link to the
  privacy policy page, the message shown after sending, an optional copy sent
  back to the person who wrote, whether answers are stored, and how long.
- The `cms-form` block places a form on any page, so adding a field never means
  republishing the pages the form appears on.
- Three checks before a message is accepted, none of which asks anything of the
  visitor: a field only a robot fills in, a signed record of when the form was
  served, and a cap on how many messages one sender may get through.
- The answers screen: search by email address, export as CSV or JSON, and delete
  one answer, which is what answering a request to see or to erase personal data
  comes down to. A cell a spreadsheet would run as a formula is defused on the
  way out.
- Answers age out on their own after the number of days their form states,
  either from `thelia_cms:forms:purge` or along with `maintenance:purge`.
- Showcase dashboard block on the back-office home page: messages received over
  the last thirty days, pages online and pages still drafts, how much of the
  site is written in each language, the five pages changed last, and whether
  anything is measuring the site. Added in showcase mode only.
- `Cache-Tag` and `Surrogate-Key` on every rendered page, and explicit
  invalidation when a page is published, unpublished, binned or restored, when a
  menu is saved, when a reusable block used by pages changes, and when the site
  settings change. A project plugs its CDN in through `CachePurgerInterface`.
  A page is marked `public` only for a plain GET from a visitor with no session
  whose answer sets no cookie; `http_cache_ttl` says for how long.
- The published pages are added to the sitemap of the theme through the
  `sitemap.urls` theme hook, with `lastmod` set to the publication date and an
  `xhtml:link` per language. Pages in the bin, drafts, pages outside their
  publication window and pages marked `noindex` are left out.
- Front-office search on `/recherche` and `/search`, over the text extracted at
  publication. Pages in the bin, drafts, pages awaiting their date and pages
  marked `noindex` stay out of the results, the query is stripped of the MySQL
  boolean operators, and the page answers `X-Robots-Tag: noindex, follow`. A
  theme overrides it with `cms-search.html.twig`; a site running TntSearch gets
  a `CmsPageIndex` over the same rows.
- The maintenance page shipped by the module is now written in the language the
  visitor is reading the site in.
- Scripts and measurement screen: third-party snippets, each with a place in the
  document, the consent vendor it waits for, a note and an on/off switch. A
  snippet naming a vendor is written into the page inert and started only once
  the visitor has agreed to that vendor.
- Axeptio and Google Consent Mode v2: the defaults are emitted denied at the top
  of the head before any tag runs, the SDK loads next, and what the visitor
  agrees to both updates the Google signals and releases the snippets of that
  vendor.
- A bin that empties itself: pages deleted more than `trash_retention_days` ago
  (30 by default) go for good with their content, their revisions, their search
  entries and their addresses, either from `thelia_cms:pages:purge-trash` or
  along with `maintenance:purge`. The bin screen shows when each page is due to
  go.
- A sent form is reported to the data layer as `generate_lead`, carrying the
  code of the form and nothing else, and only when the person agreed to be
  contacted.
- `thelia_cms:export` and `thelia_cms:import`: the page tree, the content of
  every language, the menus, the forms, the reusable blocks and the settings, as
  one versioned JSON file. Images travel as file names and are matched against
  the media library of the importing site. Submissions, third-party snippets and
  revisions are left out. An import leaves alone what is already there unless
  `--replace` is passed, applies the settings only with `--with-settings`, and
  runs in a single transaction.
- Templates screen: keep a page aside as a starting point, and start a page from
  one. A page made from a template is a hidden draft and opens straight in the
  editor. A template holds the export document of its page, so it can be handed
  to another site as a file.
- `cms_site_icon()` and the `/site-icon` route: a theme shows the icon uploaded
  in Configuration > Store, which Thelia otherwise keeps out of reach of the
  front office, and falls back on its own file when none is configured.
- A page for putting a shared cache in front of the site (`docs/shared-cache.md`):
  the VCL that drops the session cookie on public addresses, without which no
  page is ever cacheable, the equivalent for Fastly and Cloudflare, and a purger
  to copy into a project.
- A printable guide of the module in French, written for whoever runs the site
  rather than for a developer, with a shot of every screen
  (`docs/guide/thelia-cms-guide-webmaster.pdf`).
- Unit test suite runnable without a database or an installed shop, an
  integration suite over the export and import round trip, the search index, the
  sitemap, the dashboard and the breadcrumb, and an axe-core scan of the pages a
  visitor reads.

### Fixed

- The TntSearch index was never registered: the guard around it named a class in
  the module's own namespace, so it was false on every site.
- The breadcrumb of a page filed under a draft one offered a link to that page,
  which answers 404, both to the reader and in the `BreadcrumbList`.

[Unreleased]: https://github.com/thelia-modules/TheliaCMS/compare/2.12.0...HEAD
[2.12.0]: https://github.com/thelia-modules/TheliaCMS/releases/tag/2.12.0
[2.11.0]: https://github.com/thelia-modules/TheliaCMS/releases/tag/2.11.0
[2.10.0]: https://github.com/thelia-modules/TheliaCMS/releases/tag/2.10.0
[2.9.0]: https://github.com/thelia-modules/TheliaCMS/releases/tag/2.9.0
[2.8.4]: https://github.com/thelia-modules/TheliaCMS/releases/tag/2.8.4
[2.8.3]: https://github.com/thelia-modules/TheliaCMS/releases/tag/2.8.3
[2.8.2]: https://github.com/thelia-modules/TheliaCMS/releases/tag/2.8.2
[2.8.1]: https://github.com/thelia-modules/TheliaCMS/releases/tag/2.8.1
[2.8.0]: https://github.com/thelia-modules/TheliaCMS/releases/tag/2.8.0
[2.7.1]: https://github.com/thelia-modules/TheliaCMS/releases/tag/2.7.1
[2.7.0]: https://github.com/thelia-modules/TheliaCMS/releases/tag/2.7.0
[2.6.3]: https://github.com/thelia-modules/TheliaCMS/releases/tag/2.6.3
[2.6.2]: https://github.com/thelia-modules/TheliaCMS/releases/tag/2.6.2
[2.6.1]: https://github.com/thelia-modules/TheliaCMS/releases/tag/2.6.1
[2.6.0]: https://github.com/thelia-modules/TheliaCMS/releases/tag/2.6.0
[2.5.0]: https://github.com/thelia-modules/TheliaCMS/releases/tag/2.5.0
[2.4.0]: https://github.com/thelia-modules/TheliaCMS/releases/tag/2.4.0
[1.6.2]: https://github.com/thelia-modules/TheliaCMS/releases/tag/1.6.2
[1.6.1]: https://github.com/thelia-modules/TheliaCMS/releases/tag/1.6.1
[1.6.0]: https://github.com/thelia-modules/TheliaCMS/releases/tag/1.6.0
[1.5.0]: https://github.com/thelia-modules/TheliaCMS/releases/tag/1.5.0
[1.0.0]: https://github.com/thelia-modules/TheliaCMS/releases/tag/1.0.0
[1.0.0-alpha.1]: https://github.com/thelia-modules/TheliaCMS/releases/tag/1.0.0-alpha.1
