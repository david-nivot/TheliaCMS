# 11. A catalogue item carries a document

Date: 2026-10-22. Status: accepted. Amends ADR 2 and ADR 4 (their amendments
of 2026-10-20 removed the blocks of catalogue items).

## Context

The blocks of the catalogue items went with the reusable blocks in 2.0.0:
the content of a product, a category, a content or a folder was left to
TheliaBlocks or to the theme. A shop moving its editorial content from
TheliaBlocks to the CMS then has nowhere to put the blocks of its products,
which are most of them (on the first site measured, 66 groups of blocks out
of 70 were attached to an item of the catalogue), and has to keep two
editors side by side.

The document of a page (ADR 1) does not depend on the page: the validator,
the renderer and the publication pipeline take a document and a locale, and
the content screen (ADR 3) takes a subject with its routes.

## Decision

**A product, a category, a content and a folder carry a document of
sections in each language**, written, published and printed like the
content of a page:

- Stored in `cms_item_content` and `cms_item_revision`, the item named by its
  type and its id. No foreign key: four core tables can own a document. The
  module deletes the documents of an item when the core deletes it.
- Written on the content screen of a page, under
  `/admin/cms/catalogue/{type}/{id}/content`, reached from a tab of the edit
  screen of the item. The rights are those of the pages: the right to change
  pages for the screen and the changes by hand, the right to generate for
  the generator.
- Validated, filtered and published through the pipeline of a page: a draft
  visitors never see, a snapshot and a revision taken together, a refusal
  when it shows nothing or, in `block` mode, when its headings are broken.
- Written from a brief by the generator, which is told which item the
  document is shown on and what the theme already shows of it.
- Printed by the theme with `cms_item_content(type, id)`, which answers the
  published document or nothing.
- Counted as a use of the images and the components it holds, and carried
  in the export, each item found on the importing site by its reference (a
  product) or the address it answers at.

Brands and stores carry none: no site asked for it.

## Consequences

- A site can move the blocks of its catalogue out of TheliaBlocks and keep a
  single editor.
- The documents of the items are not in the search of the CMS: the shop
  searches its own catalogue.
- Rendering the drafts again (`thelia_cms:render`) and the drafts a new
  version of a site component renders again cover the pages only; the
  document of an item is rendered again when it is published.
- The theme decides where the document goes on the page of the item; Flexy
  ships no call, a child theme adds it.

## Amendment of 2026-11-03

On a theme that fires them, the module prints the document of a product on
`product.content` and the one of a category on `category.bottom`
(`ItemContentThemeHook`). A shop on Flexy wrote and published a document
that showed nowhere: Flexy fires the hooks and never calls
`cms_item_content()`.

- On by default, unlike the footer menu: a document is written for its page,
  and one online that does not show looks like a fault. A theme that prints
  it itself turns `item_content_hook` off, or shows it twice; nothing in a
  template tells the module whether it does.
- After what the theme shows of the item: the document adds to its page. On
  a product, before the products related to it rather than at the very
  bottom: Flexy fires `product.content` there for it (the hook came with
  this amendment), and a Flexy without it shows no document on a product.
- A content, a folder and a store get no hook from Flexy, so their theme
  still prints their document with `cms_item_content()`.
