# The generator

A page is written from a brief: an editor says what the page is for, in their
own words, and the generator answers with a document (see
[sections.md](sections.md)) the site validates, renders and stores as the
draft of the page. Publishing stays a decision of the editor, through the
usual pipeline.

## What it needs

- Thelia 3.3.0 or later, whose AI layer the generator goes through. The
  module holds no key, no provider and no model.
- A connection and a range in the **AI settings** of Thelia: declare the
  connection to a provider once, then give each of the three usages below a
  range of models, turn it on and set its budget. Without that, the generator
  says so (`GenerationUnavailableException`, in the language of the back
  office) and nothing is called. The budget, the rate limit, the
  jurisdiction (providers inside the European Union only) and the guardrails
  of the AI settings apply to every call: when one refuses, the editor reads
  which and what to change there.
- Six settings, in the **Generator** card of CMS > Settings, each with a
  default: `generator_effort` (`high`; `low`, `medium`, `xhigh` and `max`
  exist; passed to the model as its reasoning effort, read by Anthropic models
  and without effect on the others), `generator_max_tokens` (48000),
  `generator_web_fetch` (off: read the web pages a brief cites, below),
  `generator_component_making` (off: let a brief make a component of the
  site, below), and for the texts beside a field `generator_text_effort` (`low`) and
  `generator_text_max_tokens` (2000). The card links to the AI settings.
- The right `admin.cms.generate`, given to the editor profile the module
  seeds: asking the generator to write costs money.

### The three usages

The module declares three usages to the AI layer. Each is set up on its own in
the AI settings, so a site can give the pages a stronger range than the texts:

| Usage | What it writes |
|---|---|
| `theliacms.page_generation` | the document of a page or of a catalogue item: a brief, a change, a section, a translation |
| `theliacms.text` | a text for a rich-text field (a product description, a chapo), from a brief |
| `theliacms.component` | a component made in the back office |

The layer picks the model from the range of the usage, records every request
in its usage log, and the ledger of the module points at the row of the last
request of a call (`cms_page_generation.log_id`); the requests of one call
share a correlation id in that log.

## What the model is told

The system prompt is the same for every call of the site, written in a stable
order so a provider that caches prompts can reuse it: the role, the **skills** (below), then
the **catalogue** — every section with its description, its props and one
example. The user message carries what changes: the name of the shop and what
it says about itself, the language of the page, the currency, the title and
type of the page, the summary the editor typed, then the brief; for a change
on an existing page, the document the page holds and the change asked.

When the page type is a template of pages
([ADR 9](adr/0009-a-page-type-is-a-template-of-pages.md)), a block on the
type comes before the brief of the page: the brief of the type (what every
page of it is about), then its **writing instructions** when it has some,
introduced as "Rules for every page of this type, read as a skill: follow
them in everything you write for this page, and where a brief says
otherwise, these rules win." They are read for every request on a page of
the type (brief, change, section, translation), like a skill only that type
loads; a type without instructions leaves the message as it was.

The answer is constrained by a structured output (a schema handed to the AI
layer, which checks the answer against it) to the envelope of a document: a list of sections, each naming a component of the catalogue
(an enum) and carrying its props as a JSON object written as a string. The
grammar the API compiles a schema into cannot hold the whole catalogue — it
caps optional properties, union types and its own size — so the props are
read back and checked by the validator against the full schema of each
component, and a document it refuses is sent back with every problem listed,
twice at most; a third refusal is an error the editor sees.

While it writes, the model may ask the site:

| Tool | Answers |
|---|---|
| `search_products`, `search_categories` | the products and categories online, by name, reference or barcode |
| `search_pages` | the pages online in the language of the page, with their address, for internal links |
| `search_media` | the images of the media library, by title or tag: the only source of an image id |
| `read_section` | the full sheet of one component |
| `read_skill` | a skill listed as available on demand |
| `make_component` | makes a component of the site when none of the catalogue shows what the brief asks (below) |

