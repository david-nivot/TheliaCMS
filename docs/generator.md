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
  connection to a provider once, then give each of the five usages below a
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

### The five usages

The module declares five usages to the AI layer. Each is set up on its own in
the AI settings, so a site can give the pages a stronger range than the texts:

| Usage | Range declared | What it writes |
|---|---|---|
| `theliacms.page_generation` | advanced | the document of a page or of a catalogue item: a brief, a change, a section |
| `theliacms.page_translation` | standard | the same document written in another language of the site, its sections, components and images kept |
| `theliacms.text` | standard | a text for a rich-text field (a product description, a chapo), from a brief |
| `theliacms.component` | advanced | a component made in the back office |
| `theliacms.guardrail_reference` | fast | nothing: one word, the verdict of the judge on a file joined to a brief (below) |

A translation rewrites texts only, so it is declared at the standard range; a
site that wants the advanced one for it raises the range of the usage in the AI
settings. A budget or a model pinned on `theliacms.page_generation` does not
cover translations: they are set up on their own usage.

The cache of the prompt is the provider's, set by the AI layer: on Anthropic,
Thelia asks for the short retention (five minutes) on every connection, and the
module cannot ask for another. Calls a few minutes apart, such as the pages of
a brief written one after the other, read the system prompt and the tools from
that cache; a call after a longer pause writes them again.

The layer picks the model from the range of the usage, records every request
in its usage log, and the ledger of the module points at the row of the last
request of a call (`cms_page_generation.log_id`); the requests of one call
share a correlation id in that log.

## When the AI is not available

The screens of the back office draw an entry that calls the AI (a button, a
link, a form, a panel) only when the AI can serve its usage. Everything else
works as before: a page is written, edited, published and translated by hand.

- **The signal.** `Generation\Ai\AiAvailability::isAvailable($usage)`: the
  master switch of the AI layer (`AiSettings::isEnabled()`), then the usage
  turned on in its own settings (`AiUsageRegistryInterface::isActive()`), then a
  connection and a model that serve it (`AiTargetResolver::resolve()`, which
  throws `InactiveAiUsageException` without them), then, when the shop accepts
  the European Union only, the jurisdiction of that connection. These are the
  checks the gateway makes at each call, in the same order, so an entry is
  hidden exactly when its call would be refused for one of them.
  `isActive()` alone is not enough: it does not look at the model, and a usage
  turned on without a model passes it.
- **Once per request.** The answer is kept by usage for the request, however
  many buttons a screen has, and forgotten between two requests of a long
  worker (`ResetInterface`). The service is public, and not final, so the tests
  of the screens can replace it.
- **In the templates.** `cms_ai_available('theliacms.component')`, a Twig
  function answered by `Twig\AiAvailabilityRuntime`. The usage is the one the
  entry's call runs under:

| Entry | Usage |
|---|---|
| Write from a brief, change the brief, ask for a change, rewrite a section (content screen of a page or of a catalogue item) | `theliacms.page_generation` |
| Write this language from another, write it again (stale translation) | `theliacms.page_translation` |
| Prototype a component from a page (add-a-section drawer, panel of a prototype); New component, ask a change (CMS > Components) | `theliacms.component` |
| Write from a brief beside a rich-text field (hook `wysiwyg.js`, `TextBriefHook`) | `theliacms.text` |

