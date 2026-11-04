# TheliaCMS

A CMS for Thelia 3: a tree of pages, each written as a document of sections
from a brief, by hand or both, and published as plain HTML. A published page
ships no editing JavaScript.

## Compatibility

- Thelia 3.2 or later: the module answers the content slots and the
  back-office navigation that version introduced
- PHP 8.3+
- MySQL 5.6+ or MariaDB 10.0.5+, because the front-office search uses a native
  FULLTEXT index
- URL rewriting switched on: every CMS page is served through a rewritten URL,
  and the module refuses to activate without it

## Pages, Page module and TheliaBlocks

A Thelia 3.2 shop on the Flexy theme already has two modules that edit content,
both required by the theme:

- **Page** (`thelia/page-module`) makes standalone pages out of TheliaBlocks,
  served on rewritten `*.html` addresses. It has its own home page: a page
  flagged as home is served on `/`.
- **TheliaBlocks** (`thelia/thelia-blocks-module`) attaches groups of blocks to
  the shop's own items: products, categories, brands, contents and folders.
  TheliaCMS gives products, categories, contents and folders a document of
  their own (see [The content of a catalogue item](#the-content-of-a-catalogue-item)),
  which a site can use instead.

TheliaCMS runs alongside them, with its own tables, screens and addresses. It
never reads what Page and TheliaBlocks store, and they ignore it in return. The
CMS also has a home page (`home_page_id`, see [Configuration](#configuration)),
so set one in a single module only.

The contents and folders of the shop are not CMS pages. They stay where they
are, in Folders, which the back-office menu now reaches from the CMS section.
Nothing is converted: not the contents, not the Page pages, not the TheliaBlocks
groups.

## Installation

```bash
composer require thelia/cms-module
php Thelia module:activate TheliaCMS
php Thelia cache:clear
```

`dev-main` works too, and is what to use to follow the branch.

The module needs Thelia 3.3.0 or later. Composer pulls in TheliaLibrary.
Nothing has to be built: the module ships no compiled asset beyond its
stylesheet. The generator goes through the AI layer of Thelia: the provider,
the key, the model and the budget are set in the AI settings of Thelia, never
in this module (see [docs/generator.md](docs/generator.md)); until they are,
the rest of the module works and the generator says it is unavailable.

Activation creates the tables, seeds the ACL resources and generates the page
URLs. It also seeds four unpublished legal pages (legal notice, privacy policy,
cookies, accessibility statement) as placeholders in every active language.
Nothing is served on the front until you publish them, and publishing is refused
while a page still holds the sample text, from the back office and from a command
alike: the instructions the module wrote are not a legal notice.

A shop that already holds contents (an update, or an install with the demo) is
told that they stay in Folders, which the back-office menu now reaches from the
CMS section, and that nothing is converted into pages. The line is written at the
end of `module:activate`, `module:post-activate-all` and `thelia:demo:import`
(so `bin/install --with-demo` shows it), and the Pages screen carries the same
message until somebody with the right to change pages closes it. Nothing waits
on it: the activation goes through either way.

Deactivating the module removes the rewritten URLs it owns, so the site answers
404 rather than 500; reactivating puts them back.

## Addresses

A page carries one slug per language, which is the last segment of its address.
The segments of its ancestors are prefixed automatically, so a page called
"Conseil et accompagnement" filed under "Nos services" answers on
`/nos-services/conseil-et-accompagnement`. The field on the edit screen holds
that last segment. Leave it empty and it is derived from the title.

Each slug is stored on the page itself, next to its title. The addresses also
live in the core `rewriting_url` table, which the module has to clear when it is
switched off, so that copy is what puts a site back where it was: a slug
somebody shortened by hand cannot be derived from a title, and deriving it
anyway renames the page and leaves no redirection behind. The same copy is read
when a page comes back from the bin.

Renaming a page keeps its previous address as a 301. Those redirections are not
restored by a deactivation and reactivation cycle: what a page stores is the
address it answers on, not the list of the ones it used to answer on.

Reserved first segments (`admin`, `api`, `assets`, `recherche`, `search`,
`sitemap`, `robots.txt` and a few more) are refused as slugs. The rewriting
router runs before the Symfony routes, so a page slugged `admin` would shadow
the back office.

### Fixed addresses

A page can keep, in a language, an address that does not follow the tree: the
**Fixed address** field of the edit screen. It is the whole address, kept as
typed but for the slashes around it, `.html` suffix included, so a site taken
over from Thelia 2, WordPress or Prestashop keeps the addresses search engines
know while its pages are filed in a tree. Leave it empty and the address is
built from the parent pages and the slug again; the fixed one then answers 301
towards it.

The fixed address is stored next to the slug (`cms_page_i18n.fixed_url`), so
the addresses rebuilt on every activation of the module, on the move of an
ancestor or on a save keep it. The children of a page with a fixed address are
addressed from the path the page would have (its ancestors and its slug), not
from `about-us.html/`. An address another object answers on is refused; one a
redirection or an obsolete row holds goes to the page, which is how the former
address of a content becomes the address of the page replacing it. An address
written anew loses its accents to the core (`RewritingUrl::preInsert()`); one
the site already holds keeps them, its row being reassigned rather than
inserted. The update to 2.2.0 adds the column.

### Moving a page

Moving a page in the tree takes the addresses of everything under it. A
descendant is addressed from the addresses of its ancestors, so a page that
changes parent changes the address of its children, of their children, and so on
to the bottom of the branch. Each of them keeps the slug it already had, moves
under the new path in every language it answers in, and leaves its former address
behind as a 301 towards the new one. Renaming a page does the same to its
descendants.

The branch is re-addressed inside the transaction of the save. A subtree half
moved is a site where some pages answer and others do not, which is worse than
either outcome. Moving a page that sits near the root of a large site therefore
rewrites every address below it, at a few queries per page and per language, so
that save is not instant on a site of several hundred pages. Pages in the bin
stay out of it, and a tree where a page has ended up under one of its own
descendants is walked once rather than followed forever.

### Trailing slashes

An address of a CMS page that differs from the real one only by a trailing
slash answers 301 towards the form without it, so `/mentions-legales/` reaches
`/mentions-legales`. Symfony does that for its own routes; the rewriting router
does not, and a site taken over from WordPress, Drupal or Prestashop arrives with
the slash on every indexed address and every inbound link.

The redirection is permanent, keeps the host the visitor reached, and passes the
query string on untouched. Several slashes collapse in one hop, and the address
redirected to never carries one, so there is no chain and no loop. A POST is left
with the 404 it asked for, because a browser turns a redirected POST into a GET
and drops the body.

Only the addresses of this module are redirected, and only when the address
without the slash answers: the page is published, visible and inside its
publication window. An address belonging to another view (a content, a folder,
a product, a brand) is left alone, so the shop answers it as it always did,
with a 404 on the form carrying a slash. Whether one particular product or
brand is online is that module's business, and a redirection decided on the
rewriting table alone would send visitors to addresses nobody checked.

The root, the back office and the API are never touched. The first segment of the
path is what is compared, not a prefix, so a page addressed
`/administration-des-ventes` is not mistaken for the back office.

An address the rewriting table holds with its slash is left to the router, which
already answers on it, so an old address a takeover recorded in both forms keeps
its single hop.

The decision is taken on the request, above the router, and not on the 404 that
would follow. Waiting for the 404 leaves out every address another route answers
for: with `allow_slash_ended_uri` on in the store configuration, the Thelia
request hides the trailing slash from the Symfony routes, so `/contact/` falls
through the rewriting router into the `/{_view}` catch-all of the Front module and
renders the contact template of the theme with a 200, while the page answering on
`/contact` is never reached. Every single segment address whose name matches a
template of the theme is in that state.

An address without a trailing slash leaves on two string tests before any query,
so a site that has no use for any of this pays nothing.

## Finding a page

**CMS > Pages** shows the tree one level at a time. The pages at the root of the
site are listed; the ones holding other pages carry the number they hold and open
on a chevron. What you opened travels in the address, so a bookmark or a back
button puts you where you were, and the buttons on a row bring you back to the
same view. A site of fewer than forty pages opens whole, because folding buys it
nothing.

Above the table, **Find a page** looks for a word in the title of the language
being edited. The publication state and the visibility narrow it further. Asking
for anything turns the tree into a list of results, fifty to a screen, and each
row names the pages it sits under: indentation with the parents missing draws a
tree that is not on screen. The arrows that reorder a page only appear on the
tree, since a position moved from a filtered list is a position among pages you
cannot see. The bin has the same search box.

The screen works without a mouse. Branches open on links, moves are buttons, and
re-parenting a page is the **Parent** field of its edit form. It also works with
JavaScript switched off, apart from the confirmation before a page goes to the
bin.

Reading it costs the same on a site of six hundred pages as on a site of seven:
the state, the address and the number of pages underneath are read for the whole
screen at once, never per row. `PageListingCostTest` measures the same read on two
trees of different sizes and fails if the two numbers differ.

## Editing a page

A page carries a title, a slug, a summary, a detailed description, a parent, a
type, a publication window and its SEO metadata, all per language, and one
image shared by every language. The "Image" tab picks it from the CMS media
library or uploads a new one into it, saved with the rest of the page; uploading
takes the right to create media. Content is edited on its own screen,
**Edit content** (`/admin/cms/pages/{id}/content`).

The content of a page is a document: an ordered list of sections, each a
component of the catalogue with its props (see [Sections](#sections)). The
screen shows the brief on the left and the draft on the right, as the theme
renders it, through the signed preview link of the draft.

**Write it from a brief** sends what the page should say, its tone and its
audience to the generator, which answers with a whole document: the draft is
replaced. Each section of the draft is then listed by its title with a form to
ask for a change on that section alone, the others kept as they are, and
**Ask for a change** does the same on the whole draft. The generator reads the
skills of the site and the catalogue, may search the products, the categories,
the pages and the images of the site, reads the web pages the brief cites by
their address ([docs/generator.md](docs/generator.md)), and every call it makes is written to a
ledger the screen shows as its history, with its outcome, its turns, its
duration and its cost. Asking it takes the right `admin.cms.generate`; the
effort and the length of an answer are settings of this module, the model is
chosen in the AI settings of Thelia. How it is prompted, what it may read and
what it costs: [docs/generator.md](docs/generator.md).

A page written by the generator is marked as such, for the visitor and for
machines: see [Generated content and the mark of the AI Act](#generated-content-and-the-mark-of-the-ai-act).

Every generation that writes the draft keeps the draft it replaces as a
revision labelled `draft`, the last five apart from the twenty publications
(`cms_page_revision` and `cms_item_revision`, column `label`). **Previous
drafts** on the content screen lists them; **Put it back** makes one the
draft again and keeps the current one in turn, so a restore is undone the
same way. Sections edited by hand are not snapshotted: a word changed by hand
is cheap to change back.

Each section is also **edited by hand**, on the same screen, in a form read
from the schema of its component: a field per prop, rows added and removed for
a list, a picker on the media library for an image (thumbnails, search,
filters, sending), buttons for a choice that gives a pictogram per value
(`Prop::choice(..., icons: [...])`). A `html` prop is edited in
the editor of the Tiptap module when it is installed (`thelia/tiptap-module`,
suggested, not required) and in a plain textarea otherwise. What comes back
goes through the same validator as a generated document and is stored the same
way, so the next request to the generator starts from the corrected text. A
word corrected by hand costs nothing and takes the right to change pages, not
the generate right. The same right reorders the sections, dragged by their
handle or moved one place up or down with the arrows (the way from the
keyboard), duplicates them (the copy right after the original, with the same
props and appearance), and removes them; what is online changes when the
draft is published. **Add a section**, in the preview right after the last section
(or on the screen of a page holding nothing yet), lists the components the site
offers: the one picked is added at the end of the draft with the example
props of its component, and its text is then edited by hand. A page holding
nothing yet starts its draft that way, without the generator. A double click
on a free text section in the preview edits it where it stands: the editor
of the Tiptap module is loaded into the frame (a plain editable text without
it), and saving posts the form of the section with the text, so the schema,
the sanitizer and the appearance apply as from the form. Hovering a
section in the preview outlines it and shows **Edit**, which opens its form in
the list with the focus on its first field.

Every action on the screen is a form that posts and comes back with a message,
so it works without its script. With it, the forms post in the background and
the progress of a call is shown as it runs.

**Preview the draft** gives a signed link, valid 72 hours, that a client
without a back-office account can open. It is `noindex` and never cached. A
list next to it sets the width of the preview to a breakpoint of the theme, as
in its toolkit: the `--breakpoint-*` tokens of the nearest
`assets/styles/variables.css` of the theme and its parents, those of Flexy
otherwise. Two buttons switch the preview, and the link, between the **whole
page** (header, footer, title and fields of the page) and the **content
only** (the sections alone, the same signed link with `?content=1`); a theme
draws the latter its own way with a `cms-page-content-preview.html.twig` at
its root. The choice is kept from one screen to the next.
Publishing runs the rendered content through a server-side sanitizer, rewrites
the images and extracts the text for the search index, and the heading
structure is checked wherever a page goes online (see `heading_check_mode`).

Each language holds its own document: the French and English versions of a
page are two independent drafts, written from two briefs.

A deleted page goes to the bin instead of being removed. **CMS > Pages > Bin**
lists what was deleted, with the date and the time each page has left, and puts
any of them back. A page comes back with whatever was nested under it; a page
whose own parent is still in the bin waits for that parent.

The bin empties itself after 30 days, which `trash_retention_days` changes. A
page that goes takes everything with it: its content in every language, its
revisions, its search entry and its addresses. Set the value to 0 and nothing is
deleted until somebody asks for it. The clean-up runs from `maintenance:purge`,
the command a Thelia site already schedules, and from
`thelia_cms:pages:purge-trash` for a run on demand. That second command takes
`--dry-run`, which lists what would go and deletes nothing.

### Rendering drafts again

The HTML of a draft is written when the draft is saved. A change of the module
or of the theme since — the classes a theme gives free text, the markup of a
component — reaches a page at its next save; `thelia_cms:render` brings the
drafts up to date at once, from their documents, and publishes nothing:

```bash
php Thelia thelia_cms:render --page 12
php Thelia thelia_cms:render --all --locale fr_FR
```

The document, its author and what is online are left as they are; a draft that
renders the same is not written. The command holds nobody's right to custom
code: a draft whose iframe its new rendering would take out is left alone and
listed, for someone holding the right to save it from the back office.

A component of the theme renders its links and images on the address of the
shop: from the command line, that is the `url_site` setting, or `--url` over it
(`--url https://www.example.com/`). Without either, a page holding such a
component fails with a message saying so, and the other pages render.
`thelia_cms:publish` and `thelia_cms:generate` take `--url` too.

### Publishing from the command line

`thelia_cms:publish` puts drafts online through the same pipeline as the button
in the back office: sanitizer, responsive images, search index, revision. Writing
the published column from a script skips all four and says nothing about it.

```bash
php Thelia thelia_cms:publish --page 12 --page 13
php Thelia thelia_cms:publish --all --dry-run
```

`--all` means every draft of every page that is not in the bin, which is more
than it sounds like: a page somebody is halfway through rewriting goes online in
the state their last save left it in. `--dry-run` lists the pairs of page and
language it would publish and runs the same refusals as a real run, so the count
it announces is the count a real run reaches.

Two drafts are refused wherever the publication comes from, the button included:
one that would show nothing, and one still holding the sample text of a seeded
legal page. Write your own text and the refusal goes away. There is no flag to
remember and no way to publish the sample text by accident.

### Pages online with the example text on them

That refusal came after the first sites did, so a site can already have those
pages live. The page list and the dashboard block name them, each as a link to
the page and the language that needs writing. The warning goes away on its own
once the last of them is written or taken offline; there is nothing to dismiss.

### Emoji and other four-byte characters

An emoji typed in a title or in a page is stored as a numeric character
reference, `&#128247;`, and read back as the character. Nothing is lost and every
screen shows what was typed.

The reason is worth knowing if you write a module of your own against the same
database. Thelia opens its connection with `SET NAMES 'UTF8'`, which MariaDB and
MySQL read as `utf8mb3`, three bytes per character. An emoji needs four, and the
character set of the connection is settled before the one of the column, so a
column declared `utf8mb4` does not help: the statement comes back as
`Incorrect string value`. This module writes those characters out itself rather
than changing a connection the whole site shares.

One consequence to know about: the front-office search does not match a query
made only of emoji, since the search index holds the written-out form.

## The content of a catalogue item

A product, a category, a content, a folder and, while the Dealer module is
active, a store carry a document of sections in each language, written on the
content screen of a page: from a brief, by asking, or by hand, then published
(docs/adr/0011). The edit screen of the item
in the back office has a **CMS content** tab, which says where the document
stands in each language and leads to its screen
(`/admin/cms/catalogue/{type}/{id}/content`). The rights are those of the pages.

The generator is told which item the document is shown on, and what the theme
already shows of it, so the document adds to the page of the item rather than
repeating its name, its price or its description.

A store has no address the core builds, so its content screen offers the
preview of the document alone, not of its whole page. Its document follows it
from one site to another by the slug of its SEO record. The theme prints it on
the page of the store with `{{ cms_item_content('dealer', dealer.id) }}`.

On a theme that fires `product.content` and `category.bottom`, as Flexy does,
the module prints the document of a product or a category there itself: on a
product, after its description and before the products related to it; on a
category, at the bottom of the page. A Flexy older than `product.content` shows
no document on a product. A theme that prints it on its own turns
`item_content_hook` off, or the document shows twice.

A content, a folder and a store have no such hook in Flexy: the theme prints
their document, as it may print any document where it wants it:

```twig
{{ cms_item_content('product', product.id) }}
```

Nothing is printed while the item has no document online in the language being
served. The preview of the content screen shows the draft two ways:

- **Whole page**: the page of the item itself, opened with a signed
  `cms_preview` token, where `cms_item_content()` prints the draft instead of
  the published document. The page is then `noindex`, `no-store` and sends no
  referrer. An item without an address, or offline, has only the other way.
- **Content only**: the draft alone on a page of the module, without the
  header and the footer of the theme. A theme draws that page its own way with
  a `cms-item-preview.html.twig` at its root.

The document of an item goes when the shop deletes the item. It counts as a
use of the images and components it holds, and it travels in the export.

## Sections

The content of a page is a document: an ordered list of sections, each a
component of the catalogue with its props — the components the theme opens to
pages in its `config/theliacms.yaml`, a piece of free text, and the live
content of the site. A theme shows one anywhere with
`cms_section('cms:richtext', {...})`. How to add one, and how a document is
shaped: [docs/sections.md](docs/sections.md).

## The catalogue

A document is made of three families of components.

**The components of the theme** are what a page is built from: the theme
opens them in its `config/theliacms.yaml`, and they render with the markup and
the stylesheet of the theme. The module ships no layout of its own.

**Free text** (`cms:richtext`) holds a piece of markup, held at a readable
width by the stylesheet of the module.

**Live content** is rendered by the server on every visit rather than stored in
the page: the module ships one, a form of the site (`Partial:cms-form`). Every
partial of the site, the ones other modules register included, is a section
of the catalogue under `Partial:<name>`, with the settings of the partial as
its props.

### Products, categories, videos, maps

The module has no section of its own for a product, a category, a video, a
map or a post of a social network: they are components of the theme, opened
in its `config/theliacms.yaml` (Flexy's `Layouts:CrossSelling`,
`Organisms:CategoryCard`, `Organisms:VideoPlayer`). A `@prop` naming
`product`, `category` or a list of them is picked by search, in the generator
as in the form that edits the section by hand: a product by the start of its
reference, a part of its name or its barcode, a category by its name, only the
ones online.

Adding a component of your own takes one PHP class, or one line in the
configuration of the theme:
[docs/creating-a-component.md](docs/creating-a-component.md). Live content of
your own is a partial, a class implementing `PartialDefinitionInterface`
(`docs/examples/OpeningHoursPartial.php.example`), which joins the catalogue on
its own.

### Components offered to the generator

**CMS > Components** lists the catalogue: every component with where it comes
from (the module, a partial, the theme) and how many pages use it, drafts
included, the bin left out. A component switched off there is no longer
offered to the generator: it leaves the catalogue of the prompt, the schema
the answer is held to, `read_section` and the toolkit. Nothing else changes:
the pages using it keep rendering it, its sections stay editable by hand, and
a revision or a translation of such a page may keep it. Free text cannot be
switched off. The screen takes the settings right; the choice is stored in
`module_config` under `disabled_sections`. Where the toolkit answers (debug
mode or `THEME_TOOLKIT`, Flexy theme), a button opens it on the page that renders every
component offered.

## Documents of a page

The **Documents** tab of the edit screen of a page lists the files the page
offers to download. It takes several files per page: pdf, doc, docx, xls,
xlsx, ppt, pptx, odt, zip and txt. The extension and the detected type must
agree, and html, svg and php are refused. The size is bounded by PHP
(`upload_max_filesize`, `post_max_size`, `max_file_uploads`). Adding,
describing, ordering and deleting a file are saved at once, whether the page
is a draft or not. The tab is inactive until the page is saved. It needs the
Pages right (UPDATE to add, describe and order, DELETE to remove), not the
Media right.

The file is shared by every language, while the title and the description are
per language. A document without a title in a language shows its original
name without the extension; it does not fall back on another language. Files
are stored under `local/media/documents/cms-page/` (back them up with the
media), named by the slug of the original name, eight random hexadecimal
characters and the extension.

`GET /cms/documents/{id}/{file}` (route `cms.page_document`) serves a file.
It answers 404 when the name does not match the pattern, the row is unknown,
the id and the name disagree, the page is in the bin or the file is missing. A
page offline or never published serves its files: the address is only known
to whoever was given it. The content type comes from the extension, with
`X-Content-Type-Options: nosniff` and `Content-Security-Policy: sandbox`. A
pdf or a txt opens in the browser, the rest downloads under its original name.
The response is `Cache-Control: public, max-age=300, must-revalidate`, with
`ETag` and `Last-Modified`, so a deleted document can stay five minutes in a
cache.

A template lists them with `cms_page_documents(page, locale)`, `page` being a
`PublishedPage` or an id and the locale defaulting to the one being served.
The list is read when the page is served, so adding or removing a file needs
no new publication. It holds data only, objects with `id`, `title`,
`description`, `url`, `extension`, `size` (in bytes) and `originalName`, and
is empty for an unknown page or one in the bin. The call declares the cache
tag of the page.

```twig
{% set documents = cms_page_documents(cms_page) %}
{% if documents %}
    <ul>
        {% for document in documents %}
            <li>
                <a href="{{ document.url }}">{{ document.title }}</a>
                ({{ document.extension|upper }}, {{ (document.size / 1024)|round }} KB)
            </li>
        {% endfor %}
    </ul>
{% endif %}
```

A template that only wants the list drawn calls
`{{ cms_page_documents_block(cms_page) }}` (same arguments as
`cms_page_documents`): it answers the markup, or an empty string when the
page has no document, and declares the same cache tag. The Flexy and the
generic page templates of the module call it after the content of the page
(inside the `article`, before the `cmspage.content.after` hook), so a theme
using them lists the files with nothing to write. The markup comes from
`cms-page-documents.html.twig`: a theme takes it over by shipping a file of that
name at its root, as it does for `cms-search.html.twig`; until it does, the
neutral one of the module (`templates/front/`) is used, with `cms-documents*`
class names and no style. The template receives one variable,
`cms_page_documents_block`, holding:

- `documents`: the documents, each with `id`, `title`, `description`
  (or null), `url`, `extension`, `type` (the extension in upper case), `size`
  (readable, "812 B", "1.5 KB", "2.4 MB", translated; null when unknown),
  `bytes`, `download` (false for a pdf or a txt, which open in the tab, true
  for the rest) and `originalName`;
- `page`: what was given to the function (a page or an id);
- `heading`: the translated "Documents to download".

The language is the `locale` given, else the language of the page when a
`PublishedPage` is given, else the one of the request.

A theme that draws the documents itself with `cms_page_documents` while using
a page template of the module would list them twice: take the page template
over, or override `cms-page-documents.html.twig` instead. The template gets one
variable only because the theme parser keeps every variable of a render for the
rest of the page: a template of a theme should set none of its own.

Duplicating a page or creating one from a model copies its documents,
including the files. Emptying the bin and deleting a page type (and so its
model page) remove the files once the database change is committed; a file
that cannot be removed is logged, without an error. The export and the import
of the site do not carry documents. The update to 2.6.0 adds
`cms_page_document` and `cms_page_document_i18n`.

## Media

**CMS > Media** stores images in [TheliaLibrary][library]. Alternative text is
mandatory in every language unless the image is marked decorative, which
publishes it with an empty `alt`. An empty attribute on its own cannot say
whether an image was described or simply forgotten, so the choice is recorded.

Each image shows its dimensions, weight, format, the pages and catalogue items
using it, and where its description stands in every language. One still in use
cannot be deleted. Its tags are suggested from the ones the library already
has, and a replacing file is shown before it is saved.

The list shows 48 images a page. It narrows to the images still to describe
in the language edited, the unused ones, the decorative ones or one tag, and
orders them newest, oldest, by title or heaviest first. The search reads the
title, the alternative text, the caption and the tags.

"Describe them one by one" walks through the images of the list that still
say nothing in the language edited: one image, one field, Enter saves and
shows the next one. An image can be answered as decorative or skipped.

Images selected on the list take or lose a tag, are marked decorative or not,
or are deleted when nothing uses them; the ones still used are kept and named.

Uploads accept JPEG, PNG and WebP. SVG is refused: it is a document that can
carry script. Files are dropped on the list or chosen, sent one by one, and a
refused file is named with why without costing the others. The describe queue
then opens on the images just added.

The images of the catalogue (products, categories, brands, folders, contents)
are not in the library: the core keeps them in its own tables. To use them in
pages, copy them in:

```bash
php Thelia thelia_cms:media:import-catalogue [--type product --type brand] [--dry-run]
```

Each copy takes the title, the alternative text and the decorative flag of the
catalogue image in every language, the title of its item when it has none, and
a tag for its kind of item (and, for a product, its default category). Run it
again after adding images to the catalogue: a file the library already holds,
by its content, is not copied twice. A copy does not follow the catalogue: an
image replaced there is copied again as a new one.

At publication every image becomes a `<picture>` with a WebP alternative, a
`srcset` bounded by the file's real width and ending on the original, explicit
`width` and `height`, and lazy loading on everything but the first image.

## Menus

Menus live under **CMS > Menus** and a theme calls them by code. `main` and
`footer` exist from the first activation.

An entry points at a CMS page, a content, a folder, a category or a product of
the catalogue, a web address, or at nothing at all. An entry with no target is a
label on its own, which is how a group heading is made. Its own label is
optional: left empty, the title of the target is shown, in the language being
read. An entry pointing at an item of the shop links to the address the shop
serves it on in that language, so renaming the item or changing its address
never leaves the menu behind. In the form, categories are listed as their tree
and products grouped by their default category.

The tree goes three levels deep. Entries are reordered with the move buttons or
by dragging a row, and nested either by picking a parent in the form or by
dropping a row onto the right-hand third of another one. Dragging is a shortcut.
It cannot be done with a keyboard, so it is never the only way to reorder a
menu.

An entry whose target has been deleted, taken offline (a hidden category or
product included), or left unpublished in the language being read is **left out of the menu** in that language, and listed
in the back office with the reason. A heading that still has usable children
stays as a heading, rather than let its children move up a level.

The two menus also fill the content slots of Thelia, which is how a theme that
knows nothing of this module (Flexy among them) shows them: `main` answers the
`header_links` slot and `footer` the `footer_links` one. A menu takes its slot
over as soon as it stores one entry, even if none of its entries can be shown in
the language being read; while it is empty, the slot keeps the folders and
contents the shop configured, so activating the module changes nothing on the
front until somebody builds a menu. The consent slots (the terms of sale linked
from the checkout) stay with the contents of the shop.

### A menu per language

A menu is shared by every language until a language is given a menu of its
own. Each code then has one shared menu, read by every language that has no
menu of its own, and one detached menu per language that has one, under the
same code: a theme keeps calling `cms_menu('main')` and gets the menu of the
language it is served in.

- **Detaching** a language copies the shared menu into a menu of its own: its
  titles, every entry, and the labels of the entries in every language. From
  then on, the two are edited apart. Only an active language can be detached.
- **Realigning** replaces the entries of a menu by a copy of the entries of
  another menu of the same code: a language on the shared menu, a language on
  another language, or the shared menu on a language. The entries are
  replaced, never merged, and the titles of the menu being realigned stay.
- **Back to shared** deletes the menu of a language and its entries; the
  language reads the shared menu again.

A detached menu whose entries were all deleted is an empty menu in that
language, not a way back to the shared one: the shared menu does not show
through. Like an empty shared menu, it leaves the content slot of the core to
the folders and contents of the shop in that language.

In the back office, the tree of **CMS > Menus > Edit** is the one of the
language being edited (the flags above the tree switch it). A line above the
tree says whether that language follows the shared menu or has a menu of its
own. **Give this language a menu of its own** detaches it at once. **Realign
on…** asks which menu to copy, then shows how many entries are replaced before
anything is written; on a language with its own menu, **Make the shared menu a
copy of this one** does the same the other way round. **Go back to the shared
menu** asks first too, because the entries of the language are deleted. The
list shows each code once, with a flag for every language that has a menu of
its own. The code can only be changed on the shared menu.

The code belongs to the shared menu: renaming it renames the menus of its
languages, and deleting it deletes them. The list of menus and the picker of a
component of the theme show each code once.

## Forms

Forms live under **CMS > Forms**. A form has a code, the way a menu does, and
a page places it with the `Partial:cms-form` section. The page stores that
reference and nothing more: the fields, the wording and the recipients are read
when the page is served, so adding a field never means republishing the pages
the form is on.

A field is one of nine kinds: a single line of text, an email address, several
lines, a drop-down list, a tick box, one choice among several, a phone number,
a date, or an agreement to be contacted. Labels, help texts and the answers a
list offers are written per language. A field with no label in a language is
left out of the form in that language, and the back office says so: an input
nobody can name cannot be filled in, and a screen reader announces nothing
for it.

The agreement field is never ticked in advance, and the answer stores the exact
sentence the visitor read along with the moment they agreed. If the agreement is
ever questioned, that is what has to be produced. In France, since 11 August
2026, it is also what makes a phone number usable for a commercial call.

Recipients are set here and nowhere else. A form that took its recipient from
the page, or worse from the request, would be a mail relay with a nice
interface.

### What stops the robots

Four checks, none of which asks anything of the visitor:

- a field only a robot fills in;
- a signed record of when the form was served, so a message sent in under three
  seconds, or with a stamp this site never issued, goes nowhere;
- that stamp carries one message: each display of the form gets a stamp of its
  own, and a stamp that already brought a message in is a replay. A message
  sent back for a mistyped field goes again with the same stamp;
- a cap on how many messages one sender may get through, honouring the
  existing `form_firewall_active`, `form_firewall_attempts` and
  `form_firewall_time_to_wait` settings of the shop. Only accepted messages are
  counted: mistyping an address six times on a long form should not lock
  somebody out for an hour.

A robot is told its message was sent, as everybody else is: feedback is what it
tunes against. The counts live in the `cms_form_throttle` table, one row per
counter keyed by a keyed hash, written in one statement, so twenty messages
posted at the same instant are counted twenty times. Expired rows go with
`maintenance:purge`.

A page that shows a form is never kept by a shared cache: it is served
`private, no-store`, so that each visitor gets a stamp of their own.

There is no captcha. A captcha sends the visitor to a third party and gets in
the way of anyone using assistive technology, and on the volume a showcase site
sees it catches less than the three checks above.

### Answers

**CMS > Forms > Answers** lists what a form received, searchable by email
address. From there an answer is exported as CSV or as JSON, or deleted. That is
what answering a request to see or to erase personal data comes down to, and it
takes a couple of minutes rather than an afternoon of SQL. The CSV keeps a
column for a question that has since been removed from the form, and defuses any
cell a spreadsheet would run as a formula.

Each form states how long its answers are kept, 365 days by default. They are
deleted on their own by `thelia_cms:forms:purge`, and by `maintenance:purge`
along with the carts and the admin logs. Hooking onto the command a Thelia site
already schedules is deliberate: a retention rule needing a cron entry of its
own is one that half the sites never run.

The address a message came from is never stored: what is kept is a keyed hash
of it, which recognises the same sender twice without recording who visited.

The receipt sent back to the person, when the form asks for one, carries the
subject and the message written in the settings of the form, or a short default,
and never the answers. Its address is typed by whoever sends the form: a receipt
that repeated the answers would let anyone make the shop write any text, in its
own name, to any address. For the same reason one address receives three
receipts a day at most, whoever sends the form; beyond that the message is still
kept and the recipients still told, only the receipt is skipped.

A sent form is pushed to the data layer as `generate_lead`, carrying the code of
the form and nothing else, and only when the person agreed to be contacted.

## Dashboard

On a showcase site, the back-office home page gains a block of its own, above
the shop charts: those count orders and turnover, which on a site with no shop
is a screen of zeros somebody has to scroll past.

It shows how many messages the forms received over the last thirty days, how
many pages are online and how many are still drafts, how much of the site exists
in each active language, and the five pages changed last. Nothing here
re-implements analytics.

Above all of that, when there is something to say: the pages that are online with
the example text of the seeded legal pages still on them. The same warning is on
the page list, which is the screen somebody opens to go and write them.

The block is only added in showcase mode, and only for somebody allowed to see
the pages.

## Shared cache

Every rendered page carries a `Cache-Tag` header (and `Surrogate-Key`, which is
the same list under the name Fastly and Cloudflare read) naming what went into
it: the page itself, the menus it draws and the site settings. Publishing a page
drops that page from the cache, and saving a menu drops the pages that draw
it.

Purging is done by whatever sits in front of the site. The module ships no
implementation, because what a purge means depends on the proxy and guessing
wrong is worse than doing nothing. A project implements `CachePurgerInterface`
and the tag picks it up. A purger that fails is logged and skipped: not reaching
a CDN must not turn publishing a page into an error the editor sees.

Whether a page may be shared at all is decided once the response is finished,
not while it is being built, because the answer depends on the cookies on it. A
page is only ever marked `public` for a plain GET, from a visitor who arrived
without a session, with a 200 answer that sets no cookie of its own.

That last condition is the one that matters in practice: **Thelia opens a
session on every front-office request and sets `PHPSESSID` on the way out**, so
out of the box no page is marked `public` and `http_cache_ttl` changes nothing.
Making it work is a matter of configuring the proxy to drop the session cookie
on the addresses it is allowed to cache, which is the same thing every Varnish
setup in front of Thelia already does. The tags are written either way, so the
invalidation side works from the start.

On a Thelia core that brings the shared cache of the public pages, turning
`shared_cache_mode` on hands all of this to the core: it shares the pages of
the module under the tags above and sends the purges of the module to its
proxy. See [docs/shared-cache.md](docs/shared-cache.md).

`docs/shared-cache.md` has the VCL that does it, the same thing for Fastly and
Cloudflare, and a complete purger to copy into a project.

## Security headers

The pages the module renders (a page, the home page it serves, its 404 and
maintenance pages, the search results and the three previews) can be sent a
Content-Security-Policy, set under **CMS > Settings > Security headers**:

```
img-src 'self' data: <hosts>; font-src 'self' data: <hosts>; frame-ancestors 'self'
```

It says where pictures and fonts may come from, and that only the site may
frame the page. It says nothing of scripts: the consent layer and the snippets
an administrator pastes are written inline, and a nonce can neither be put
into pasted HTML nor be kept by a page a cache shares. What it guards is a
picture or a font a stylesheet or a `style` attribute would load from another
host, and a tracking pixel loaded before the visitor agreed.

- `off`, the default: nothing is sent.
- `report-only`: nothing is blocked. The browser reports what would be to
  `POST /cms/csp-report`, which writes it to the log of the shop (directive,
  origin of what was blocked, path of the page; nothing a visitor typed), each
  report once an hour and at most a hundred an hour.
- `enforce`: the browser blocks what the policy does not allow. Turn it on once
  the log stays quiet for a week of real visits.

A snippet that loads a pixel, a widget or a font from another host needs that
host in the list. The pages of the shop (cart, payment, account) are never sent
the policy, and a page that already has one, from the theme or another module,
keeps it. Thelia (3.3 and later) already sends `X-Content-Type-Options`,
`X-Frame-Options` and `Referrer-Policy` on every page.

## Sitemap

A theme opens its sitemap to modules by calling `theme_hook('sitemap.urls')`
inside its `<urlset>`, passing the language and the context it was asked for.
The module answers there with its published pages, so the pages are part of the
sitemap the site already had rather than of a second one nobody submits.

Each entry carries `lastmod` set to the publication date, never to the update
date of the row: a row is touched by things a reader never sees, and a sitemap
claiming every page changed last night is one a crawler learns to distrust.
Pages that exist in several languages carry an `xhtml:link` to each of them,
themselves included, since a one-way hreflang is ignored. There is no
`changefreq` and no `priority`, which no search engine has read for years.

Only pages a visitor can reach are listed. The bin, the drafts, a page waiting
for its publication date, a page past its unpublication date and a page marked
`noindex` in a given language are all left out.

Themes deriving from `thelia/flexy-cms` already have the hook. On any other
theme, adding it is one line, and the `xmlns:xhtml` namespace on the `<urlset>`
element.

## Search

On a showcase site, the module answers on `/recherche` and on `/search` with a
results page. On a shop it leaves both paths alone: the search bar of the theme
sends visitors to `/search`, and the product search of the theme keeps answering
there. The choice is made when the path is matched, so switching the site mode
takes effect on the next request. Both paths are on the reserved list either way,
so no page can be given a slug that would shadow them, and the language of the
page is the one the visitor is reading the site in rather than one the address
imposes.

The query runs against the plain text extracted when a page was published, never
against the HTML: a full-text index over markup matches tag names and ranks a
page by how much of it there is. Only pages a visitor may reach are searched.
The bin, the drafts, a page waiting for its publication date and a page marked
`noindex` are filtered in SQL rather than dropped from the results afterwards,
which is what keeps a page of ten results from showing three.

What the visitor typed is stripped of everything MySQL boolean mode reads as an
operator. Boolean mode has no escape character, so a search for "C++" would
otherwise be a syntax error rather than a search. Every word is required and the
last one is completed as a prefix, so "access" already finds the accessibility
statement.

The results page answers `X-Robots-Tag: noindex, follow`. It exists in as many
versions as there are queries, which is exactly what search engines ask sites
not to have indexed.

A theme takes over the layout by shipping `cms-search.html.twig` at its root.
Until it does, the module renders its own, in `cms-*` class names styled by the
stylesheet the module ships.

On a site that also runs TntSearch, the module registers a `CmsPageIndex` over
the same rows. Without TntSearch nothing is registered and the built-in search
answers alone.

## Scripts and measurement

> **Not offered in this version.** The scripts screen and the site styles
> screen are left out of the back office: neither the menu nor the dashboard
> links to them, and their addresses answer 404. Snippets saved before keep
> being written into the pages as described below, and the custom-code
> permission keeps governing what free markup may carry beyond the standard
> allow list.

**CMS > Scripts and measurement** holds the third-party snippets of the site: the
measurement tags, the chat widget, whatever the agency was asked to add. They
live here rather than in the theme, so they are the same on every page and
removing one is a click.

Each snippet says where it goes and which vendor of your consent platform it
waits for. A snippet naming a vendor is written into the page inside a
`<template>`, which the browser reads but does not run: no script executes, no
image is fetched, no iframe connects. It comes out once the visitor has agreed
to that vendor. Marking only the script tags as `text/plain` would leave the
tracking pixel beside them free to fire, and that is the tag that needed consent
most.

A snippet with no vendor loads for everyone, straight away. That amounts to
saying the site cannot run without it, so the screen labels it that way.

The consent layer is Axeptio, set up on the same screen with your project
identifier. It goes at the very top of the head, before anything else, and
Google Consent Mode is told first that nothing is allowed: `ad_storage`,
`ad_user_data`, `ad_personalization` and `analytics_storage` all denied, with a
short wait so a returning visitor's earlier answer arrives before the tags do.
Since 15 June 2026 `ad_storage` decides whether a Google Ads conversion is
counted at all, so a site that never emits these defaults and never updates them
measures nothing rather than measuring without consent. What each vendor may
turn on is a JSON map, which defaults to the two Google products.

The screen sits behind the custom-code permission rather than the settings one:
whoever can paste a script tag onto every page can do anything a visitor's
browser can do.

With no consent platform set up, a snippet that waits for a vendor never loads.
The screen says so rather than letting you find out from the traffic.

## Site icon

A theme points its `<link rel="icon">` at `cms_site_icon()`, which serves the
file uploaded in Configuration > Store when there is one, and returns nothing
when there is not, so the theme falls back on its own. Changing the icon of a
site becomes an upload in the back office rather than a file to replace in the
theme.

Thelia keeps that file outside the public directory and reads it through an
admin-only route, so the module serves it on `/site-icon`, cached for a day. Only
the extensions a browser accepts as an icon are served: the file name comes from
a configuration row, and this path answers before any authentication.

## Configuration

Settings live in `module_config` and are read through
`TheliaCMS::getConfigValue()`.

Most of them are edited under **CMS > Settings**.

| Value | Default | Description |
|---|---|---|
| `home_page_id` | none | Page served on `/`. Set from the page list; its own slug then 301s to `/`. |
| `site_mode` | `commerce` | `vitrine` closes the shop paths and puts CMS first in the back-office menu. |
| `404_page_id` | none | CMS page served when an address does not exist, with a 404 status. |
| `maintenance_active` | `0` | `1` closes the site with a 503. |
| `maintenance_allowlist` | empty | IP addresses and CIDR ranges that keep seeing the site while it is closed. |
| `maintenance_page_id` | none | CMS page shown while the site is closed. |
| `trash_retention_days` | `30` | Days a deleted page stays in the bin before it is deleted for good. `0` keeps it until somebody deletes it by hand. |
| `http_cache_ttl` | `0` | Seconds a shared cache may keep a page. `0` disables it, which is the default. |
| `csp_mode` | `off` | `report-only` or `enforce` sends a Content-Security-Policy on the pages of the module. See [Security headers](#security-headers). |
| `csp_hosts` | `https://fonts.gstatic.com`, plus `https://*.axept.io` when Axeptio is configured | Hosts pictures and fonts may come from besides the site, one per line. |
| `axeptio_client_id` | none | Axeptio project. Without it no banner shows, and every snippet waiting for consent stays off. |
| `axeptio_cookies_version` | none | Which Axeptio configuration to load, when a project has several. |
| `axeptio_consent_map` | the two Google products | JSON: vendor to the Consent Mode signals it grants. |
| `cache_ttl` | `3600` | Seconds a resolved menu is cached for. It is also dropped on every change that affects it, the changes made to contents, folders, categories and products from the screens of the shop included. |
| `footer_menu_hook` | off | Renders the `footer` menu into the `layout.footer.top` hook of the theme. For a theme that does not call `cms_menu('footer')` itself: without it, a CMS page is reachable only by its address. Leave it off on a theme that already renders the menu, or it appears twice. |
| `item_content_hook` | on | Prints the document of a product on the `product.content` hook of the theme, and the one of a category on `category.bottom`. Turn it off on a theme that calls `cms_item_content()` for them itself, or the document appears twice. |
| `heading_check_mode` | `warn` | `warn` reports heading problems and publishes anyway; `block` refuses to publish. Checked wherever a page goes online: the content screen, the settings screen, `thelia_cms:publish`. |
| `native_content_notice_dismissed` | `0` | `1` once the notice about existing contents has been closed on the Pages screen. |
| `generator_effort` | `high` | How hard the model thinks: `low`, `medium`, `high`, `xhigh` or `max`. Anything else falls back to the default. It is passed to the model as its reasoning effort, which only Anthropic models read: on another provider it has no effect. |
| `generator_max_tokens` | `48000` | The longest answer the generator accepts, in tokens, thinking included. Below 1024 no page fits, so a smaller value falls back to the default. Only what is written is billed, but the AI layer of Thelia reserves the budget of the whole limit on every call, and holds it under the limit of the usage and of its model. |
| `generator_web_fetch` | `0` | `1` lets the generator read the web pages a brief cites (see [docs/generator.md](docs/generator.md#web-pages-the-brief-cites)). Off until the site turns it on. |
| `generator_text_effort`, `generator_text_max_tokens` | `low`, `2000` | The same two settings for the texts written beside a field, which need less than a page. |

The provider, the key, the model and the budget are not settings of this
module: they live in the AI settings of Thelia, where a connection is
declared once and each usage is given a range of models. A setting of this
module travels with a site export, a key must not. The files written before
2.4.0 may still carry the model of the generator: it is ignored on import.

### Generated content and the mark of the AI Act

Thelia marks what the AI writes (article 50(2) of the AI Act: a machine-readable
mark on the content, and a notice a person can read). The module keeps the mark
with the content it describes and prints it where the theme renders it:

- a page, or the document of a catalogue item, written by the generator carries
  the mark in `cms_page_content` and `cms_item_content` (`draft_provenance`,
  `published_provenance`), copied from the draft to the publication, to a
  revision and through the export and import of the site. A page written by
  hand carries none. Editing a generated draft by hand keeps the mark: nothing
  can tell when a page stops being the model's.
- the public page prints `data-ai-generated`, `data-ai-usage`, `data-ai-model`
  and `data-ai-generated-at` on the element that holds the content, and a
  sentence a visitor can read after the article ("Content generated by an AI
  (model) on date."), in the language of the page. `cms_item_content()` wraps
  the document of a product, a category, a content or a folder the same way
  when it was generated.
- the mark is stored in a column and not in the HTML: the sanitizer of the
  published HTML removes every `data-*` attribute it does not know.

A theme that overrides `cmspage.html.twig` has to keep the two calls, or its
pages lose the mark. On the element that holds the content, add
`{{ ai_provenance_attributes(cms_page.provenance) }}`; after it,
`{% set label = ai_provenance_label(cms_page.provenance, cms_page.locale) %}`
and print `label` when it is not empty. Both come from Thelia and print nothing
for a page written by hand. The Flexy theme of the module does it (see
`templates/frontOffice/flexy/cmspage.html.twig`).

The text a person asks for beside a field (a product description, for instance)
carries no public mark: the person reads it, edits it and saves it as their
own. What stays is the trace in the AI log of Thelia, which records the usage
`theliacms.text`, the model, the cost and the date of every call.

### Prompts and their retention

The ledger of a page keeps the prompt of each call (the brief and what the
generator read of the site) so an editor can start again from it. The AI
settings of Thelia decide how long a prompt lives, usage by usage, and may
decide to keep none: the ledger follows. `php Thelia maintenance:purge`, which a
Thelia site is already told to schedule, empties the prompt of a row once the
log of the core has emptied its own (30 days after the call for a row with no
pointer to the log, such as a row written before 2.4.0). The brief the editor
wrote, the document, the cost and the dates stay: they are the history of the
page.

## Skills

A skill is a piece of instructions the generator reads before it writes: how a
page is structured here, how text is written, what accessibility asks, what
search engines read, how a page is translated, how it stays within the design
system. The module ships eight under `skills/<code>/SKILL.md` (`catalogue`,
`page-structure`, `copywriting`, `accessibility`, `seo`, `translation`,
`design-system`, `motion`), and a
theme ships its own under `skills/` with the same layout; a theme skill with
the code of a module skill replaces it.

The files are a seed. The first read sows them into the `cms_skill` table, and
the site edits them there, under **CMS > Skills**, which takes the settings
right: a skill is rewritten, switched off, moved up or down from the arrows
of the list, and put back to the shipped text with one button. A shipped file that changes afterwards updates
the stored text unless the site rewrote it. The generator reads the table,
never the files. The front matter of a skill and how it reaches the prompt:
[docs/generator.md](docs/generator.md).

## Showcase mode, maintenance and the 404

A **showcase site** answers 404 on `/cart`, `/order` and `/checkout`, and moves
CMS to the top of the back-office menu. Nothing else changes, and switching back
undoes it: the shop is one save away.

Saving showcase mode also creates the **Editor** profile: pages, menus, media,
forms and news, and none of the shop, none of these settings and no free HTML.
Assign it under **Configuration > Administrators**; its permissions are yours to
change afterwards, and it is never touched again.

**Maintenance** answers 503 with `Retry-After`, which asks search engines to come
back rather than to drop the page. A 200 saying "back soon" is what gets indexed
in place of a site, and a 404 is what gets it removed. Addresses that do not
resolve answer 503 as well, since the check runs before routing. Three ways
through: the back office, the IP addresses on the allow list, and an
administrator already signed in. The page shown can be a CMS page; if it is not
published in the visitor's language, a plain page from the module is served
instead, because the theme is part of what may be under repair.

The **page shown when an address does not exist** is a CMS page like any other,
served with the 404 status. Answering 200 would have search engines index it
under every wrong address ever linked to the site.

## Documentation for editors

**CMS > Documentation** is the same ground as this file, written for whoever
edits the pages rather than for the developer of the site: what each screen
does and what each setting changes, in French or in English after the
language of the back office (`templates/backOffice/default-twig/documentation/`,
one file per language, English for any other). Its summary is read off the
`<section id>` and `<h2>` of the text, whose ids are the same in every
language: each screen of the CMS links to the section explaining it
(`CmsDocumentationLinkHook`, by the name of its route). A change of the module
that an editor sees belongs there too.

## Permissions

Seven resources, seeded on activation with no access granted. Open them per
profile under **Configuration > Administrators**.

| Resource | Covers |
|---|---|
| `admin.cms.page` | the page tree, the content screen, editing a section by hand, publication |
| `admin.cms.generate` | asking the generator to write, from a brief or for a change. It costs money, so it is a right of its own; the seeded Editor profile has it |
| `admin.cms.menu` | the menus and their entries |
| `admin.cms.media` | the media library |
| `admin.cms.custom-code` | `<iframe>` in published content, beyond the standard allow list |
| `admin.cms.settings` | the settings screen: showcase mode, maintenance, the 404 page, the generator card, the skills, the components offered to the generator |
| `admin.cms.form` | the forms and their answers |

Every route under `/admin/cms` is guarded by the resource of its section, so a
route added later cannot ship unprotected by omission.

While the module is active, the Folders section of the back-office menu is
hidden and the CMS section links to the folders instead, so the content of the
site has one place in the menu. The folder and content screens are those of the
shop, unchanged, and keep their own permission (`admin.folder`): a profile
allowed on the folders and on nothing of the CMS still sees the CMS section,
with that one entry in it.

## Theme integration

The module renders a page with `cmspage.html.twig` from the active theme when
the theme provides one, and falls back to its own otherwise. It therefore works
on any Twig theme exposing a `base.html.twig`, and a theme can take the layout
over without touching the module.

Both versions emit the same hooks, which are the extension points for other
modules. Each receives the page as `page`.

| Hook | Type | Rendered |
|---|---|---|
| `cmspage.top` | front | before the content |
| `cmspage.content.before` | front | inside the article, before the content |
| `cmspage.content.after` | front | inside the article, after the content and the documents of the page |
| `cmspage.bottom` | front | after the content |

The page is a `TheliaCMS\Page\PublishedPage`: `cms_page` in the template,
`page` in the hooks. Besides its title, its HTML and its SEO fields, it holds
`chapo` and `description`, the summary and detailed description typed in the
back office, as HTML filtered on save like the content of the page, or null
when left empty, and `image`, a
`TheliaCMS\Page\PageImage` or null: `url`, `alt`, `width`, `height`. Its `alt`
is an empty string for a decorative image and null for one nobody has
described yet. None of the three is rendered by the fallback layout: they are
what a theme shows about the page elsewhere, a heading block or a card.

### Page types

A page has one type, picked in the "General" tab, which chooses the template it
is displayed with: a recipe and a news item can look nothing alike. The types
of the site are listed under **CMS > Page templates** (also reached from
**CMS > Settings**). A type is
identified by its code — lowercase letters, digits and single hyphens, such as
`recipe` or `news-item` — and may also be named in each language and serve as
a template of pages (below).

A page of type `recipe` is rendered with the first of these templates that
exists:

1. `cmspage-recipe.html.twig` as the front parser finds it: the active theme,
   the themes it inherits from, and the front templates another module ships
   for the theme or for `default`;
2. `cmspage-recipe.html.twig` in `templates/front/` of this module;
3. `cmspage.html.twig` of the theme;
4. `cmspage.html.twig` of this module.

A type without a template of its own is therefore displayed like any other
page, and the page types screen says, for each type, which template it gets.
A typed template receives the same `cms_page` as `cmspage.html.twig`, with
`cms_page.pageType` holding the code, and must emit the same hooks.

Every template of a page draws its title as the level 1 heading, with its
summary, above the sections, which start at level 2
([ADR 10](docs/adr/0010-the-template-draws-the-title-of-a-page.md)). For
Flexy, the module ships `templates/frontOffice/flexy/cmspage.html.twig`, found
as the usual page template of the theme: a page is laid out like a content of
the shop, with the heading band of the theme (title, summary, breadcrumb) and
the fields of its type.

Every site has the `default` type, which cannot be deleted, and the update to
1.2.0 turns the three former layouts into the types `default`, `full-width` and
`landing`, so a page keeps the one it had. A type some page has, the bin
included, cannot be deleted; its model page is not one of them and goes with
it. Changing the type of a page applies as soon as the
page is saved, without publishing it again. With a shared cache in front of the
site, a template added to the theme shows at the next expiry of
`http_cache_ttl`: see [docs/shared-cache.md](docs/shared-cache.md).

The type travels with the site export. An import of a site creates the types
the site lacks (a type no page has is not exported, unless it is described as
a template).

The fallback template puts `cms-page--{type}` on the `<body>`, for a theme that
only needs a class to tell the types apart.

### Templates of pages

A page type is also a template of the pages of a series (recipes, case
studies, events), described on its screen under **CMS > Page templates**
([ADR 9](docs/adr/0009-a-page-type-is-a-template-of-pages.md)):

- a **name** and a description in each language;
- a **brief**, with a tone and an audience, that the generator reads before
  the brief of every page of the type: what its pages are about;
- **writing instructions**, free markdown on how every page of the type is
  written (tone, length, sections expected, mandatory mentions), read by the
  generator for each page of the type as rules that win over a brief, like
  a skill only that type loads;
- **fields**, the values each page of the type carries: a whole number, a
  decimal, a duration in minutes, a short or long text, yes or no, a word from
  a list, a date. A field has a code, which a template reads it by and which
  never changes, a label in each language, and may take a unit, bounds, be
  written again for each language (texts only), or be required: a page cannot
  be published while it is empty;
- a **model page**, the demo content new pages of the type are copied from.
  It is written like any page, from a brief or by hand, is never published,
  and is not listed with the other pages.

**Start a page from a template** on the page list copies the model of the
type, its content in every language and its field values, under the title
typed. The values of a page are filled in the "Fields" tab of its settings,
and the generator writes them along with the content.

A template of the theme reads the values of the page served as
`cms_page.fields.<code>`, raw, and `cms_page_fields(cms_page)` gives the ones
holding a value, labelled and written for a visitor:

```twig
<dl>
    {% for field in cms_page_fields(cms_page) %}
        <dt>{{ field.label }}</dt><dd>{{ field.text }}</dd>
    {% endfor %}
</dl>
```

The pages online of a type are listed with `cms_pages_of_type`, the model left
out. `sort` is `recent` (the default), `oldest`, `title`, `position` or the code
of a field, pages without a value last; `filter` keeps the pages holding a
value, or a `{min, max}` range for a number or a date; `limit` and `offset` cut
the list. Each card carries its `fields`:

```twig
{% for card in cms_pages_of_type('recipe', {sort: 'cooking_time', filter: {vegetarian: true, servings: {min: 4}}, limit: 6}) %}
    <a href="{{ card.url }}">{{ card.title }}</a> {{ cms_page_fields(card)|map(f => f.text)|join(' · ') }}
{% endfor %}
```

An editor places the same list in a page with the **Pages of a type** section
(`Partial:cms-page-list`), rendered on every visit: a page published later
shows in it without republishing the page that lists it. A theme takes the
markup over with `cms/partials/page-list.html.twig`. From PHP, the list comes
from `PublishedPageRepository::ofType($type, $locale, $query)`.

The description of a type travels with the site export; an import keeps the
fields of a type the site has described already, and says so.

### Components

A theme opens its own Twig components to pages by naming them under
`sections.components` in `config/theliacms.yaml`; their props are read off the
template, and nothing is opened until the theme says so. A component whose
defaults render nothing gets the props it is shown with under
`sections.examples`, which is what a section added by hand starts from. A module adds a
component of its own with one class implementing `SectionDefinitionInterface`.
The catalogue and the shape of a document: [docs/sections.md](docs/sections.md);
adding a component, step by step, and what the generator does with it:
[docs/creating-a-component.md](docs/creating-a-component.md).

A section given an appearance (a background, a text colour, space above and
below) renders inside a band of the whole width,
`<div class="cms-section-styled">`, which becomes the child of the content in
place of the component. A rule of the theme that sets a width on the children
of `.cms-page-content`, or lays sections out in a grid, has to target
`.cms-section-styled > …` as well, or the section spans the whole screen:
[docs/sections.md](docs/sections.md#what-a-theme-does-with-the-band).

### Tags

A page carries one tag or none, picked in the "General" tab among the tags of
the site, which are listed, added, edited and deleted under **CMS > Tags**. A
tag is a name shown as it is in every language — "Recette", "Actualité" — and
managing them takes the same right as editing pages. Deleting a tag leaves its
pages without one; the screen says how many.

A tag may have a color or none. Its color is free: a background, as a
`#rrggbb` code, and a text color, either chosen or left to the contrast, in
which case the text is black or white, whichever reads better on the
background (WCAG 2 relative luminance). The back office shows the tag in a
badge of those colors, grey without one. The codes are checked before they
are stored, so a theme may print them in a style attribute.

Up to 2.6, a tag took one of six names. The update to 2.7.0 turns each name
into the codes of the badge it gave, and a site file written then imports the
same way:

| Former name | Background | Text |
|---|---|---|
| `red` | `#dc3545` | `#ffffff` |
| `orange` | `#f26041` | `#000000` |
| `yellow` | `#ffc107` | `#000000` |
| `green` | `#198754` | `#ffffff` |
| `cyan` | `#0dcaf0` | `#000000` |
| `dark` | `#212529` | `#ffffff` |

The tag is what gathers pages: a theme lists the pages of a tag with
`cms_pages_tagged('Recette')`, which answers with the pages online in the
language served, the most recently published first, each as `id`, `title`,
`url`, `chapo`, `description`, `image`, `pageType`, `tag`, `tagColor`,
`tagTextColor` and `publishedAt`. The page being served reads its own tag as `cms_page.tag`, and
the modules hooked on `cmspage.*` as `page.tag`; the colors of the tag are
`tagColor`, the code of its background, and `tagTextColor`, the code of its
text, both null for a tag without a color. From PHP, the same list comes from
`PublishedPageRepository::withTag($tag, $locale)`.

```twig
{% for card in cms_pages_tagged(cms_page.tag) %}
    {% if card.id != cms_page.id %}
        <a href="{{ card.url }}">{{ card.title }}</a>
    {% endif %}
{% endfor %}
```

The tag follows a page when it is duplicated and travels with the site export,
its color with it; an import creates the tags the site lacks, in the color of
the file, and leaves the color of a tag the site has. The update to 1.3.0 adds
the `cms_tag` table and `cms_page.tag_id`, the update to 2.5.0 `cms_tag.color`,
the update to 2.7.0 `cms_tag.text_color`.

### Twig functions

| Function | Returns |
|---|---|
| `cms_menu(code, locale)` | the tree of a menu: the menu of the language when it has one of its own, the shared menu otherwise (see [A menu per language](#a-menu-per-language)). Each entry has `label`, `url` (null for a heading), `blank`, `children`, `active`, `in_trail`. The locale defaults to the one being served. |
| `cms_page_alternates()` | the page being served, in each language it exists in: `locale`, `code`, `title`, `url`, `current` |
| `cms_pages_tagged(tag, locale)` | the pages online carrying the tag, the most recently published first. The locale defaults to the one being served. |
| `cms_pages_of_type(type, options, locale)` | the pages online of a type, the model left out, ordered, filtered and cut by `options` (`sort`, `direction`, `filter`, `limit`, `offset`): see [Templates of pages](#templates-of-pages). |
| `cms_page_type(type, locale)` | the `code`, `title` and `description` of a type, for the heading of a list. |
| `cms_page_fields(page)` | the fields of a page or a card holding a value, each with its `code`, `label`, raw `value` and `text` written for a visitor ("1 h 30 min"). |
| `cms_item_content(type, id, locale)` | the published document of a product, a category, a content, a folder or a store (`dealer`), as markup, or an empty string. The locale defaults to the one being served. |
| `cms_page_documents(page, locale)` | the files a page offers to download, each with `id`, `title`, `description`, `url`, `extension`, `size` and `originalName`: see [Documents of a page](#documents-of-a-page). |
| `cms_page_documents_block(page, locale)` | the same files drawn as markup (empty string without a file), from the `cms-page-documents.html.twig` of the theme or of the module, with the one variable `cms_page_documents_block` (`documents`, `page`, `heading`): see [Documents of a page](#documents-of-a-page). |
| `cms_is_home(id)` | whether the page of this id is the one the module shows as the home page (setting `home_page_id`). |

Except for `cms_item_content()`, whose document is markup by nature, they
return data rather than markup, because navigation markup belongs to the
theme. A menu of any depth takes a dozen lines:

```twig
{% macro menu(entries) %}
    <ul>
        {% for entry in entries %}
            <li>
                {% if entry.url %}
                    <a href="{{ entry.url }}"{% if entry.blank %} target="_blank" rel="noopener"{% endif %}>{{ entry.label }}</a>
                {% else %}
                    <span>{{ entry.label }}</span>
                {% endif %}
                {% if entry.children is not empty %}{{ _self.menu(entry.children) }}{% endif %}
            </li>
        {% endfor %}
    </ul>
{% endmacro %}

{{ _self.menu(cms_menu('main')) }}
```

Menus are cached per code, language and host, and the cache is dropped whenever a
menu is saved, detached, realigned or sent back to shared, or a page it points
at is renamed, published, unpublished or binned. Whether a menu has entries, which
decides who fills the content slots, is cached per code and language too.

`cms_page_alternates()` is what a language switcher should be built on: it
answers with the current page in each language, absolute and on the right domain
when the shop runs one domain per language, and it leaves out the languages the
page is not published in. It works beyond CMS pages: on a product or a
category it follows the rewritten URL of that object in the other language, and
elsewhere it carries the current path over. A switcher built on a `?lang=`
parameter alone sends the visitor back to the home page, losing the page they
were reading; the same addresses feed the `hreflang` tags, so the two cannot
drift apart.

When [SEOne][seone] is installed, CMS pages describe themselves to it: title,
description, `WebPage` microdata, and a breadcrumb built from the page tree.
Their `hreflang` alternates come from the languages a page is published in.
Without SEOne the module runs unchanged.

## Moving content between sites

Two commands write and read the content of a site as one JSON file:

```bash
php Thelia thelia_cms:export site.json
php Thelia thelia_cms:import site.json [--replace] [--with-settings] [--allow-custom-code]
```

The file holds the page tree, the content of every language, the documents of
the catalogue items, the components made in the back office, the menus, the
forms and their fields and the settings. It is a starter
kit you build once and start the next project from, and it is a copy of the
content to keep somewhere other than the database.

Three things stay behind. Form submissions, because they are what visitors
wrote about themselves and a starter kit ends up on laptops. Third-party
snippets, because they carry the measurement accounts of one site and importing
them elsewhere would send that site's traffic into them. Revisions, because they
are the history of one site rather than its content.

Images travel as file names. Upload them to the media library of the other site
first, under the same names, and the import points the content at them; whatever
is missing is named in the report rather than left as a silent hole.

An import leaves alone whatever is already there: a page at the same address, a
menu, a form or a component carrying the same code. It counts them and says so.
A menu carries `locale`, empty for the shared menu of its code and the locale
of the language for a menu of its own, and is matched on its code and that
key; a file without it holds shared menus. The menu of a language the site
does not have, or whose code has no shared menu in the file or on the site, is
left out and named in the report. Since module 2.6.0 the file is in format
version 3, which an older module refuses rather than write the menu of a
language over the shared one.
A component of the file is checked as if the generator had just written it; one
the site refuses is left out and named. The document of a catalogue item goes
on the item of the importing site with the same reference (a product) or the
same address; an item the site does not have is named and left out, never
created. `--replace`
overwrites them instead.

A file is not trusted any more than an editor is. The markup of the pages and
of the catalogue items, drafts included, goes through the filter of a
publication, and iframes are taken out unless the import is run with
`--allow-custom-code`, the equivalent of the custom code right of the back
office. Their documents are checked as the content screen checks the one it
saves: one it would refuse is left out, and the content keeps only its
filtered markup. A form keeps its code only when the form screen would accept
it, its recipients that are email addresses, and a retention the screen
accepts. Each of these is named in the report.

Settings are only applied when asked for, so importing
a starter kit never switches a running site into showcase mode by surprise. The
whole thing runs in one transaction, so a file that turns out to be broken
halfway through leaves the site as it was.

## Scope

Working today: the page tree with its bin and duplication, the content screen
with the generator, its skills and its ledger, sections edited by hand,
drafts, revisions and shared previews, the catalogue of sections made of the
components of the theme, free text and live content, the publication
pipeline (sanitizer, responsive images, search indexing, heading check),
publication and generation from the command line, the media library, menus, hierarchical URLs
with 301s on rename, forms and their answers, the front-office search,
third-party snippets behind consent, the sitemap section, cache tags, the
showcase dashboard, import and export, showcase mode, maintenance,
the editable 404, the ACL, and the activity log.

## Tests

Working on the module inside a Thelia host, with the caches and the test
pitfalls met on the way: [docs/developing.md](docs/developing.md).
For the people who write the pages: [docs/using-the-ai.md](docs/using-the-ai.md).
Letting Claude Code work in the back office through a Chrome window, and
checking pages as a visitor: [docs/claude-code-and-chrome.md](docs/claude-code-and-chrome.md).
Making the generator give the same page for the same brief:
[docs/plans/deterministic-skills.md](docs/plans/deterministic-skills.md).

The unit tests need neither a database nor an installed shop, only the
autoloader of the project the module sits in:

```bash
vendor/bin/phpunit -c local/modules/TheliaCMS
```

They cover the sanitizer against a corpus of hostile HTML and CSS, the content
normalizer, the responsive image rewriting, the heading check, the signing of
preview links and the slug rules.

The integration tests need a shop, and they run against its test database:

```bash
php bin/test-prepare        # once: creates the `test` database and activates the modules
vendor/bin/phpunit -c local/modules/TheliaCMS/phpunit.integration.xml.dist
```

They cover what only exists once there are rows: the export and import round
trip, the search index, the sitemap entries, the dashboard figures, the
breadcrumb, the rendering of the sections and the generator,
which runs against recorded answers and never reaches the API (see
[docs/generator.md](docs/generator.md)). Each test runs inside a transaction that is rolled back afterwards,
so the content of the shop is left alone. The exception is the search: InnoDB
only adds a row to a FULLTEXT index when the transaction writing it commits, so
those tests commit and clean up after themselves.

The GitHub Actions workflow (`.github/workflows/ci.yml`) runs both suites on
every push and pull request to `main`, on PHP 8.3, 8.4 and 8.5, inside the tip of
`thelia/thelia` with the themes on their development branch and the module in
`local/modules/TheliaCMS`.

Accessibility is checked separately, with axe-core driving a browser against a
running shop. `Tests/Accessibility/README.md` says how to run it and what the
last run found.

## Licence

LGPL-3.0-or-later. See [LICENSE](LICENSE).

[library]: https://github.com/thelia-modules/TheliaLibrary
[seone]: https://github.com/thelia-modules/SEOne