A tool is a class implementing `TheliaCMS\Generation\Tool\GeneratorToolInterface`;
implementing it is what offers it. `make_component` is the exception: a class
of its own (`Generation/Tool/MakeComponentTool.php`), offered only when the
setting `generator_component_making` is on, the editor may make components
(settings and custom code resources, `Content/Site/SiteComponentPermission.php`)
and the request is not a translation, twice per generation at most. It hands
the request to the generator of components (a nested call, up to a minute,
filed in the ledger as a row of its own under `target_type` `component`),
keeps the answer as a prototype of the site (status `draft`), resets the
catalogue and answers with the sheet of the component; the schema of the
answer is then built again so the model may name it. A prototype is shown on
the pages that hold it and offered to nobody else until **CMS > Components**
keeps it for the whole site.

### Web pages the brief cites

A brief, a change or a section asked again may cite web pages by their
address (`http://…` or `https://…`): "take our story from
https://example.com/about, shorter". **Reading them is off by default**, and
the site turns it on with **Read the web pages a brief cites** in the
Generator card. The reason: a page read is fetched by the server of the shop,
and its text is sent to the provider of the range, which may be outside the
European Union. A site that has to keep what it sends inside a perimeter, or
whose server sits on a private network, should leave it off. The addresses then
stay in the brief as text, and the generator is not offered the tool.

When it is on and a brief cites an address, the user message lists them and
asks the model to read each before it writes, and the request offers
`read_url`. The tool runs on the server, through the HTTP client of the core
that refuses private, loopback and link-local addresses when the name is
resolved (a page of the internal network cannot be reached through a brief),
checks every redirect the same way, and is bounded in size, in time and in the
number of redirects. Five pages at most per request, only the ones the brief
cites (a redirect may not leave the host of an address cited); each is cut at 40,000 characters. What a
page says is handed to the model as untrusted content: a given of the brief,
never an instruction. A page that cannot be read (private, behind a login, an
error) is left out and said so in the history. The address of a picture (a path
ending in `.jpg`, `.png`, `.webp`, `.gif`, `.svg` or `.avif`) is not a page: it
is not offered for reading, and goes as it is into an image prop. A translation
cites none: its source is the document of the other language.

## Skills

A skill is a piece of instructions the generator reads before writing: how a
page is structured here, how text is written, what accessibility asks, what
search engines read, how a page is translated, how it stays within the design
system. The module ships eight under `skills/<code>/SKILL.md` (`catalogue`,
`page-structure`, `copywriting`, `accessibility`, `seo`, `translation`,
`design-system`, `motion`), a
theme may ship its own under `skills/` with the same layout, and a theme skill
with the code of a module skill replaces it.

The files are a seed: the first read sows them into `cms_skill`, and the
site edits them there, under **CMS > Skills** (the settings right). A skill
can be rewritten, switched off, moved up or down from the arrows of the list,
and put back to the shipped text;
a shipped file that changes updates the stored text unless the site rewrote
it. The generator reads the table, never the files.

```markdown
---
name: copywriting
description: How to write the text of a page.
always: true
---

# Writing the text
…
```

`always: true` puts the whole skill in every prompt; the others are named with
their description and read through `read_skill` when the page calls for them.

The files are the seed, the table `cms_skill` is what the site reads: the first
read sows the files, and a site edits a skill under CMS > Skills — its text,
whether it is on, its order — with "restore the shipped text" to come back.
A file that changes afterwards updates the shipped text kept next to the
site's, and the text itself only where the site never rewrote it.

## The screen

CMS > Pages > a page > **Edit content** (`/admin/cms/pages/{id}/content`).
The brief on the left, the draft on the right as the theme shows it, through
the signed preview link of the draft.

- The catalogue the generator writes from is the theme's first: the
  components the theme opens in `config/theliacms.yaml` (with the sections of
  the module the theme puts away under `hidden`), then the sections and the
  partials of the module (`docs/creating-a-component.md`).
