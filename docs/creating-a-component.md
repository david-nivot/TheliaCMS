# Creating a component

A page is a document: an ordered list of sections, each naming a component of
the catalogue and carrying its props ([sections.md](sections.md)). The
catalogue is built from every class implementing
`TheliaCMS\Content\SectionDefinitionInterface` and from the providers that
bring several at once. There are three ways to add to it: open a Twig
component the theme already has, write a class in a module, or have the
generator make one in the back office, from **CMS > Components** or from the
content screen of a page.

## A component of the theme

A theme built on Twig components (Flexy, or a child of it) names the ones a
page may use under `sections.components` in its `config/theliacms.yaml`:

```yaml
sections:
    components:
        - Molecules:Reinsurance:Base
        - Organisms:ArticleCard:Base
```

A theme that opens a component of its own for something the module also
does puts the module's section away under `sections.hidden`, so the
generator is never asked to choose between the two:

```yaml
sections:
    components:
        - Organisms:ContactForm:Base
    hidden:
        - Partial:cms-form
```

A component is shown — in the toolkit, to the generator, and in a section
added by hand on the content screen — with its example: the defaults of its
props, the first rows of a list of products. A component whose defaults
render nothing, such as a video player with no video, gets props of its own
under `sections.examples`, written the way the component names them; the
defaults fill the rest, and a name the component does not take is ignored:

```yaml
sections:
    components:
        - Organisms:VideoPlayer:Base
    examples:
        Organisms:VideoPlayer:Base:
            alt: Big Buck Bunny, a short film of the Blender Foundation
            provider: youtube
            embedUrl: https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ
```

Nothing is opened until the theme says so. A name is two or more segments of
letters, digits and underscores joined by `:`, and is looked up as
`@Flexy/<segments>.html.twig` with the segments as directories, so
`Molecules:Reinsurance:Base` is `@Flexy/Molecules/Reinsurance/Base.html.twig`.
A child theme inherits the list of its parent unless it declares its own, and
the nearest declaration wins whole. The file is read at every request, so a
line added while working on the theme shows up at once; a name the theme chain
does not ship is logged and skipped rather than fatal. A list that is not a
list, or a name that is not a component name, refuses the whole file.

The props are read off the template (`Content/Theme/ComponentPropsReader.php`),
from two places:

- the `{% props name = default, other %}` tag gives the names and the
  defaults. Only a literal default is understood: `null`, `true`, `false`, a
  number, a quoted string, and `[` or `{` read as an empty list. An expression
  (a function call, a variable) reads as `null`, and the section leaves such a
  prop to the component when the document leaves it empty;
- one `{# @prop name type description #}` comment per prop gives the type and
  the meaning. `bool` or `boolean` is a boolean, `int` or `integer` an integer,
  `array`, `list`, `iterable`, `object` or any other `array<…>` a JSON prop
  (an editor types JSON by hand), a list of
  quoted alternatives (`'primary'|'secondary'`) a choice, `html` markup
  (handed to the component as it is, to print with `|raw`, and filtered by
  the writer on the rendered page), and anything else a line of
  text, 500 characters at most. A type naming `null` makes the prop optional.
- a list the editor fills row by row is documented with the shape of a row,
  type and spaces included: `list<{name: string, quantity: string, image:
  image|null}>` (`array<{…}>` reads the same). Each field is typed like a
  prop of its own (text, 500 characters at most, `html`, `int`, `bool`, quoted
  alternatives, `image`, `video`), is required unless its type says `|null`, and gets a
  field of the form. A list holds up to fifty rows unless it says otherwise
  after its type, each bound optional: `list<{title: string, video: video}>
  min:1 max:6 The videos`, `string[] max:20 The steps`. The editor offers no
  "Add a row" past the maximum and no removal under the minimum, and a save
  outside the bounds is refused; a list that needs a row is required. Bounds
  that make no sense (a maximum of 0, a minimum above the maximum) are left
  out. A field of a row gets a help of its own in a comment named after the
  list and the field, its type being the one the list gives it:
  `{# @prop videos.title The title read under the video #}`. Like the
  description of a prop, the help goes through the translator (`theliacms`
  domain). The component receives a list
  of rows, `item.name`, `item.image` (the address of the picture, as for an
  `image` prop; `item.video`, the address of the file, for a `video` field:
  `list<{title: string, video: video, image: image|null}>`), under the names the template spells: `imageAlt` is stored as
  `image_alt` and comes back as `item.imageAlt`. A field that a row cannot hold, a list or a record such as
  `product`, leaves the whole prop a JSON one. A list of plain strings
  (`list<string>`, `string[]`, and `list<html>`, `html[]` for markup) is one
  field per row, and stays a list of strings, `["one", "two"]`, in the
  document and in the component; a blank row is dropped. `product[]` and the
  other lists of records are not affected, see below.
