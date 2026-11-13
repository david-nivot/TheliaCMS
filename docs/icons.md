# Icons

An icon is an SVG of the media library that was given a name. The module draws
it through ux-icons as `ux_icon('cms:<name>')`, and a section stores its name in
an `icon` prop. The decision is
[ADR 14](adr/0014-an-icon-is-a-named-svg-of-the-library.md). What an editor does
with icons (adding one, the name, replacing, deleting, choosing one in a section)
is written in **CMS > Documentation**, sections "Icons" and "Choosing an icon in
a section" (`templates/backOffice/default-twig/documentation/`).

## Code

- `Media/Icon/`: `IconName` (the name rules), `IconNames` (the `cms_icon` table,
  read through a join on `library_image`), `CmsIconRegistry` (the ux-icons
  source), `IconEligibility` (may an SVG be an icon), `IconNameSuggestion`,
  `IconCitations` (pages, items and components of the site that cite a
  name, a page in the trash not counted), `IconCacheInvalidator`, `ThemeIcons`.
- The "Icon" usage is on the **Prepare the SVG** screen
  (`Media/Admin/SvgPreparationController`, `media/prepare.html.twig`), offered
  for a file added to the library only. Renaming and removing a name are on the
  sheet of the image (`Media/Admin/CmsMediaAdminController`), refused while a
  page, an item or a component of the site cites the icon.
- Table `cms_icon` (`name` unique, `image_id` unique), created by
  `Config/update/2.27.0.sql`, also in `Config/TheliaMain.sql`. No foreign key to
  `library_image` (ADR 14): a row whose image is gone is ignored when read.
- The source of ux-icons has priority 0, under the local sources of the theme
  and above Iconify (-10). An unknown `cms:` name draws an empty icon and logs
  one warning; it does not throw.

## What a file must hold

`IconEligibility` reads `PublishedContentSanitizer::SVG_ELEMENTS` and does not
copy it: `svg`, `g`, `path`, `circle`, `ellipse`, `rect`, `line`, `polyline`,
`polygon`, with the attributes the filter lists, and no `fill` or `stroke` that
points to a gradient or a pattern. Anything else is returned as an obstacle in
an editor's words. The filter is not widened for icons.

## The `icon` prop

In a component of the theme, declare it in the comment at the top of the
template:

```twig
{# @prop icon icon The pictogram drawn before the title #}
```

The value is a ux-icons name: `cms:<name>` for a library icon, or the name of an
icon of the theme (`set:name` for a sub-directory of `assets/icons`). It is
validated on its shape only, never on the existence of the icon, so a deleted
icon invalidates no stored document. When the section is drawn, a name that is
not an icon of the site reaches a component of the theme as an empty string,
with a warning in the log: `ux_icon()` never receives a name it would ask
Iconify for, or throw on (`ThemeComponentSection::draw`). Print it with
`cms_icon()` in a component of the site.

The form of a section shows a button that opens a grid of previews
(`bindIconPickers` in `templates/backOffice/default-twig/assets/content-editor.js`),
fed by `admin.cms.partials.sources.icons`: the icons of the theme and the ones of
the library, searched by name. The previews are `<img>` elements carrying a data
URI, so no SVG of the library is injected in the back office. "No icon" empties
the field and is offered only when the prop is optional.

## Drawing an icon in a component of the site

`cms_icon(reference, attributes)` is the one icon function of the sandbox of the
components of the site (`ux_icon` is not exposed there):

```twig
{{ cms_icon(icon, {class: 'size-6', 'aria-hidden': 'true'}) }}
```

Only a known name is drawn (a library icon, or an icon of the theme), through
the chain of ux-icons. Any other value, a name taken away since included, draws
nothing and logs a warning. Attributes allowed: `class`, `aria-label`,
`aria-hidden`, `width`, `height`, `role`; the others are dropped. The story "CMS
icons" of the toolkit (`templates/front/sections/toolkit/icons.html.twig`) shows
it.

Outside the sandbox, a template or a screen lists the names with
`cms_theme_icons()` (theme) and `cms_library_icons()` (library, prefixed with
`cms:`).

## The generator

When a section of the catalogue has an `icon` prop, its card gives the model one
list of the names that may be used (`Generation/CatalogSheet.php`): the CMS
icons first, then those of the theme, at most 200 names, the number left out
said. The list sits in the cached prefix of every prompt, hence the ceiling.
A generated page that cites an unknown icon is sent back for correction with
the list (`DocumentProblem::ICON`); the value is emptied if it is still not
corrected. Known means the same thing everywhere (`Media/Icon/KnownIcons.php`):
a CMS icon whose image is still in the library, or an icon of the theme.

## What a page keeps

The HTML of a page is rendered when it is saved and again when it is published,
and stored. Replacing an icon changes the pages already published only at
their next publication (a cited icon cannot be deleted); `thelia_cms:render-published` renders what is
online again from the published documents without publishing anything new.
