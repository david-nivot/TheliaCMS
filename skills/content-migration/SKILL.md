---
name: content-migration
description: How to rebuild an existing page from its address or from its content given as material, keeping every word and every media of the source.
always: false
---

# Rebuilding an existing page

The page already exists somewhere else (a former site, a reference address, a
file of its content). The author wants it on this site **as it is**: the job is
to transpose it into sections, not to write it.

## Work from the material

- When the brief or a joined file gives the content of the source, that is the
  text to use. Read the source address only to see how it is laid out, or when
  no material is given.
- Keep the order of the source: section after section, element after element.

## Text: verbatim

- Copy every heading, paragraph and list item **word for word**. Do not
  rephrase, shorten, summarise, translate or "improve" anything.
- Keep what looks like a mistake: spelling, agreement, straight or curly
  apostrophes, capitals, spacing before punctuation, line breaks inside a
  paragraph and the way paragraphs are split. Correcting the source is the
  author's call, not yours.
- Keep the heading levels of the source. The title of the page is its h1: the
  sections start at h2, and an h3 of the source stays an h3.
- Write no text of your own: no introduction, no transition, no conclusion, no
  call to action the source does not have.

## Sections: only what the source has

- Add no section the source does not have, even one that would suit the page
  ("Why …", "You may also like …"): the page template may already draw related
  content.
- Choose the section that renders the source element, not one that redraws
  it: text with headings and lists in a rich text section; an embedded video
  in a video section with the same video; a slideshow or a row of photos in a
  carousel, without a title if the source shows none.
- Clear every sample value a section comes with (title, text, button, link)
  that the source does not have.

## Media: from the library

- Use the images of the source from the media library, by their id
  (`search_media`). Never point an image at the source's address.
- Keep the alternative text of the source as it is, even when it is poor; an
  image with no alternative text at the source keeps none. Invent none.
- An image of the source that is not in the library cannot be used: leave it
  out and say so.

## Links

- A link of the source that leads to a page of this site goes to that page
  (`search_pages`). A link with no equivalent page keeps its text and loses its
  link. Never link to the former site.

## What is not content

- The title, the lead (chapô) and the header image of the source are settings
  of the page, not sections: do not repeat them in the content. Say in what you
  left out that the author has to set them.

## Say what you left out

List every element of the source you could not rebuild as it is: a missing
image, a link with no target, an embed no section can render, a text a section
would have to cut.