- a prop may name what the site holds: `image` is a picture of the media
  library (its id, the path the library serves it from, such as
  `/image-library/17/full/max/0/default.webp`, or the `https://` address of a
  picture kept elsewhere),
  handed to the component as the address of the picture, to print in a
  `src`;
  `video` is a video of the media library, stored as its id (or the path
  the library serves it from, `/video-library/12.mp4`; never an `https://`
  address or a page of a video site) and handed to the component as the
  address of the file, `/video-library/12.mp4`, to print in a `src` or in the
  `data-` of a Stimulus controller of the theme. An id that is no video of the
  library (an image, a file gone) reaches the component as nothing, and the
  template decides to draw nothing; an image prop holding the id of a video
  does the same. Nothing is cut or resized, so no framing applies. The
  component draws one element per video, so the page loads nothing until the
  visitor plays one when the theme sets only the address and
  `preload="none"` on it (a `preload` or a `poster` of its own is the theme's
  to write; the module adds neither). The editor picks the video in the
  library, filtered on the videos, or sends an MP4 from the field;
  `product`, `category`, `folder`, `form` or `menu` is one record,
  picked by its id with the same pickers the partials use (the generator
  finds the id with its search tools); `product[]`, `category[]`, or
  `list<folder>`, is several of them, two to twelve, stored as rows of ids in
  the order they were picked and handed to the component as a list of ids,
  the way a template of the theme would pass them.
- a prop documented and not declared in the `{% props %}` tag is one the PHP
  class of the component takes in `mount()` (`productIds` of a product strip,
  for instance): it is read as optional, under the name the comment gives,
  except one record or one picture (`productId product` of a product card),
  which is required unless its type says `|null`.

A prop declared without a default and not documented as nullable is required.
A component with no `@prop` comment at all still works: every declared prop
is read as text. A `{# @description … #}` comment, when the template has one,
is what the generator reads about the component; without it, the sheet says
it is the component of the theme as used on the rest of the site. The
template spells `camelCase`, a document spells `snake_case` (`iconLeft` is
`icon_left`), and the section hands the value back under the name the
component declares. Slots (`{% block %}`) are not read: a component whose
content goes through a block cannot be filled from a document.

## A component of a module

The first way to add a component is the theme: a Twig component opened in
one line of `config/theliacms.yaml`, as above. A module that has to compute
what it shows writes a partial (`PartialDefinitionInterface`), which joins the
catalogue as `Partial:<name>`, or a class implementing
`SectionDefinitionInterface`. Such a class is a component the moment it is a
service of the container with autoconfiguration on, which is how a Thelia 3
module registers its services: the interface carries the tag. The example
below is complete:

