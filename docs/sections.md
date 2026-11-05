# Sections

A page of the CMS is a **document**: an ordered list of sections, each a
component of the catalogue with the values of its props. The document is what
is stored and edited; the HTML next to it in the database is its rendering,
produced when the page is saved and again when it is published.

```json
{
  "version": 1,
  "sections": [
    {"id": "head", "component": "Layouts:Subheader:Base", "props": {"title": "Des studios photo à louer"}},
    {"id": "intro", "component": "cms:richtext", "props": {"html": "<p>Trois plateaux, à l’heure ou à la journée.</p>"}},
    {"id": "contact", "component": "Partial:cms-form", "props": {"form": 1}}
  ]
}
```

A section names a component by the name the catalogue knows it under, carries
an id of its own (lowercase letters, digits, hyphens and underscores) so a
later change can replace it alone, and holds props the component accepts. A
document whose sections name no component of the catalogue, or carry props a
component refuses, is rejected on the way in, every problem listed at once
(`TheliaCMS\Content\InvalidDocumentException`).

## The catalogue

`TheliaCMS\Content\SectionCatalog` holds every component a document may use:

| Family | Names | Where they come from |
|---|---|---|
| Free text | `cms:richtext` | The module. A piece of markup as is, filtered on the rendered page like everything that goes online. |
| Columns | `cms:columns` | The module. Two to four columns side by side, each an image (of the media library or an `https` address) over an optional heading and a short text, drawn with the grid of `blocks.css` and stacked below 48rem. |
| Highlights | `cms:highlights` | The module. A band of one to three blocks, the first one the large one, each a picture under a title, a catchphrase and a button, with an optional picture for a screen narrower than 1024px (see [Highlights](#highlights)). |
| Image | `cms:image` | The module. One picture (of the media library or an `https` address) with an optional caption, at the width of the reading column (by default), of the content, of the whole screen or at a size in pixels set by the editor, with an optional frame and the part of the picture kept (see [The size and the framing of an image](#the-size-and-the-framing-of-an-image)); nothing is drawn while no image is chosen. |
| Live content | `Partial:<name>` | Every partial of the site (`PartialDefinitionInterface`), the ones of the module and the ones other modules register. The section renders the placeholder the partial renderer substitutes when the page is served. |
| Components of the theme | As the theme names them, `Molecules:Reinsurance:Base` | The components the active theme opens to pages in `config/theliacms.yaml` (below). |
| Components of the site | `Site:<Code>`, `Site:PricingPlans` | Made in the back office from a prompt and kept (**CMS > Components > New component**, or prototyped from the content screen of a page and kept for the whole site later): props as data, a sandboxed Twig template and a scoped stylesheet, stored in `cms_component` ([creating-a-component.md](creating-a-component.md#a-component-made-in-the-back-office)). |

A component of another module joins the catalogue by implementing
`TheliaCMS\Content\SectionDefinitionInterface`; implementing it is what
registers it. Several at once, decided at runtime, go through
`SectionProviderInterface`.

Two readings of it. `all()` is every component a stored document may name:
what renders and validates a page. `offered()` leaves out the components the
site switched off under **CMS > Components** (`SectionAvailability`) and the
prototypes (a component of the site made from a page and not kept for the
whole site yet, `SiteComponentSection::$prototype`): what the generator reads
and may write, and what the toolkit shows. A generation that
revises or translates a page is also allowed the components that page already
uses (`GenerationRequest::keptComponents()`), so a component switched off
never forces a page to change.

## Highlights

`cms:highlights` is the band a shop puts under its header: one to three blocks,
in the structure of the HeaderHighlights module (a large first block, shorter
ones next to it), drawn by the module under `cms-highlights…` classes of
`assets/blocks.css`. A block, in `blocks`:

| Prop | Holds |
|---|---|
| `title`, `text` | the title (80 characters) and the catchphrase (160), plain text, none when empty |
| `button_label`, `button_url` | the words and the address of the button (a path of the site, an `https`, mailto or tel address); the button is drawn only when both are set |
| `image`, `image_alt` | the picture of a wide screen (media library or `https` address) and, for a picture kept elsewhere, its text |
| `image_mobile` | optional: the picture drawn instead on a screen narrower than 1024px |
| `focus_x`, `focus_y` | editor only (the generator is not offered them): the part of the picture kept when the block crops it, as an `object-position` |

Zero blocks and four are refused; every prop of a block is optional.

The markup is `<ul class="cms-highlights cms-highlights--N">` with one
`<li class="cms-highlights__item">` per block (`--lead` on the first). With an
`image_mobile`, the picture is a `<picture>` whose `<source media="(max-width: 1023px)">`
holds the mobile picture and whose `<img>` holds the one of the computer; without
one, the `<img>` is alone, and the publication wraps it in its own `<picture>` with a
WebP alternative and a set of widths like any image of a page. The `<source>` of the
mobile picture gets its own set of widths on publication, and the `sizes` of the `<img>`;
no WebP alternative is added to it. The blocks are stacked below 48rem (the breakpoint of the picture is 1024px,
the one HeaderHighlights uses, so a tablet gets the mobile picture in a side-by-side
layout). The text sits over the lower part of the picture, on a darkened foot.

## Props

A component describes its props with `TheliaCMS\Content\Prop`, and the schema
(`PropSchema`) is what checks the values, describes them to whoever writes a
document, and becomes the JSON schema a generator is constrained with.

| Type | Holds | Checked |
|---|---|---|
| `text` | a line of plain text, printed escaped | length |
| `html` | markup, printed as is, filtered by the writer on the rendered page | that it is a string; a `<h1>` becomes a `<h2>`, attributes kept |
| `integer` | a whole number | range |
| `boolean` | true or false | |
| `choice` | one of a closed list | membership |
| `image` | the id of an image of the media library, `null` or `0` for none yet | a positive id |
| `url` | a path of the site, an absolute http(s), mailto or tel address | nothing else, `javascript:` first of all |
| `items` | a list of objects, each shaped by item props | count, and each item |
| `reference` | the id of an item of the site: a page, a menu, a form, a product | a positive id |
| `json` | a list or an object the component does not describe further | that it is one |

The editor of a `html` prop (Tiptap, when installed) offers a reduced toolbar:
undo and redo, bold, italic, underline, headings, paragraph, both lists, link
and unlink, with headings limited to h2 to h4. There is no image, no HTML
source, no preview and no h1: the title of the page is the page's, an image
belongs to an image section, and markup a writer cannot see is how a page
breaks. The toolbar and the levels are set per field by the attributes
`data-tiptap-toolbar` and `data-tiptap-headings`, which need Tiptap 1.1.0;
before that version Tiptap ignores them and its global toolbar applies. The
server does not depend on it: whatever way a `html` prop is written (by hand,
generated, imported), a `<h1>` is stored as a `<h2>` with its attributes, and an
image already in a text stays.

Unknown props are dropped, missing ones get their default, wrong ones are
refused.

## Appearance

Any section may carry an `appearance` next to its props, whatever its
component (`TheliaCMS\Content\SectionAppearance`):

```json
{"id": "selection", "component": "Layouts:CrossSelling:Base", "props": {"…": "…"}, "appearance": {"background": "#f8d7d7", "padding": "medium"}}
```

| Setting | Values | Style written |
|---------|--------|---------------|
| `background` | `#rrggbb` | `background-color` |
| `text` | `#rrggbb` | `color` |
| `padding` | `small`, `medium`, `large`, or a length | `padding-block` of 1.5, 3 or 5 rem, or of the length |
| `padding_top`, `padding_bottom` | a length | `padding-top`, `padding-bottom`, after `padding-block`: a side that is set wins for its side |
| `padding_left`, `padding_right` | a length | `padding-left`, `padding-right` |
| `margin_top`, `margin_bottom` | a length | `margin-top`, `margin-bottom` (outside the band, its background does not follow) |
| `margin_left`, `margin_right` | a length | `margin-left`, `margin-right`: the band is narrower |

A side on the left or the right, inside or outside, is written
`max(min(<length>, 5vw), min((100vw - 48rem) * 1000, <length>))`: the length as
set from 48rem up, 5% of the screen at most below, so that a phone keeps room for
the content. In the style attribute itself, as no media query reaches it and no
rule of the theme outweighs it.
| `align` | `left`, `center`, `right` | `text-align` |

A length is a number of two decimals at most followed by `px` (0 to 320) or
`rem` (0 to 20), such as `64px` or `2rem`: no `calc()`, `var()`, negative
number, bare number or other unit. The check of the appearance is the only
barrier, the CSS filter of the rendering does not bound a length. The
generator's schema and catalogue sheet list the three presets only; a precise
room is the editor's: "Edit by hand" shows its eight fields under the room
button "Precise room" only, and another button leaves them out of the save. A
site without this release refuses an imported page
whose document holds one.

Every setting is optional, and nothing else can be set: an unknown setting or
a value outside the list rejects the document like a wrong prop. A section
with an appearance renders inside a band of the whole width,
`<div class="cms-section-styled" data-cms-section="…" style="…">`, the
component unchanged inside it; a section without one renders, and is stored,
without it. The generator sets the appearance when a brief asks for a look in
words; "Edit by hand" sets it in its **Appearance** group, a colour typed or
picked. A translation takes the appearance of the source section by section:
it changes the words, not the look.

### What a theme does with the band

The band, not the component, is then the child of the content
(`.cms-page-content`), and it spans the whole width on purpose: the first
element of a component is often its container, narrower than the page, and a
background set there would be a box rather than a band. The blocks of the
catalogue set their own width and are not affected. A rule of the theme that
reaches the sections through the content does not reach a section in a band,
so it has to name the band too:

- a width or a margin set on a component as a child of the content: target it
  under `.cms-section-styled` as well, so the background keeps the whole width
  and the component inside it the width of the page;

  ```css
  /* Before: the steps span the whole screen once given a background. */
  .cms-page-content > .cms-site--steps {
    width: min(75rem, 100% - 2rem);
    margin-inline: auto;
  }

  /* After */
  :is(.cms-page-content, .cms-section-styled) > .cms-site--steps {
    width: min(75rem, 100% - 2rem);
    margin-inline: auto;
  }
  ```

- sections laid out next to each other, cards in a grid or a row: the band is
  the item of the grid or of the row in place of the card, so a rule on the
  items names it as well, or the card with an appearance leaves the grid. Its
  background then fills the cell of the card, not a band across the page.

  ```css
  .cms-page-content > :is(.cms-site--card, .cms-section-styled:has(> .cms-site--card)) {
    grid-column: span 4;
  }
  ```

The module wraps the document in a `div.cms-page-content` of its own, inside
the `article.cms-page-content` of the page template: a rule written
`.cms-page-content > *` reaches that inner container as well, and narrows the
band with it. Name the components rather than every child.

The band carries the section's id in `data-cms-section`, as the first element
of a section without an appearance does; a script of the theme that looks a
section up by its id finds the band.

## The size and the framing of an image

`cms:image` has the three widths of `blocks.css` (`narrow`, `column`, `full`) and a
fourth, `custom`, which the editor alone sets. The props after `width` are for the
editor too: they are `editorOnly` (`Prop::forEditor()`), so the generator is not
offered them, the sheet of the catalogue does not list them and `custom` is out of
the `width` enum it is constrained with.

| Prop | Meaning | Bounds |
|---|---|---|
| `width_px` | width of the box, with `width = custom` | 0, or 160 to 2400 |
| `height_px` | height of the box; 0 keeps the natural height of the picture | 0, or 80 to 2000; the box between 1:4 and 4:1 |
| `fit` | `cover` (fill the box, crop the rest) or `contain` (the whole picture) | read with both sizes set |
| `focus_x`, `focus_y` | centre of the part kept, in percent of the picture | 0 to 100 |
| `zoom` | 100 shows the largest part of the box's proportions, 200 half its width and height | 100 to 400, by steps of 10 |

The box keeps its ratio as the screen narrows (`aspect-ratio`, `width: min(Wpx, 100% - 2rem)`),
so the part kept is the same at every width. A section saved before these props
renders the same markup, byte for byte.

For a picture of the media library the box is filled from a zone of the file:
`ImageFrame::zone()` turns the focus and the zoom into `x,y,w,h` pixels of the
original, and the publication asks the library for that region
(`/image-library/{id}/{x},{y},{w},{h}/{size}/...`). The section writes the kept part as
`data-cms-crop` on the `<img>` (read, then taken out, like `data-cms-sizes`); the
`srcset` candidates are narrower than the zone, the last one is the zone at its own
size, and `width` and `height` are those of the zone. A zone is never outside the
file and never upscaled. The same arithmetic is in `image-crop.js`, so the dialog
shows what the server serves. An address kept elsewhere is framed by CSS alone
(`object-fit`, `object-position` from the focus); its zoom cannot be honoured, and
the dialog does not offer it. A picture shown whole (`contain`) is served as before.

### Pictures of the components of the theme and of the site

A component whose markup is not the module's (`ThemeComponentSection`, `SiteComponentSection`)
prints the address of its pictures itself, so the module cannot write `data-cms-crop` or a
`style` on its `<img>`. `ImageFraming` (a service the two providers take) adds, after each
`image` prop of the schema (at the top level and in the rows of a list), four editor-only props
marked `editor = framing`: `{image}__proportions` (`original` by default, or a ratio of
`ImageProportions`), `{image}__focus_x`, `{image}__focus_y` (0 to 100, 50) and `{image}__zoom`
(100 to 400, by steps of 10, checked by `normalizeProps()`). The double underscore is a name the
components do not spell, and a picture whose four names a real prop already holds stays unframed.

At render time the component is given none of them: `ImageSource::framed()` turns the picture into
the address of the zone `ImageFrame::zone()` computes from the size the library holds
(`/image-library/{id}/{x},{y},{w},{h}/max/0/default.{ext}`), or into the address `of()` gave
before when nothing is set (a component nobody framed draws the same bytes), when the file has
no measured size, or when the zone is the whole file. A picture kept elsewhere is passed as it
is. The rewriter then reads the region of the address of an `<img>` of the library
(`ImageFrame::region()`, four integers, inside the file, not the whole file; anything else is
ignored and the picture served whole, `src` included) and serves the zone as it does for
`data-cms-crop`, which wins if both are there.

In the form (`content-editor.js`, `bindPictureFraming`), each picture gets its proportions under
it, then the sentence that says the kept part and the button that opens the dialog, per picture
and per row (also for a row added without reloading). The dialog knows the shape asked for,
not the box of the component: it keeps its own CSS box, and a box of another shape is cropped
again by the theme. For a picture kept elsewhere the button is off, with the reason. The
generator never sees the props (`EditorValues`); a rewrite gives each picture back its framing
when the component and that picture are unchanged.

### Proportions, for the three widths and for the columns

`proportions` (editor-only choice of `cms:image`, after `width`): `original` (default),
`16:9`, `4:3`, `3:2`, `1:1`, `3:4`. A ratio writes, on the `<img>` of a preset width,
`aspect-ratio`, `object-fit: cover`, `object-position` and `data-cms-crop` with a box of
that shape taken from `ImageProportions` (1600x900, 1600x1200, 1500x1000, 1000x1000,
1200x1600: inside the bounds of the box above). `original` writes nothing, so the markup
is the one of before, except with a zoom over 100: `data-cms-crop="x,y,zoom,0,0"`, a box of
`0,0` meaning the proportions of the picture itself (`ImageFrame::zone()` then uses the
size of the original, `parse()` accepts it), and the rewriter serves the zoomed zone.
With `width = custom` the proportions are ignored (the box is width x height) and the form
hides them. The dialog is offered for every preset width; with a size in pixels, as before,
only with a height and the fill.

`cms:columns` has the same choice at the top level of its props, with `band` in
place of the default `original`. `band` is the strip of 5 rem of before (`data-cms-height`
on the section, no framing). Any other value drops `data-cms-height`, gives each image the
class `cms-columns__image--framed` (the width of its column, `height: auto`) and, for a
ratio, the box of the rows above; `sizes` stays that of the column. Each row of the list has
its own `focus_x`, `focus_y` and `zoom` (editor-only, in the row, zoom by steps of 10 checked
by `ColumnsSection::normalizeProps()`). `Generation/EditorValues` hides the editor-only props
of the rows from the model and puts them back row by row (same position, same component, same
picture), the defaults otherwise.

A published page keeps the files it was published with: after the picture is
replaced in the library, or after a change of the module, `thelia_cms:render-published`
renders what is online again (README, "Rendering what is online again").

The generator never sees these props: the draft it is shown leaves them out and
reads a custom width as the default one (`Generation/EditorValues::forModel()`), and
an answer that replaces a section gets them back from the section it replaces when
the component and the image are the same (`restore()`), the model's own different
preset width winning over the custom one. Translation copies them from the source.

## The width of the images

A page is published with a `srcset` and a `sizes` on every image of the media
library (`Content/Pipeline/ImageRewriter.php`, once, when the page goes
online). The browser picks the file from `sizes`, which says how wide the image
is drawn, and only the section knows that: the publication sees the final
markup and nothing of the layout. A section therefore declares it, with an
attribute on the element that holds its images:

```html
<figure class="cms-image cms-image--narrow"
        data-cms-sizes="(max-width: 50rem) calc(100vw - 2rem), 48rem">
```

- `data-cms-sizes` is the value of `sizes` as the browser reads it: a list of
  `(media condition) width` entries, then a default width, in `px`, `rem`, `em`,
  `vw` or `vh`, with `calc()`, `min()`, `max()` and `clamp()`. The conditions
  are on `min-width` and `max-width`. Anything else (a `url()`, a `var()`, a
  quote, a percentage, unbalanced parentheses, over 300 characters) is not a
  width and is ignored.
- An image takes the value of the nearest element above it that carries the
  attribute, itself included; an invalid value counts as none and the elements
  further up are not asked.
- The attribute is taken out of the published page. It stays in a draft or a
  preview, where it does nothing.
- An image under no declaration keeps the default: the viewport, up to the width
  of its file and 1440px at most (`(max-width: 1440px) 100vw, 1440px` for a
  file of 1440px or more). Free text, and a component that declares nothing, are
  in that case.
- `TheliaCMS\Content\ImageSlot` writes the values of the sections of the module
  (`narrow()`, `content()`, `screen()`, `column($count)`) and says whether a
  value is acceptable. They follow the widths of `assets/blocks.css`: change one
  and the other.

Declare it for the width the image really has on the page, not for the width of
the block when the image is smaller.

An image drawn at a fixed height, as the logos of the columns are (a band
`5rem` high, as wide as the proportions of the file make it), says so with a
second attribute on the same element, `data-cms-height="5rem"` (a plain length
in `px`, `rem` or `em`). The publication knows the proportions of the file: it
holds each width of `data-cms-sizes` to the width that height gives, as
`min(<width>, calc(5rem * <width of the file / its height>))`. Read the same
way as `data-cms-sizes` (the nearest element), ignored when it is not a length
or when the library does not know the proportions, and taken out of the
published page. `ImageSlot::columnBand()` is the one of the columns.

## Writing a section of your own

The first way is a component of the theme, opened in one line of
`config/theliacms.yaml` (below). A module that has to compute what it shows
writes a partial (`PartialDefinitionInterface`), or a class implementing
`SectionDefinitionInterface`:

```php
final readonly class OpeningHoursSection implements SectionDefinitionInterface
{
    public const string NAME = 'MyModule:OpeningHours';

    public function __construct(private Environment $twig) {}

    public function name(): string { return self::NAME; }
    public function label(): string { return 'Opening hours'; }

    public function description(): string
    {
        return 'The opening hours of the shop, one line per day. Use it on a contact or access page.';
    }

    public function schema(): PropSchema
    {
        return new PropSchema([
            Prop::text('title', 'Heading', required: true, max: 120),
            Prop::items('days', 'Days', [
                Prop::text('day', 'Day', required: true, max: 20),
                Prop::text('hours', 'Hours', required: true, max: 40),
            ], min: 1, max: 7),
        ]);
    }

    public function sample(): array
    {
        return ['title' => 'Opening hours', 'days' => [['day' => 'Monday', 'hours' => '9:00 – 18:00']]];
    }

    public function normalizeProps(array $props): array
    {
        return $this->schema()->normalize($props);
    }

    public function render(array $props, string $locale): string
    {
        return $this->twig->render('@MyModule/opening-hours.html.twig', ['props' => $props, 'locale' => $locale]);
    }
}
```

`description()` is read by a generator to pick the section: say what it is
for and when to use it. `sample()` is what the toolkit renders and the example
a generator is shown: realistic, complete, in the language of the back office.

## Opening the components of the theme

A theme built on Twig components (Flexy, or a child of it) opens some of them
to the pages of the CMS in `config/theliacms.yaml`:

```yaml
sections:
    components:
        - Molecules:Reinsurance:Base
        - Organisms:ArticleCard:Base
```

Nothing is opened by default: a cart line or a checkout step makes no sense on
a page, and the theme is the one that knows which components do. A child theme
inherits the list of its parent unless it declares its own.

The props of a component are read off its template: the `{# @prop name type
description #}` comments at the top give the type and the meaning, the
`{% props name = default, … %}` tag the defaults. A prop declared without a
default and not documented as nullable is required, and so is a record or a
picture the class of the component takes (documented, not declared) unless
its type says `|null`. A picture is stored as the id of the media library
and reaches the component as its address; a prop documented `html` is
markup, filtered by the writer on the rendered page. `camelCase` in the
template is `snake_case` in a document, and goes back to the component under
its own name. A `{# @description … #}` comment, when the template has one, is
what a generator reads about the component. Slots (`{% block %}`) are not
supported: a component whose content goes through a block cannot be filled
from a document.

The same file names the classes the theme styles written text with, which
free text sections (`cms:richtext`) then carry next to `cms-richtext`:

```yaml
sections:
    richtext_class: wysiwyg wysiwyg-theme
    components: []
```

Flexy styles its text with Tailwind Typography under the name `wysiwyg`:
without the class, its reset leaves the lists of a free text without bullets
or numbers and its headings the size of a paragraph. The class is written
when a page is rendered, at its save: a page saved before the theme named it
keeps its old rendering until it is saved or published again, or until
`thelia_cms:render` renders its draft again.

The same file may give a component the props the toolkit shows it with,
which the generator is shown and a section added by hand starts from:

```yaml
sections:
    examples:
        Layouts:Banner:Base:
            title: Fresh from the farm
            image: images/illustrations/banner.webp
```

A picture prop of an example may be the path of a file of the theme, written
as `asset()` takes it (relative, with no scheme and no leading slash). The
toolkit draws it from the address the theme serves it from, so the "All
sections" page shows real pictures. A document stores only the id of an image
of the media library or an https address: the generator and a section added
by hand never see the path and start with the picture empty. An id or an
https address given in an example is kept as it is. Pictures nested in a
`json` prop are not resolved.

A component that wires its behaviour with Stimulus (a carousel, tabs) keeps
it once published only if the theme names its controllers:

```yaml
sections:
    components:
        - Organisms:Carousel:Base
    controllers:
        - Organisms--Carousel--base
```

The filter keeps the inline svg icons a component draws (`ux_icon()`): their
shapes, geometry and paint, without `use`, `image`, `foreignObject`, `a`,
`animate*`, handlers or a `url(…)` paint. The sanitizer writes `viewBox` as
`viewbox`, which a browser reads back inside an `svg`.

The filter a page goes through before it goes online drops every
`data-` attribute it does not know. For the controllers named here it keeps
`data-controller`, `data-action` and the `data-<identifier>-target`,
`-value`, `-param`, `-class` and `-outlet` attributes; in `data-controller`
and `data-action`, a controller the theme does not name is dropped, so that
written text cannot wire one the theme never opened to pages. Free text can
still use a controller named here, with values of its own: name only the
controllers that treat their values as data, never as markup or an address
to load. A page published before the theme named a controller is rendered
again by `thelia_cms:render`, then published.

## From a template

`cms_section(name, props)` renders a section anywhere in a theme, with its
sample props when none are given: `{{ cms_section('cms:richtext', {html: '…'}) }}`.
An unknown section renders nothing. It is also how the page of the toolkit of
the Flexy theme (`/toolkit/cms-all-sections`, in debug or with `THEME_TOOLKIT`) shows every section.