- **See every section the generator can use**: a link, shown in debug or with
  `THEME_TOOLKIT` on the Flexy theme, to the page the module hangs in the toolkit
  (`/toolkit/cms-all-sections`): every section with its sample, live content
  named and described. A brief that names a section asks for it.
- **Write it from a brief**: what the page should say, its tone and audience.
  The answer replaces the whole draft. The form holds the brief the page was
  last written from in this language (kept in the ledger, column `brief`),
  so a page is written again from its own brief, corrected.
- **The sections of the draft**: each one numbered and quoted by its title,
  with a form to rewrite that section alone; the others are kept as they
  are, ids included. A section is also removed by hand, after confirmation.
  The number of a section is shown on it in the preview when the pointer is
  on its row, and the row is lit when the pointer is on the section; the
  label of a row scrolls the preview to the section. The renderer puts
  `data-cms-section="<id>"` on the first element of every section for that
  (`Content\DocumentRenderer::SECTION_ATTRIBUTE`, kept by the sanitizer).
- **Ask for a change**: a change on the whole draft, same rule.
- **Add a section > A new component**, for an editor who may make components
  (settings and custom code resources): **Prototype a component** asks the
  generator of components in a few words. The answer is drawn in the preview
  in place of the draft, as the theme shows it, with its props listed; the
  editor asks a change, discards it, or keeps it: the component is stored as
  a prototype of the site (status `draft`), a section of it is added at the
  end of the draft with its sample text, and the ledger holds a `manual` row
  for the addition. The candidate lives in the ledger
  (`Generation\Component\ComponentCandidates`) and is named on the screen by
  `?component=<id>`, so the preview and the forms work without the script
  (`Generation/Admin/CmsPageComponentController.php`).
- **Written from another language**: when the page holds nothing in the
  language on screen and a draft in another, the stage offers to write this
  language from it (`GenerationRequest::translation`): the same sections,
  components, ids, images and references, the texts written for the new
  reader rather than word for word. One language is picked by default, in
  the order of the shop; a select lets the editor choose when there are
  several. The brief form stays at hand for a page written on its own.
- **History**: the calls of the ledger for this page and language, with their
  outcome, turns, duration and cost, what each one left out of the brief, and
  what it needed ("2 corrections, 1 retry, read_url ×2, effort high"). A call
  that runs or that died shows `Running` or `Interrupted`.
- **Publish**: the usual pipeline, with the heading check.

Every action is a form that posts and comes back with a message, so the
screen works without its script. With it, the forms post in the background
and the progress of the call is shown as it runs: the answer is a stream of
server-sent events (`start`, `turn`, `delta`, `correction`, `cut`, `done`, `error`),
written by `Generation\Admin\GenerationStream` as the generator reports each
turn, and every few hundred characters of the answer, to its
`GenerationObserverInterface`: the generator reads the answer of the AI layer as a
stream (`Generation\Ai\ObservedStream` tells the observer how much has arrived
and hands back the final response). When
the call ends, the script fetches the screen at `?done=<id>` and puts it in
place of the one on show, so the address bar, the scroll and the open panels
stay as they were; without the script, the browser is sent to that address.
Either way the outcome is read from that row of the ledger: a stream can
leave no flash message behind, and the ledger says more. A draft that leaves
out part of the brief — an image the media library does not hold and the
brief gives no address of, a product the search does not find — comes back
as a warning listing what is missing, in the words of the model: the answer
carries it in an optional `left_out` list next to the sections, and the
editor learns it there rather than by reading the page.