- **Not hidden.** The routes: none depends on the hiding. Called by a stale
  tab or a script while the AI is off, each answers with its own refusal
  (`GatewayFailures`, "The generator is turned off, or no AI connection and
  model is set up for it", a 503 for the text route), never a 500. The skills
  and the settings of the generator stay: they are configuration, and the
  screens that describe what the generator reads. The **Generator** card of
  CMS > Settings (and only it) carries one line for an administrator when a
  usage is unavailable: why (turned off for the whole shop, turned off for the
  usage, no connection or model, unreadable key, jurisdiction) and which usages.
  The core has no back-office screen of the AI settings to link to: the line
  names them.
- **Limit.** The monthly cap and the budget left are not looked at. An
  entry stays when the cap is reached, and the call says so
  (`AiBudgetExceededException`); a screen that hid its buttons at the ceiling
  would hide them for a reason that goes away by itself.

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

A document the validator accepts is well formed, not true: the model may write
an image id nobody uploaded, a form that does not exist, a `/contact` the site
does not answer. `Generation\DocumentChecker` runs after the validator, for every
kind of request, and looks up:

- an image id in the media library (an https address is never fetched: the check
  makes no call outside the site);
- a reference in the table its prop names, from the picker of the prop (product,
  category, folder, menu, form, page), a form or a page in the bin counting for
  nothing;
- an internal address (a path of the site): the home page, a page of the CMS that
  is online and reachable, a URL the core rewrote, or else a path the router
  resolves to a route of the shop (`/login`, `/checkout/cart`). The catch-all view
  route of Flexy (`flexy_view`, `DocumentChecker::CATCH_ALL_ROUTES`) takes any
  path, so a path only it takes counts when the active front theme has that view
  (`/contact`, `/sale`) and does not declare it internal (`/base`,
  `/checkout-cart`), the test the core's `ViewRenderer` makes; a site without
  Flexy excludes no route;
- the headings of the rendered document, as the theme draws it under the title of
  the page (`HeadingChecker`, `underTitle`), like the publication does.

What the document held before the request (a revision, a section) is not the
model's, and is not checked; a translation copies its images, references and
addresses from its source, so only its headings are. A problem goes back to the
model as a correction, in the same count as a document refused (two at most).
Past it the generation still succeeds, because the page is paid for: an image, a
reference or an address is emptied, a row of a list that cannot do without it is
dropped, a section that cannot do without it is left out (a section written alone
is kept as it was), and one sentence per problem is added to what the page leaves
out, in the language of the page. A heading problem changes nothing in the
document: it is said, and the editor mends it where it shows. A problem of shape
keeps failing the generation after the corrections, as before.

A page written from a brief and a page translated are also asked for the title
and the description search engines show: two properties of the envelope,
`meta_title` (60 characters at most) and `meta_description` (155), read as plain
text on one line. They are required when asked, an empty string saying there is
nothing to propose: held to a strict schema, the model leaves an optional key
out, and none was written on the real calls checked while they were optional. They are written to `cms_page_i18n`, in the language
of the call, only where the page holds none yet in that language
(`CmsPageWriter::saveSeo()`): what an editor wrote is never replaced, and they
are live as soon as saved, like the fields of the page type. One that is empty or
longer than its limit is ignored: it is no reason for a correction turn, and the
page does without. A change, a section and an item of the catalogue (whose meta
belong to the core) are not asked for them. `thelia_cms:generate` prints them on
a line of their own, as JSON, and writes them unless `--dry-run`.

A **translated page** is also asked for its own header, in the same call: the
title, the summary (`chapo`), the description and the two sharing texts
(`og_title`, `og_description`) of the page in the source language go in the
prompt (`Generation/PageHeader`), and the answer carries five properties,
required when asked for the same reason, `title`, `summary`, `description`,
`og_title`, `og_description` (an empty string for nothing to propose). The
titles are read as plain text on one line (255 characters at most), the sharing
description likewise, the summary and the description as HTML; one that is
empty (an HTML field with no text included), not a string or longer than its limit is ignored, never a reason for a
correction turn. Only a page whose source header says something is asked, and
only a page: the header of a catalogue item is the core's. The writer
(`CmsPageWriter::saveTranslatedHeader()`, done by `PageHeaderTranslator`) **fills
and never replaces**: a column that holds something in that language stays as
its editor wrote it, for the header is live as soon as it is saved and keeps no
revision to put back. The summary and the description go through the filter an
edited page goes through (from the command line nobody holds the right to custom
code). The address is derived from the title the language holds, under the
address of the parent in that language, **only where the language has none**:
no address, no slug and no fixed address. A page that already has an address,
online or not, keeps it and no redirection is made; a title that is a reserved
path writes the texts and no address; the pages under it already addressed in
that language follow it on its first address. A model of a page type gets its
texts and no address. What was written and what was kept is said on the screen
the editor comes back to (carried by the `header` parameter of its address, a
stream leaving no flash message behind), and `thelia_cms:generate` prints the
proposed header as a JSON line of its own and writes it unless `--dry-run`.

Two requests are held tighter than a brief. A **section asked again** is
constrained to one section, the shape of an item of `sections`, and its prompt
says to answer with that section alone, the whole document staying in the
context. The answer must carry the id asked (another id is sent back as a
correction); its component is free; the appearance is the one of the section it
replaces unless the answer sets one (a brief asking for a colour in words);
`Document::withSection()` grafts it in the document, which goes through the
validator once more. A **translation** is written onto its source
(`Generation\TranslationGraft`): the sections, their order, ids and components
are the source's, and any difference is sent back as a correction; only the
`text` and `html` props are read from the answer, in the rows of a list too,
and `json` too, since it may hold text; an `image`, a `reference`, a `url`, an
`integer`, a `boolean` and a `choice` are copied from the source whatever the
model wrote, and so is the appearance.

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
is not offered for reading, it is a picture to import (next section). A translation
cites none: its source is the document of the other language.

### Pictures the brief cites: `import_image`

A brief that cites the `https` address of a picture ("import this image:
https://example.com/banner.jpg") ends with that picture copied into the media
library and used by its library id, never with the external address in the
page. Under the same setting as `read_url` (**Read the web pages a brief
cites**), when the brief cites at least one picture address, the request
offers `import_image` (`Generation/Tool/ImportImageTool.php`), the user message
lists the pictures cited and says an image prop takes the id the tool gives.
It takes the address, a title, a text for what the picture shows and a credit
(the last two may be empty), and answers with the id and the editor form of
the address (`/image-library/<id>/…`), or with a failure code and a short
reason (`url_not_cited`, `url_not_allowed`, `blocked_address`,
`url_not_accessible`, `too_many_redirects`, `too_large`, `unsupported_type`,
`too_many_pixels`, `refused`, `import_failed`), never an error: the picture is
then left out.

- Only an address the brief cites is imported, `https` only. The download goes
  through the same client as `read_url` (private, loopback and link-local
  addresses refused when the name is resolved, every redirect checked, and a
  redirect may not leave the host of the address cited), bounded in time, and cut
  one byte past the ceiling rather than waited for.
- The bytes decide, not the extension or the Content-Type: JPEG, PNG or WebP
  (the formats of the upload routes; a GIF is refused like there), up to 10
  MiB and 25 million pixels, the limits of the upload routes.
- The image is stored by `CmsMediaWriter::add()`, so it is tagged for the CMS
  library like an upload; the title and the credit (as caption) are set, and
  the alternative text by `describe()`.
- The same address imported twice in one call gives the same id, without a
  second download. At most the pictures cited can be imported.
- Not done: the ledger does not record the imported images (id, source
  address, credit), which needs a column of `cms_page_generation` and so a
  migration. The images are in the library, tagged `cms`, with their credit in
  the caption.

### A file joined to the brief or to a change

The brief of a page or of a catalogue item takes a file, `.md`, `.txt` or
`.pdf`, of up to 100,000 characters of text (five times the brief;
`Generation/ReferenceFile.php`, no setting): a product sheet, a page of the old
site, a note from the client. The file is read once from the upload and never
written to disk. A text file in UTF-16 with its byte order mark, or in
Windows-1252 (what Windows writes French in by default), is read into UTF-8.
A file of another extension, holding a NUL byte or a control character a text
does not hold (a binary file renamed), blank or too long is refused before
anything is called, with the reason on the
screen and no row in the ledger; the browser refuses a file above the ceiling
before sending it, naming the limit that plays, the module's (5 MB, the one of
a PDF) or PHP's when it is lower. A text file is held to 400 kB on the server.

A PDF is told by its content, not its name: a file that does not start with
`%PDF-` is refused as unreadable. Its text is extracted by
`Generation/ReadableText.php` (smalot/pdfparser, as for a PDF the brief cites by
its address), its images left out: the gateway of the core carries text only.
A PDF the parser cannot read (damaged, protected by a password) and a PDF
without text (a scan) are refused with their reason; the 100,000 characters
hold for the extracted text. From there it goes the way of a text file.

The parser holds every stream it inflates, and a stream of pages costs it up
to 37 times its size (measured on the host, 2026-11-09: a page stream of 16 MB
inflated takes 595 MB and 6.9 s, one of 4 MB 148 MB): a PDF of 160 kB holding
five such streams stopped PHP on a memory limit of 512 MB, a fatal error no
`catch` reaches. So the streams are weighed before the parser sees them
(`ReadableText::isTooHeavyToParse()`, a stream at a time, a kilobyte of input
inflated at a time): 4 MB at most for the streams read for text, 64 MB for
all of them, images and fonts included. A heavier PDF is refused as too heavy
to read, and a PDF the brief cites by its address is unreadable. A PDF of
100,000 characters, the most a file holds, weighs far less. The densest PDF
measured under the ceiling, 4.5 MB and 4,000 pages of uncompressed text
(3.7 million characters), is now refused before parsing, rather than parsed in
1.8 s for 49 MB and refused as too long.

The text goes to the model as it does for a page read: an `UntrustedContent`
of source `document`, with the name of the file as reference, on the request
from its first turn, and kept beside the pages `read_url` adds. The AI layer
wraps it in delimiters that name the source and tells the model it is data;
the module adds nothing to the message but a block that says the brief comes
with a file, that what it says counts as given by the brief, and that it is
material to read, never instructions. The name of the file is not in that
block: it is chosen by whoever made the file, so it reaches the model only as
the reference of the wrapping, which the AI layer escapes. The length of the file counts in the
budget the AI layer reserves, not in the limit on what a person types.

The ledger keeps the name, the length and a fingerprint of the file in the
JSON of the brief, never its text; the AI layer keeps the text with the prompt
of the call when the merchant keeps prompts, for as long as it keeps them. The
recall of the brief names the file; the form does not put it back, and a page
written again from its brief is written without it unless the editor joins it
again.

"Ask for a change", on the whole draft or on one section ("Rewrite this
section"), takes a file the same way, with the same ceiling, the same
refusals and the same judge: a change of the whole draft (a revision) may come
with new prices, a product sheet, a note, read as material for the change; the
change typed in the form stays the only request, and the block of the message
says so. The ledger keeps the name of that file in the JSON of the change; the
recall of the brief never shows it, since it reads the latest brief only. The
file field of a section is named after it (`cms_change_<section>[reference]`),
as its text is. A
file joined to a change says what to draw from, not what to do: a file that
gives the change itself ("replace the prices by…") reads as instructions and is
refused.

The request of a component prototyped from the content screen (**Prototype a
component**, **Ask a change** of the candidate, **Ask again** after a failure)
takes a file the same way: posted as `reference` beside `request` to
`CmsPageComponentController::generate()`, read by the same `ReferenceFile`,
refused before the call with the same reasons (no row of the ledger), sent as
an `UntrustedContent` of source `document` by `ComponentGenerator`, whose usage
`theliacms.component` lists the same judge in `block` mode. The message of
`ComponentPrompt` ends with the same block ("the request" or "the change
asked" is the only request), the name of the file left out of the message. A
file is refused, before it is read, when the guardrails the merchant chose for
`theliacms.component` leave `reference_file` out (`ReferenceFileJudgeSetting`
reads that usage). The ledger keeps the name, length and fingerprint in the
JSON of the request of the component, never its text. A refused file
or a failed candidate is not put back in the form. **CMS > Components** and the
`make_component` tool of the brief take no file.

### The media the generator of components is offered

The user message of `ComponentPrompt` has a section **Pictures and videos for
the sample**, between the components of the site and the request (or the
component to change), so the sample of a component draws its pictures. It lists
the media the CMS offers its editors (`Generation\Component\SampleMedia`, over
`CmsMediaLibrary`): the 12 most recent pictures and the 3 most recent videos
at most, one line each (`- 12 (image, 1600 × 900): <text>`), the text being the
alternative text of a picture or the title of a video, in the language of the
request and then in the default language of the shop, cut to one line of 120
characters. The section is left out when the library holds nothing.

The rule of the system prompt follows: an `image` or `video` prop of the sample
takes the id of one of those media, chosen for what it shows, or null when none
fits or when there is no list; an `image` may still take an `https` address the
request gives. The schema of the answer is unchanged. A change of a component
is offered the same list. The list is read at each call, after the cached
system prompt, so it never breaks the cache. The bench fills the library with
`thelia_cms:component-bench:media` (`docs/evaluation/components/README.md`).

A file that instructs rather than documents (it addresses an assistant, asks
to act or to ignore rules, or has nothing to do with a page to write) is
refused, not only neutralised, by `Generation/Guardrail/ReferenceFileGuardrail.php`:
an optional guardrail of the AI layer named `reference_file`, listed in
`block` mode by `theliacms.page_generation` and `theliacms.component`, modelled on the topic guardrail of
the core. It runs on the first turn of a request that holds a `document`
content, as a call of the fast range under the usage
`theliacms.guardrail_reference` (system audience, no optional guardrail, the
correlation id of the call it judges): the request of the editor and the file
reach the judge as untrusted data, and one word is read in its answer,
`reference` or `instructions`. `instructions` fails the check with the reason
`reference_rejected`; any other answer or a judge that cannot run fails it
`unavailable`, and in `block` mode the call is refused, as the AI layer wants
it. The judge reads the whole file, a slice of 20,000 characters per call, the
slices overlapping by 1,000 so that a sentence cut at the edge of one is whole
in the next: every call stays within the smallest models of the fast range (8k
tokens), a file of 100,000 characters takes six calls, most files one, and the
first slice that instructs, or cannot be judged, settles it without the rest
being paid for. The merchant sees the usage in the register, its verdicts in
the AI log, and may set the mode of the guardrail on the usage of pages.

A merchant who chooses the guardrails of the usage of pages replaces the list
it declares (the AI layer runs the ones chosen): a choice that does not name
`reference_file` would let a file through unjudged. The controller refuses a
joined file then, before reading it, and says where to add the judge
(`Generation/Guardrail/ReferenceFileJudgeSetting.php`, which reads the setting
the way the core does: an empty or unreadable choice keeps what the usage
declares). A judge chosen in `annotate` mode is the merchant's choice and is
kept. No keyword list:
it can be walked around and refuses honest files ("delete" in a page about
ending a contract). A judge is a model and can be fooled: the protection is
the wrapping of the gateway and the editor reading the draft.

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
  with a form to rewrite that section alone; the model answers with that
  section only, grafted in the document, so the others are kept as they are,
  ids included. A section is also removed by hand, after confirmation.
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
  components, ids, images and references (copied from the source, not read
  from the answer), the texts written for the new reader rather than word for
  word. One language is picked by default, in
  the order of the shop; a select lets the editor choose when there are
  several. The brief form stays at hand for a page written on its own.
- **Translations that are out of date**: a language written from another one
  keeps which one (`translated_from`) and the words of that draft then
  (`source_fingerprint`, a JSON object holding the SHA-256 of the words of
  each section by section id and, for a page, the SHA-256 of the words of its
  header under `header`), on `cms_page_content` and `cms_item_content`
  alike (`Content\Editing\TranslationFreshness`). The words are the props a
  translation writes, the ones `TranslationGraft` reads from the answer: an
  image, a colour, a link, a number or a move of a section is not a change
  (`Content\TranslatableWords`). Never stored as a status: compared when the
  screen, the list of pages or the dashboard is read, with the draft of the
  source as it is then. Once the words differ, the panel says so, names the
  sections that changed, were added or are gone, and offers to write this
  language again from the source (the draft it replaces is kept with the
  previous drafts) or to mark it up to date, which writes nothing in the
  translation and asks nothing of the generator; put back as it was, the source
  is up to date again. A language written by hand is followed once tied to
  the one it translates, from the same panel. The list of pages shows
  "Content to translate again" on the row, and the dashboard of a showcase site
  counts these pages for each language (`Page\StalePages`: one query for the
  translated rows and their source drafts, the words compared in PHP; pages in
  the bin and the models of the page types are left out). Items of the
  catalogue have it on their content screen only: the module has no list of
  them. It is the draft of the source that is compared, not what is online. For
  a page, the header of the source (title, summary, description, sharing title
  and description) is compared as one more entry, named in the panel as the
  header of the page; the other meta fields, the address and the fields of the
  page type are not. Writing the language again from the source fills only the
  empty fields of the header, as the first translation does, but records the
  whole header of the source as followed: a header already filled in that
  language is not rewritten and is no longer named in the panel, so the editor
  updates it by hand. A fingerprint stored before the header was followed has no
  `header` entry and follows none: such a translation is not made stale by the
  header until it is written again or marked up to date. The
  command `thelia_cms:generate --translate-from` keeps the draft it replaces
  and records the source the same way, and the copy of a page carries the link
  (with the header of the copy of the source, whose title is not the original's).
  The export and the import do not: a site restored from an export starts with
  nothing followed.
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
gives the shop (with the language of the sample, the one of the request, and
the default language of the shop), the components the catalogue already has,
the request and, for a change, the component as it is. The labels an editor
reads in the back office (the label of the component, the label, help and
choice labels of each prop) are asked in the default language of the shop,
whatever the language of the request; the sample stays in the language of the
request. This holds for a component asked from the back office and for one
the generator of pages makes from a brief or a page prototypes. The answer is constrained to a schema
of ten strings: the `outcome` (an enum), `existing` and `reason`, then the
seven of the component (`code`, `label`, `description`, `props` and `sample`
as JSON written as a string, `template`, `css`). A component the validator
refuses, a new one whose code is taken, a change that renames its component or
that breaks the pages using it goes back with every problem listed, twice at
most.

The `outcome` says what the model made of the request, and with `existing` or
`copy` every field of the component is empty:

- `component`: a new component (or the change asked). The default when an
  answer, written before the outcomes, has none.
- `existing`: a component of the catalogue already does what is asked, the
  request differing by its wording or its example. `existing` holds its name,
  checked against `SectionCatalog::all()`, and `reason` says in a sentence what
  it already does. The result has no component (`ComponentResult::$component`
  is null, `outcome` is `existing`, `existing` is the name); the screens say
  that the component does it already, with a link when it is a `Site:` one, and
  show no candidate; the tool of the page generator answers with its sheet and
  tells the model to use it. Every `reason` is kept in the ledger.
- `copy`: the request is a variant of a component named `Site:…` (another
  look, a prop more or less). `existing` holds its name, found in
  `SiteComponentRepository`. The generator then sends its source
  (`ComponentPrompt::copy()`, the JSON of `ComponentPrompt::json()`) in the same
  conversation and asks for the variant under a new code; that second answer
  must be a `component`. The result carries `basedOn`, the screens show the
  candidate as usual with "Based on `Site:…`", and the row of the ledger keeps
  `based_on` and `reason` in its `document`.

A name the catalogue does not hold, a `copy` of a component that is not a
`Site:` one, an `existing` or a `copy` for a change (`ComponentRequest::$current`
set: only `component` is accepted) or after a copy, is sent back like any
refused component. The call that follows a `copy` is a turn, not a correction.
A row of the ledger whose `document` says `existing` is never a candidate
(`ComponentCandidates::componentOf()` gives null), so no screen offers to keep
it. `make_component` counts an answer `existing` among its two uses per page.

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

The translations written before a site was updated to 2.13 follow nothing.
`thelia_cms:translations:follow` ties the ones the ledger proves current, and
is run once after the update on the sites that translate:

```bash
php Thelia thelia_cms:translations:follow           # says what it would tie, writes nothing
php Thelia thelia_cms:translations:follow --apply   # writes the links
```

A translation is tied when the last call of the ledger that wrote its content
and language is a successful translation (the brief reads `Translated from
<locale>.`), the language still holds a draft, and the draft of the source was
not saved after that call started (`updated_at` of its row is not after the
`created_at` of the call), which is when the source was read: it then holds the
words the translation was written from. Any other translation stays
untracked, and is tied by hand from the content screen. A content already
followed is left as it is, so a second run changes nothing.

## Tests

Nothing in the test suites reaches a provider: the generators take the
gateway of the core (`AiGatewayInterface`), and the tests hand them
`Tests/Support/FakeAiGateway`, which answers from a queue and keeps every
request for the assertions; `cutAfter()` queues a stream that gives some
events and then fails, as a provider dropping half way does
(`GenerationRunTrackingTest`).
The quality of what the real model writes is measured by hand, against a
rubric: `docs/evaluation/`.
