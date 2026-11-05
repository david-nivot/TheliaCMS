---
name: catalogue
description: How to compose a page from the sections of the catalogue, and the rules every section obeys.
always: true
---

# Composing a page from the catalogue

A page is a document: an ordered list of sections. Each section names a
component of the catalogue and gives the values of its props. The catalogue
you may use is listed after these skills, with the props of every component
and one example each. **Use only the components listed.** A section naming
anything else is refused.

## Choosing sections

- The catalogue holds three kinds of components: the components of the
  theme (their names start with `Layouts:`, `Organisms:` or `Molecules:`),
  the site's own blocks, drawn as the rest of the site is; free text
  (`cms:richtext`); and live content (`Partial:*`).
- **A component of the theme comes first.** When one of them is made for the
  content — reviews, reassurance items, a row of products, a heading band —
  use it, and keep free text for what no component is made for.
- The theme draws the title of the page as its main heading, above the
  sections: never open with a heading band or an `<h1>`. The first section is
  what comes under the title, its headings at level 2.
- Three to seven sections make a page. One idea per section.
- Read the description of each component to pick the one made for the
  content; never bend a component to say something it is not made for.
- When two components could hold the same content, these choices settle it:
  - A review or a testimonial the brief gives **no score** for goes in free
    text, quoted, with its author: the review components draw a score out
    of five, and an empty one reads as zero. Reviews with a score: the row
    of reviews for several, the card for one.
  - A banner of one to three offers to push, each with a picture, a title, a
    catchphrase and a button, goes in the highlights, at the top of a home
    page; the first block is the large one. Never use it for a list of
    arguments: that is columns.
  - Points of the same kind listed side by side — rooms, arguments, ways
    to get there, commitments — go in columns, one column each, when there
    are two to four of them and each fits in two sentences. More, or longer,
    go in free text, one `<h3>` each.
- When the page asks the visitor to do something, close with that request: a
  component of the theme made for it, or a short free text ending on the link.
- Live content (`Partial:*`) shows what the site holds today — a form, for
  instance — and takes the id of that item. Use the search tools
  to find the id; never guess one.
- Several products go in **one** row: the component of the theme made for
  a row of products, when the catalogue has one; the ids from
  `search_products`, a heading that says what they have in common. Never
  one card per product stacked one under the other; a single card is for one
  product on its own, next to the text that talks about it.
  The same for categories.

## Props

- Fill every required prop. Leave an optional prop out, or empty, rather than
  inventing a value for it.
- `text` props are plain text: no markup, no line breaks.
- `html` props hold markup: paragraphs `<p>`, lists `<ul><li>`, emphasis
  `<strong>`, links `<a href>`, headings from `<h2>` down. No `style` attribute, no `<script>`, no `<iframe>`, no
  inline images: a picture goes in a section of its own (`cms:image`).
- `image` props take the id of an image found with `search_media`, the
  absolute `https://` address of a picture the brief gives, or `null` when
  neither exists. Never a path, never an id you did not get from the tool,
  never an address you made up.
- `url` props take the `url` of a page found with `search_pages`, a
  `tel:` or `mailto:` address the brief gives, or an absolute `https://`
  address the brief gives. Never a path you did not get from a tool, however
  likely it looks: a button to a page the site does not have is a dead end.
  When no page fits, leave the button out (its label and its address empty).
- Every section has an `id`, taken from this list by what the section does,
  never from the words of the brief: `intro` (the lines under the title),
  `story`, `team`, `figures`, `features` (the points, arguments or benefits
  the brief lists), `offer` (prices, formulas, what is included),
  `products`, `categories`, `reviews`, `faq`, `practical` (address, hours,
  access, parking), `contact` (the text that asks the visitor to get in
  touch), `form`, `legal`, `content` (anything else). When two sections do
  the same thing, number the later ones: `faq`, `faq-2`. A page written from
  a model page, or translated, keeps the ids of the page it follows.

## Output

Answer with the JSON document only, matching the schema you were given:
`{"version": 1, "sections": [...]}`. The `props` of each section is a JSON
object written as a string, with exactly the keys and the value types the
catalogue gives for that component: a list prop holds a JSON list of objects,
a number prop a number, a choice prop one of its values. No prose before or
after the document.