Each section also opens a form to **edit it by hand**, read from the schema
of its component (`Generation\Admin\SectionFormBuilder`): a field per prop,
a textarea the Tiptap module upgrades for a `html` prop when the module is
there (`thelia/tiptap-module`, suggested, not required), rows added and
removed for a list prop, a picker on the source of a partial for a
reference. An image is chosen in the media library, with a preview, or
replaced by a file that joins the library on the spot (the media right to
create is needed), or given as the `https://` address of a picture kept
elsewhere, stored as it is and served from there without resizing. What comes back goes
through the same validator as a generated document, is stored through the
writer, and is written to the ledger as a `manual` row with no cost, so the
next request starts from the corrected text. A word corrected by hand costs
no generation, and takes the right to change pages, not the generate right.
With the script, the form posts in the background and the screen it comes
back with is put in place, the panel still open, as at the end of a
generation. The form opens in a drawer over the left panel, the preview
staying in sight, its fields on two columns once the drawer is wide enough
(a container query on the drawer): a text of several lines, a text longer
than the default 255 characters (`data-cms-long`), a picture, a list of rows
and a folded part take the line. The rows come from the module's form theme
(`content/_section_form_theme.html.twig`, last in the list so it wins over
the back-office theme): the label with a "?" beside it, the field, then the
errors under the field, the three parts of every field of a line in the same
three rows of a subgrid, so the fields of a line are level. The help is a
tooltip on the "?" (`role="tooltip"`), shown on hover and on focus by the
stylesheet and held open by a click by the script.

Opening the screen takes the page right; asking the generator takes
`admin.cms.generate`; publishing takes the right to change pages.

## The ledger

Every call writes a row of `cms_page_generation`, from its start to whatever
its outcome (see "The life of a call" below): the
page and the language, the kind of request (brief, revision, section), the
prompt, the model, the tokens read, written and cached, the turns, the cost in
US dollars as the usage log of the
core recorded it (the module has no price list: it adds up what the core says
the requests of the call cost, and keeps the id of the last request in
`log_id`), the duration, the stop reason and, for a refusal, its category, the
document produced, the mark of a generated content (`provenance`, see the
README) and the web pages the call read (`web_pages`): each address, the error
code of a page that could not be read, and the size of the text read. A row
written before 2.4.0 has no `log_id`. The prompt is emptied by
`maintenance:purge` once the core has emptied its own (README, *Prompts and
their retention*). What the
answer said the page leaves out is kept as a JSON list of sentences
(`left_out`), at most ten of 300 characters. `GenerationLog::history()` is what a page screen reads;
`spentSince()` what a budget alert reads.

### The life of a call

- **Statuses.** `running` (written before the first request, touched at every
  turn so `updated_at` moves, and holding the tokens, turns and cost so far),
  then `done`, `refused` (the model or a safety check declined), `invalid` (a
  document the site cannot store, after the corrections), `failed`, or
  `interrupted`. The row is completed in place: one call, one row.
- **Failed.** Every exit writes its outcome: the known failures of the AI layer
  and of the generator, and any other error (Propel, a `TypeError` in a tool),
  which is logged with its trace, written `failed` with a sentence for the
  editor, and rethrown as a `GenerationException` with the error as previous.
  A `$save` of the screen that fails after the row says `done` is logged and
  answered with an error event; the row stays `done`.
- **Interrupted.** A `running` row not touched for 30 minutes
  (`GenerationLog::STALE_AFTER`) is a call whose process died (memory limit,
  killed worker). Nothing watches it: the ledger writes `interrupted` when it
  reads it (`history()`, `find()`, the guard, and the component candidates),
  leaving `updated_at` at the last sign of life. Its cost is what the last
  touch recorded.
- **One call at a time.** A page or catalogue item call is refused while a
  fresh `running` row exists for the same target type, id and language: a
  `GenerationUnavailableException` without a row, "A generation is already
  running for this page in this language (started at … by …)". Texts and
  components are not guarded (new components all share the id 0). It is a
  check then an insert, with no lock: two calls a few milliseconds apart can
  both pass.
