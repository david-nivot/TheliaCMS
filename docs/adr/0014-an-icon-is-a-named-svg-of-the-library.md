# 14. An icon is a named SVG of the library

Date: 2026-11-10. Status: accepted.

## Context

Sections and components of the site draw icons with `ux_icon()`, which reads
the icons of the theme and, as a last resort, asks Iconify over the network.
An editor who owns a drawing of their own (a pictogram of the brand) had no
way to use it as an icon: the media library holds it as a picture, placed
with `<img>`, which does not follow the color of the text.

Three constraints shape the answer:

- The HTML of a page is rendered when the page is saved, passed through the
  publication filter (`PublishedContentSanitizer`) and stored. What is online
  is that stored HTML, not a rendering made at each request.
- The filter keeps of an inline SVG only a handful of shapes and their
  drawing attributes (`PublishedContentSanitizer::SVG_ELEMENTS`). It drops
  `defs`, gradients, masks, `use`, `style` and `id`.
- `library_image` belongs to TheliaLibrary. The module does not own that
  table, it is absent from the aggregated schema, and TheliaLibrary does not
  signal the deletion of an image.

## Decision

**An icon is an SVG of the media library that was given a name.** It is drawn
by `ux_icon('cms:<name>')`.

- The name is lowercase letters, digits and single hyphens, 64 characters at
  most. It is stored without the prefix in `cms_icon` (`Config/update/2.27.0.sql`),
  unique, and one image holds one name at most. The rule is the one of a slug
  of ux-icons, so a name that passes is a name the icon chain accepts
  (`Media/Icon/IconName`).
- **No foreign key to `library_image`.** The SQL of the module may be played
  when that table does not exist yet. A row whose image is gone is orphaned:
  it is ignored when read and gives its name back to the next image that asks
  for it. When the CMS deletes an image it deletes the row too; an image
  deleted from TheliaLibrary directly leaves a row that is ignored, not an
  error.
- **The module is a source of ux-icons at priority 0** (`CmsIconRegistry`):
  under the local sources of the theme, above Iconify (-10). A name outside
  `cms:` falls to the next source. An unknown `cms:` name draws an empty icon
  and writes one line in the log. It never throws: an exception climbs the
  chain to Iconify, and `ignore_not_found` is false by default, so the page
  would fail or the network would be asked.
- The cache of ux-icons is emptied for a name each time a write changes what
  that name draws: the name given, the file replaced, the name or the image
  removed (`IconCacheInvalidator`).
- **The HTML of a page keeps the icon it was published with.** `ux_icon()` is
  evaluated when the HTML is rendered, and the result is stored. Replacing an
  icon does not change the pages already published: they change at their next
  publication. Nothing republishes by itself; `thelia_cms:render-published`
  renders what is online again without publishing anything new. The media
  sheet and the screen that replaces the file say so. A cited icon cannot be
  deleted (see below), so no published page is left citing a deleted one.
- **The filter is not widened.** The "Icon" usage is offered on the
  **Prepare the SVG** screen only when the prepared file holds nothing but
  `svg`, `g`, `path`, `circle`, `ellipse`, `rect`, `line`, `polyline` and
  `polygon`, with their drawing attributes, and no paint that points to a
  gradient or a pattern. `IconEligibility` reads `SVG_ELEMENTS` itself, so the
  screen and the filter cannot drift apart. A file that does not qualify is
  told why in words of an editor and stays addable as an image.
- **A name cannot be renamed or removed while a page, an item or a component
  of the site cites it** (its template writing `cms_icon('cms:<name>')`): they
  would keep the old name and the icon would vanish from them. Pages
  in the trash do not count. Nor can the image be deleted then: the usage count
  of the library counts a citation of its name as a use, a draft included, as
  it counts the address of an image. Once no page or item cites it, deleting
  the image deletes the name.
- A prop of type icon stores a ux-icons name (`cms:<name>`, or an icon of the
  theme without prefix, `set:name` for a sub-directory). It is validated on its shape only, never on the
  existence of the icon: a deleted icon invalidates no stored document. When
  it is drawn, a name that is not an icon of the site is handed to a
  component of the theme as an empty string, so that `ux_icon()` never asks
  Iconify for it nor throws.
- The file of an icon is replaced only by an SVG, through the preparation
  screen that checks it can be one: a JPEG, PNG or WebP sent from the sheet
  is refused.

## Consequences

- An icon is a plain, flat drawing. A multi-color logo with gradients stays an
  image. That is a limit of the filter, kept on purpose.
- No `<style>` can leak onto the page from an icon, and two icons in a page
  cannot collide on an `id`: neither exists in what is kept.
- A page published before a change keeps the old drawing, or the old empty
  space when the icon was deleted. The editor learns it from the sheet of the
  media, and the fix is a new publication.
- A name is free again as soon as its image is gone, even when the row is
  still there: the reads join `library_image`, and giving a name first deletes
  the orphan row that holds it (`IconNames`).
- The icons of the module and the ones of the theme share one picker and one
  prop type ("No icon" empties an optional field); the picker previews with `<img>` carrying a data URI, so no SVG
  of the library is injected in the back office.
- `Config/update/2.27.0.sql` is replayable and also lives in `TheliaMain.sql`.
- An icon of the theme is "known" when its file is in `assets/icons` of the
  active front template or of its parents, read at runtime. ux-icons reads its
  local directories when the container is built. The two agree as long as the
  theme keeps its icons in `assets/icons` and the cache is cleared after the
  active template changes, as Thelia asks; otherwise a known name may not be
  found locally, and ux-icons would ask Iconify for it.
- The guarantee that no `<style>` reaches a page holds for the pages of the
  CMS, whose HTML goes through the publication filter. A file replaced outside
  the CMS (the TheliaLibrary API) and drawn by an `ux_icon('cms:…')` written by
  hand in a theme template is not filtered: it needs back-office rights, and
  no script survives the sanitizers.

## Alternatives rejected

- **Widen the filter and prefix the ids.** Allowing `defs`, gradients, masks,
  `use` and `id`, with every `id` rewritten per icon, would let any drawing
  through. It opens the filter to `style` and to references between elements,
  which is the part of an SVG that carries risk, and it changes what every
  inline SVG of every page may contain, for one feature.
- **Republish the pages that cite the icon.** It would keep pages up to date,
  but it publishes content nobody asked to publish: a draft change in review
  would go online with it. The decision is to warn, not to publish quietly.
- **A foreign key to `library_image`.** The table is another module's, absent
  from the aggregated schema and possibly not created when the SQL runs; the
  update would fail on a site where it works today. The orphan row costs
  nothing and is handled at read.
- **A name drawn from the id of the image, or carried by a tag.** An id says
  nothing to an editor and breaks when the library is imported elsewhere. A
  tag is shared, renamable and not unique: two images could answer to one
  name, and a tag edited for another reason would rename an icon under the
  pages that cite it.
