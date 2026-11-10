# Plan: pictures found on the web

Status: planned, not started. Two questions below are open and must be
settled before any code.

## Why

A brief that asks for a picture the media library does not hold, and gives no
address of one ("a photo of the tart out of the oven, found on the internet"),
gives a page without it. Since the `left_out` list (see `docs/generator.md`)
the editor is told so, but the generator still cannot find the picture.

## What is there today

- `cms:image` takes an image of the media library or the `https` address of
  a picture kept elsewhere (`Content/ImageSection.php`, `Content/ImageSource.php`).
- `search_media` lists the images of the library (`Generation/Tool/SearchMediaTool.php`).
- `read_url`, a client tool run on the server (`Generation/Tool/ReadUrlTool.php`;
  the `web_fetch` SDK tool was retired in 2.4.0), is offered only when the site
  allowed the reading of the web and the brief cites an `http(s)` address
  (`Generation/PageGenerator.php`, `Generation/Brief.php::addresses()`).
- The answer may say what it left out (`left_out`), stored in
  `cms_page_generation.left_out` and shown in the outcome and the history.
- The SDK (`anthropic-ai/sdk` 0.59) ships `web_search_20260209` and
  `web_search_20260318`, with `allowedDomains`, `blockedDomains`, `maxUses`
  and `userLocation`.

## The mechanism

`web_search` finds pages, not image files. A picture of the web reaches a
page in three steps: search, read the page found for the address of the
picture, import that picture into the media library.

## Steps

1. **Setting.** `generator_web_search` in `GeneratorSettings`, off by default,
   with an optional list of allowed or blocked domains, on the settings
   screen next to the reading of pages. Off, the request stays byte for byte what it
   was.
2. **The server tool.** Offered in `PageGenerator` when the setting is on,
   `maxUses` 3. `StreamedMessage` handles `server_tool_use` and
   `web_search_tool_result` and sends them back unchanged at the next turn;
   `FakeClaudeTransport` reproduces their shapes.
3. **Reading what was found.** `read_url` is offered too when `web_search`
   is, not only when the brief cites an address.
4. **`import_image`, a client tool** (`Generation/Tool/ImportImageTool.php`),
   **delivered alone, without web search** (see `docs/generator.md`, the ledger
   part of this step is not done):
   takes an address, a title and a credit; downloads on the server side,
   `https` only, private and reserved addresses refused, redirects limited,
   type (jpg, png, webp, read in the bytes; a GIF is refused like on the upload
   routes) and size checked; creates the image through the
   code of `Media/Admin/CmsMediaWriter`, tags it for the CMS library, and
   returns its id. The publication then resizes it and serves it as WebP like
   any image of the library.
5. **Ledger.** The searches (queries, count) and the imported images (id,
   source address, credit) recorded with the call, shown in the history like
   the web pages read; the cost of the searches added to the cost of the call.
6. **Skills.** Import only from a source whose terms allow it, credit the
   author in the caption, and say it in `left_out` otherwise.
7. **Tests.** Fake transport for a search, an import that succeeds, imports
   refused (private address, wrong type, too large), and a call where the tool
   is not offered.

## Open questions

1. **Rights on pictures.** An open web search mostly finds pictures nobody may
   reuse. Recommended: restrict `allowedDomains` to free banks (Wikimedia
   Commons, Unsplash, Pexels). Alternative: a dedicated client tool on the
   Unsplash or Pexels API, which returns images with their licence and author
   directly, more reliable than `web_search` for this need, at the price of an
   API key per site.
2. **Cost.** The price of a search is to be confirmed on the current price list
   before it is added to `Generation/Pricing.php`.

## Decisions taken

- A picture of the web is imported into the media library, never linked from
  where it was found: it is resized and served by the site, and it stays when
  the source moves.
- The tool is opt-in per site.
- What the generator could not find goes in `left_out`, never in an invented
  address.

## Resuming

Read, in order: this file, `docs/generator.md` (sections "What the model is
told", "Web pages the brief cites", "The ledger"), `Generation/PageGenerator.php`,
`Generation/StreamedMessage.php`, `Generation/Tool/SearchMediaTool.php`,
`Media/Admin/CmsMediaWriter.php`. Check that the two open questions are
settled, then follow the steps in order, each with its tests.