- **A stream cut half way is asked again once.** `ObservedStream` tells a cut
  (a retryable `AiProviderUnavailableException` after at least one event, or a
  stream that ends without `StreamCompleted`) from a failure before any event,
  which the core has already retried by itself and which stays a failure. On
  the first cut the generator sends the same conversation again; it counts as
  neither a turn nor a correction, and the observer is told (`onCut()`, the
  `cut` event). A second cut fails with the usual message. Texts are read as a
  whole, not as a stream: they are never cut.
- **Cost of a request that failed or was cut.** When a core exception names a
  usage log row (`logId`), its cost (`Thelia\Model\AiUsageLog::getCost()`, priced
  from the tokens received) is added to the cost of the call.
- **What it needed**: `effort` (what was asked of the model), `corrections`
  (documents sent back), `retries` (cut answers asked again) and `tools` (a JSON
  object `{name: count}`).

## Components made in the back office

`Generation/Component/ComponentGenerator.php` makes a component of the site
from a request (see [creating-a-component.md](creating-a-component.md#a-component-made-in-the-back-office)).
Same gateway, effort and log as the pages, under its own usage
(`theliacms.component`); no tool of its own, though the generator of pages
may call it through `make_component` (above). The system
prompt (`ComponentPrompt`), cached, gives the JSON form of the props, the rules
of the sandbox and of the stylesheet, the colour tokens read from
`assets/blocks.css`, the accessibility skill and an example; the user message
gives the shop, the components the catalogue already has, the request and,
for a change, the component as it is. The answer is constrained to a schema
of seven strings (`code`, `label`, `description`, `props` and `sample` as JSON
written as a string, `template`, `css`). A component the validator refuses,
a new one whose code is taken, a change that renames its component or that
breaks the pages using it goes back with every problem listed, twice at most.

Its calls are in the ledger with the pages, under `target_type` `component`:
`target_id` is the row of the component, 0 for one not kept yet, and the
call a new component came from is filed under it once it is kept. A
candidate is such a row, its `document` holding the component; nothing else
stores it until it is kept.

A component kept from the content screen, by the editor or by the tool, is a
**prototype**: status `draft` in `cms_component`. `SectionCatalog::all()`
holds it, so the pages using it render, validate, edit and export it;
`offered()` leaves it out, so no other page is offered it. **CMS > Components**
lists it with a **Prototype** badge and **Keep for the whole site** (route
`admin.cms.components.site.promote`, `SiteComponentRepository::promote()`),
which sets it `ready`: from then on it is a component of the site like the
others. An export writes the status of every component and an import keeps it.

## From the command line

```bash
php Thelia thelia_cms:generate --page 12 --locale fr_FR --brief "Une page qui présente nos services…"
php Thelia thelia_cms:generate --page 12 --locale fr_FR --brief brief.md --revise
php Thelia thelia_cms:generate --page 12 --locale fr_FR --brief brief.md --dry-run
php Thelia thelia_cms:generate --item product:42 --locale fr_FR --brief brief.md
php Thelia thelia_cms:generate --page 12 --locale en_US --translate-from fr_FR
php Thelia thelia_cms:generate --page 12 --locale fr_FR --brief brief.md --effort medium --dry-run
```

The brief is a text or the path of a file. `--revise` reads it as a change on
the document the page holds; `--dry-run` prints the document and saves
nothing. `--item <type>:<id>` writes the document of a product, a category, a
content or a folder instead of a page. `--translate-from <locale>` writes the
language from the draft the target holds in another one, as the button of the
back office does, and takes no brief. `--effort` sets the effort of this call
over the one of the settings, which stay as they are. The draft is saved
through the writer, like a save from the back office; `thelia_cms:publish` puts
a page online.

## Tests

Nothing in the test suites reaches a provider: the generators take the
gateway of the core (`AiGatewayInterface`), and the tests hand them
`Tests/Support/FakeAiGateway`, which answers from a queue and keeps every
request for the assertions; `cutAfter()` queues a stream that gives some
events and then fails, as a provider dropping half way does
(`GenerationRunTrackingTest`).
The quality of what the real model writes is measured by hand, against a
rubric: `docs/evaluation/`.