```php
<?php

declare(strict_types=1);

namespace MyModule\Content\Section;

use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\SectionDefinitionInterface;
use Twig\Environment;

/**
 * The opening hours of the shop, one line per day.
 */
final readonly class OpeningHoursSection implements SectionDefinitionInterface
{
    public const string NAME = 'MyModule:OpeningHours';

    public function __construct(
        private Environment $twig,
        private TranslatorInterface $translator,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return $this->translator->trans('Opening hours', [], 'mymodule');
    }

    public function description(): string
    {
        return $this->translator->trans('The opening hours of the shop, one line per day. Use it on a contact or access page.', [], 'mymodule');
    }

    public function schema(): PropSchema
    {
        return new PropSchema([
            Prop::text('title', $this->translator->trans('Heading', [], 'mymodule'), required: true, max: 120),
            Prop::items('days', $this->translator->trans('Days', [], 'mymodule'), [
                Prop::text('day', $this->translator->trans('Day', [], 'mymodule'), required: true, max: 20),
                Prop::text('hours', $this->translator->trans('Hours', [], 'mymodule'), required: true, max: 40, help: $this->translator->trans('As displayed, for instance "9:00 – 18:00".', [], 'mymodule')),
            ], min: 1, max: 7),
            Prop::boolean('closed_on_holidays', $this->translator->trans('Closed on public holidays', [], 'mymodule'), true),
        ]);
    }

    public function sample(): array
    {
        return [
            'title' => 'Opening hours',
            'days' => [
                ['day' => 'Monday to Friday', 'hours' => '9:00 – 18:00'],
                ['day' => 'Saturday', 'hours' => '9:00 – 12:00'],
            ],
            'closed_on_holidays' => true,
        ];
    }

    public function normalizeProps(array $props): array
    {
        return $this->schema()->normalize($props);
    }

    public function render(array $props, string $locale): string
    {
        return $this->twig->render('@MyModuleModule/front/opening-hours.html.twig', [
            'props' => $props,
            'locale' => $locale,
        ]);
    }
}
```

What each method is for:

- `name()` is the name a document refers to the component by, stable across
  versions. Prefix it with the module. The first definition claiming a name
  wins, so a name already in the catalogue is ignored rather than replaced.
- `label()` is what a person reads in a list; `description()` is one or two
  sentences on what the component is for and when to use it, which is what the
  generator reads to pick it.
- `schema()` declares the props with the constructors of `Content/Prop.php`:
  `Prop::text` (length), `Prop::html` (markup, filtered on the rendered page),
  `Prop::integer` (range), `Prop::boolean`, `Prop::choice` (a closed list),
  `Prop::image` (an id of the media library), `Prop::video` (an id of a video of
  the media library), `Prop::url` (a path of the site
  or an absolute http(s), mailto or tel address), `Prop::items` (a list of
  objects, each shaped by its own props), `Prop::reference` (the id of a page,
  a menu, a form, a product) and `Prop::json`. A prop name is lowercase
  letters, digits and underscores.
- `sample()` shows the component at its best: it is what the toolkit renders
  and the example the generator is shown. It must pass the schema.
- `normalizeProps()` drops unknown props, gives missing ones their default and
  refuses wrong ones with every problem listed (`InvalidDocumentException`):
  `PropSchema::normalize()` does it all. Add to it only a rule the schema
  cannot say.
- `render()` receives the props already normalised. The Twig namespace of a
  module is its code followed by `Module`.

The template, at `templates/front/opening-hours.html.twig` of the module:

```twig
<section class="opening-hours">
    <h2>{{ props.title }}</h2>
    <dl>
        {% for item in props.days %}
            <dt>{{ item.day }}</dt>
            <dd>{{ item.hours }}</dd>
        {% endfor %}
    </dl>
</section>
```

Several components decided at runtime (one per row of a table, say) go
through `SectionProviderInterface` instead, the way the partials and the
components of the theme do.

## A component made in the back office

**CMS > Components > New component** asks the generator for a component in a
prompt ("a table of two to four plans side by side, one put forward"). The
answer is checked, drawn with its sample in the active theme, at the width of
a phone, a tablet or a screen, and changed by asking again until the editor
keeps it. Kept, it is `Site:<Code>` in the catalogue, like any other
component: offered to the generator of pages, added and edited by hand on the
content screen, on the toolkit page, switched off on the list.

A component is also **prototyped from a page**. On the content screen,
**Add a section > A new component** asks for one in the same words; the
answer is drawn in the preview in place of the draft, changed by asking
again, and kept with a section of it added at the end of the draft with its
sample text. When the generator setting "Let a brief make a component of
the site" is on (off by default), the generator writing a page may make one
itself, through the tool `make_component`, when none of the catalogue shows
what the brief asks. Either way the component is a **prototype**: status
`draft` in `cms_component`, in the catalogue (the pages holding it render,
edit and export it), offered to nobody else, neither to the generator nor
on the content screen of another page, until **CMS > Components** keeps it
for the whole site (**Keep for the whole site**, status `ready`). Prototyping
takes the same rights as **CMS > Components > New component**: the settings
and the custom code resources. An export carries the status of every
component, and an import keeps it.

A component is also **copied into a new one**, to vary from it (a dark
price table next to the light one). On the screen of a component,
**Copy into a new component** takes a code and a label: the copy holds the
template, the stylesheet, the props, the sample and the mark of provenance
of its source, and nothing calls the generator. It is a prototype, offered to
nobody until it is changed by **Ask a change** and kept, or kept for the
whole site. Its stylesheet is scoped to the class of its own code
(`cms-site--price-table-dark`), so changing one never changes the other. Its
history starts empty. Copying takes the same rights as making a component.
A component of the theme is not copied this way: it is rendered outside the
sandbox, and a component of the site made from it would be a new generation.

What the generator writes, and what the site checks
(`Content/Site/SiteComponentValidator.php`) before showing it:

- **A code**, PascalCase, which never changes once kept: documents name it.
- **Props**, as data: the JSON form of the constructors of `Prop`, read by
  `Content/Site/SitePropReader.php`. The types a template can print: text,
  html, integer, boolean, choice, image (its address reaches the template),
  video (the address of its file reaches the template, which draws it in a
  `<video>` or a link: a `data-` attribute is not kept on a component of the
  site), url, and items whose props are any of these but items.
- **A Twig template** rendered in a Twig of its own with the sandbox on
  (`Content/Site/SiteTemplateRenderer.php`): `if`, `for`, `set`, a few
  filters that shape text, `cycle`, `min`, `max`; no `range` (its loops go
  over its props), no more than two `for` inside each other, no include,
  extends or use, no macro, nothing of the site. It receives `props` alone. It holds no text of its own, so a
  component serves every language of the site, and no `script`, `style`,
  `iframe`, `form`, `on…` attribute or `javascript:`. What it draws is
  filtered like a page that goes online, wherever it is drawn: a draft, the
  preview, the toolkit. An element or an attribute that filter takes out
  (`input`, `select`, a `data-` attribute no controller of the theme reads, `data-cms-sizes` apart: see below) is
  refused when the component is made, in every branch of the template
  (`SiteComponentValidator::dropped()`): taken out silently, it left a
  component that showed and did nothing.
- **A stylesheet**, plain CSS, scoped to the component when it is served
  (`Content/Site/SiteCssScoper.php`): every selector goes under
  `.cms-site--<code>`, the class of the element the component is drawn in;
  `:scope` or `&` is that element, and only starts a selector (`:not(:scope)`
  or `& ~ p` would reach what is around it). Rules may sit in `@media`,
  `@supports`, `@container`; a `@keyframes` is named after the component
  (`cms-site--<code>--<name>`), and so is every `animation` of the stylesheet
  that uses it. A rule that sets an animation sits in
  `@media (prefers-reduced-motion: no-preference)`; the template sets none in
  a `style` attribute. Nothing else, `@import`
  and `@property` included. Nothing is loaded: no `url()`, no `image-set()`, no backslash
  escape; the pictures of a component are props. Colours come from the tokens of `blocks.css`
  (`--cms-color-*`), so a component follows the theme. The stylesheets of the
  kept components are served together at `/cms/site-components.css`, linked
  after the one of the blocks.
- **A sample** that fills every prop: what the preview, the toolkit and the
  generator of pages are shown.

Making, changing and keeping a component take the settings and the custom
code permissions. A new version of a kept component is refused while a page
holds props it no longer takes (`Content/Site/SiteComponentUsage.php`, every
section named, draft and published); keeping one draws the drafts using it
again, and the published pages change at their next publication. A component
a page holds cannot be deleted. The kept components travel with the export of
the site and are checked again on import.

## The width of the images of a component

Whichever way a component is made, a picture of the media library in its markup
is published with a `srcset` and a `sizes` (`Content/Pipeline/ImageRewriter.php`).
Without a word from the component, `sizes` says "the viewport, up to the width
of the file": a component that draws its pictures in a third of the page, or in
a fixed frame, has them downloaded larger than needed. It says so by putting
`data-cms-sizes` on the element that holds them, with the `sizes` the browser
should read:

```twig
<div class="cms-site cms-site--logo-wall" data-cms-sizes="(min-width: 64rem) 20vw, 50vw">
    {% for item in props.logos %}<img src="{{ item.image }}" alt="{{ item.name }}">{% endfor %}
</div>
```

- It applies to every image under the element; the nearest declaration wins, so
  one element can hold two kinds of picture.
- Only a list of widths is read (`px`, `rem`, `em`, `vw`, `vh`, `calc()`,
  `min()`, `max()`, `clamp()`, conditions on `min-width` and `max-width`): see
  "The width of the images" in `docs/sections.md`. The attribute is out of the
  page once published.
- It works the same in the three ways above: the sandbox of a component made in
  the back office does not look at attributes, and the filter that runs on every
  rendered page lets `data-cms-sizes` through. A component of the theme, or of a
  module, writes it in its own template.
- Pictures drawn at a fixed height (a row of logos in a band) add
  `data-cms-height="5rem"` on the same element: each width of the list is then
  held to what that height gives the picture, from its proportions.
- The component keeps its layout in its stylesheet; the width declared must follow
  it. A wrong value only makes the browser pick a file too small or too large.

## The text of the images of a component

A picture of the media library that the component prints with no `alt`, or an
empty one, is published with the description the library holds for it in the
language of the page (`Content/Pipeline/ImageRewriter.php`), or with an empty
text when the library marks it decorative. A component may thus leave the text
of an image to the library; a text it prints (`alt="{{ item.name }}"`) is kept
as it is, even where the library says otherwise.

## What the generator does with it

Every component is written into the system prompt as a sheet
(`Generation/CatalogSheet.php`): its name and label, its description, one line
per prop with its type, its constraints and its help, a `*` on the required
ones, and the sample as a complete JSON section whose id is derived from the
last segment of the name. The sheets are written in the same order at every
call so the prompt stays cacheable. The `read_section` tool hands the same
sheet back when the model asks for one component.

The answer of the model is constrained to the envelope of a document, with the
names of the catalogue as an enum; the props come back as JSON and go through
`DocumentValidator`, which calls `normalizeProps()` of each component. A
document that is refused is sent back to the model with every problem listed,
twice at most. The same validator runs on a section edited by hand and on an
import, so a rule written in the schema holds whoever wrote the document. How
the prompt is built and what the model may read: [generator.md](generator.md).

## Testing it

Without a shop, a unit test exercises the schema and the validator alone, the
way `Tests/Unit/Content/DocumentValidatorTest.php` does with
`Tests/Unit/Content/FakeSection.php`: build a `SectionCatalog` from the
definition, validate a `Document` holding a `Section` with the sample, assert
the normalised props, then assert that a wrong value is refused with the
problem named.

With a shop, an integration test extends `CmsIntegrationTestCase` and asks the
`SectionCatalog` for the section, as
`Tests/Integration/Content/Partial/PartialSectionTest.php` does for a partial:
the name is the one of the catalogue, the sample normalises to the declared
props, a required prop left out is refused, and `render()` on the normalised
sample, after `useAnInstalledFrontTheme()`, writes the markup expected. For a
component of the theme,
`Tests/Integration/Content/Theme/ThemeComponentSectionTest.php` shows how the
props are read off a template and how the rendering goes through the component
renderer.

```bash
vendor/bin/phpunit -c local/modules/TheliaCMS
vendor/bin/phpunit -c local/modules/TheliaCMS/phpunit.integration.xml.dist --filter OpeningHours
```
