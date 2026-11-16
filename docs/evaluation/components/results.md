# Results of the bench of components

Rounds played on 2026-11-10 against module 2.24.0, `claude-opus-5-5`, effort
`high`, on a fresh demo with the seed (`seed.json`) imported. The run files
stay out of the repository (`var/eval/components/<round>/` of the host); this
file keeps the tables `thelia_cms:component-bench:check --markdown` prints and
what they show. The tables below are the verdicts of the oracle as it stands
(hidden copies of a carousel accepted, see "Findings", 4).

| Round | Cases | Trials | Kept the contract | Outside known gaps | Cost | Duration |
|---|---|---|---|---|---|---|
| `round-0` | 28, 3 runs each | 84 | 39 | 34 of 75 | 5.19 USD | 32.1 min |
| `round-1` | 8 with an image prop, 3 runs each | 24 | 17 | 15 of 21 | 1.59 USD | 10.7 min |
| `round-2` | 12 that failed on contrast or scroll, 3 runs each | 36 | 29 | 24 of 30 | 2.98 USD | 18.3 min |
| `round-3` | the 2 still failing, 3 runs each | 6 | 6 | 6 of 6 | 0.66 USD | 3.8 min |

Round 0 was played with an empty media library: a sample could not draw a
picture. The cases with an image prop have required an `<img` in the rendered
sample since then, so their 20 drawn trials of round 0 fail on that line alone
or with others; round 1 plays those cases again with the media of the bench
in the library, and is the measure of them.

## Round 0

| Case | Trials | Outcomes | Kept the contract | Corrections | Copied a target | Mean cost USD |
|---|---|---|---|---|---|---|
| author-box | 3 | 3 component | 0/3 | 0 | - | 0.064 |
| cards-offers | 3 | 3 component | 0/3 | 0 | - | 0.098 |
| carousel-autoplay | 3 | 3 copy | 0/3 | 0 | 3/3 | 0.095 |
| cta-banner | 3 | 3 component | 0/3 | 0 | - | 0.070 |
| figures-bars | 3 | 3 copy | 0/3 | 3 | 3/3 | 0.134 |
| gallery-grid | 3 | 1 component, 2 copy | 0/3 | 1 | 0/2 | 0.070 |
| image-before-after | 3 | 2 component, 1 existing | 0/3 | 1 | - | 0.068 |
| image-hover-caption | 3 | 3 component | 0/3 | 3 | - | 0.108 |
| logos-partners | 3 | 3 component | 0/3 | 0 | - | 0.064 |
| quote-dark | 3 | 3 copy | 0/3 | 0 | 3/3 | 0.061 |
| reuse-client-quote | 3 | 3 existing | 3/3 | 0 | - | 0.009 |
| reuse-faq | 3 | 3 existing | 3/3 | 0 | - | 0.009 |
| reuse-image-right | 3 | 3 existing | 3/3 | 0 | - | 0.010 |
| reuse-latest-articles | 3 | 3 existing | 3/3 | 0 | - | 0.012 |
| reuse-legal-text | 3 | 3 existing | 3/3 | 0 | - | 0.009 |
| reuse-photo-carousel | 3 | 3 existing | 3/3 | 0 | - | 0.009 |
| reuse-single-photo | 3 | 3 existing | 3/3 | 0 | - | 0.010 |
| reuse-three-commitments | 3 | 3 existing | 3/3 | 0 | - | 0.011 |
| steps-timeline | 3 | 3 component | 0/3 | 0 | - | 0.080 |
| table-compare | 3 | 3 component | 0/3 | 0 | - | 0.139 |
| text-callout | 3 | 3 component | 0/3 | 1 | - | 0.062 |
| text-two-columns | 3 | 3 component | 3/3 | 0 | - | 0.061 |
| trap-google-map | 3 | 3 component | 2/3 | 0 | - | 0.068 |
| trap-newsletter-form | 3 | 3 existing | 3/3 | 0 | - | 0.012 |
| video-presentation | 3 | 3 component | 2/3 | 3 | - | 0.086 |

Cases of a known gap (counted apart):

| Case | Trials | Outcomes | Kept the contract | Corrections | Copied a target | Mean cost USD |
|---|---|---|---|---|---|---|
| compose-quote-figures (N71) | 3 | 1 component, 2 copy | 3/3 | 0 | 2/2 | 0.075 |
| compose-steps-faq (N71) | 3 | 3 component | 2/3 | 0 | - | 0.092 |
| compose-text-carousel (N71) | 3 | 3 component | 0/3 | 3 | - | 0.147 |

84 trials, 39 kept the contract (34 of 75 outside the cases of a known gap). Cost 5.19 USD, duration 32.1 min.

## Round 1

| Case | Trials | Outcomes | Kept the contract | Corrections | Copied a target | Mean cost USD |
|---|---|---|---|---|---|---|
| author-box | 3 | 3 component | 0/3 | 0 | - | 0.063 |
| carousel-autoplay | 3 | 3 copy | 3/3 | 0 | 3/3 | 0.101 |
| gallery-grid | 3 | 3 copy | 3/3 | 0 | 0/3 | 0.055 |
| image-before-after | 3 | 3 component | 3/3 | 0 | - | 0.057 |
| image-hover-caption | 3 | 3 component | 3/3 | 0 | - | 0.053 |
| logos-partners | 3 | 3 component | 3/3 | 0 | - | 0.057 |
| quote-dark | 3 | 3 copy | 0/3 | 0 | 3/3 | 0.069 |

Cases of a known gap (counted apart):

| Case | Trials | Outcomes | Kept the contract | Corrections | Copied a target | Mean cost USD |
|---|---|---|---|---|---|---|
| compose-text-carousel (N71) | 3 | 3 component | 2/3 | 0 | - | 0.075 |

24 trials, 17 kept the contract (15 of 21 outside the cases of a known gap). Cost 1.59 USD, duration 10.7 min.

## Round 2

Played on 2026-11-11 against the branch that tells the generator what each
colour is for and how a wide element fits a phone (`fix/component-readability`),
with the media library imported again in the order of the manifest. The 12
cases that failed on contrast or on a scroll at 375 px, 3 runs each: 36
trials, 2.98 USD, 18.3 min.

| Case | Trials | Outcomes | Kept the contract | Corrections | Copied a target | Mean cost USD |
|---|---|---|---|---|---|---|
| author-box | 3 | 3 component | 3/3 | 0 | - | 0.071 |
| cards-offers | 3 | 3 component | 3/3 | 0 | - | 0.103 |
| cta-banner | 3 | 3 component | 3/3 | 0 | - | 0.069 |
| figures-bars | 3 | 3 copy | 3/3 | 3 | 3/3 | 0.134 |
| quote-dark | 3 | 3 copy | 0/3 | 0 | 3/3 | 0.066 |
| steps-timeline | 3 | 1 component, 2 copy | 3/3 | 0 | 2/2 | 0.074 |
| table-compare | 3 | 3 component | 0/3 | 0 | - | 0.140 |
| text-callout | 3 | 3 component | 3/3 | 0 | - | 0.049 |
| trap-google-map | 3 | 3 component | 3/3 | 0 | - | 0.071 |
| video-presentation | 3 | 3 component | 3/3 | 0 | - | 0.048 |

Cases of a known gap (counted apart):

| Case | Trials | Outcomes | Kept the contract | Corrections | Copied a target | Mean cost USD |
|---|---|---|---|---|---|---|
| compose-steps-faq (N71) | 3 | 3 component | 3/3 | 0 | - | 0.083 |
| compose-text-carousel (N71) | 3 | 3 component | 2/3 | 0 | - | 0.084 |

36 trials, 29 kept the contract (24 of 30 outside the cases of a known gap). Cost 2.98 USD, duration 18.3 min.

Kept the contract, per case, against the last round that played it:

| Case | Round 0 | Round 1 | Round 2 |
|---|---|---|---|
| author-box | 0/3 | 0/3 | 3/3 |
| cards-offers | 0/3 | - | 3/3 |
| cta-banner | 0/3 | - | 3/3 |
| figures-bars | 0/3 | - | 3/3 |
| quote-dark | 0/3 | 0/3 | 0/3 |
| steps-timeline | 0/3 | - | 3/3 |
| table-compare | 0/3 | - | 0/3 |
| text-callout | 0/3 | - | 3/3 |
| trap-google-map | 2/3 | - | 3/3 |
| video-presentation | 2/3 | - | 3/3 |
| compose-steps-faq (N71) | 2/3 | - | 3/3 |
| compose-text-carousel (N71) | 0/3 | 2/3 | 2/3 |

No component writes its text in the accent any more: `color-contrast` is gone
from every case but one, and author-box draws its photo now that the portrait
is among the pictures offered. The three cases still failing were looked at in
the browser:

- **quote-dark**: the quote is white on `text`, as asked, but its words are an
  `html` prop, and the site gives every `p` of the page its own colour
  (`text-muted`, `assets/blocks.css`): grey on dark, 1.5:1. The prompt now says
  to colour the paragraphs of an `html` prop with their container.
- **table-compare**: the table scrolls in its own wrapper, keyboard reachable,
  as asked; the page still widened to 505 to 524 px because the texts hidden
  for screen readers inside the table are positioned, and escape a wrapper
  that is not. With `position: relative` on the wrapper the page is 375 px in
  the three trials (measured by injecting the rule). The prompt now says so.
- **compose-text-carousel** trial 3: a `li` outside a list (`listitem`), a
  known gap case (N71).

The two prompt changes that follow from this round (the positioned wrapper,
the paragraphs of an `html` prop) were played in round 3.

## Round 3

Played on 2026-11-11, the two cases still failing in round 2, 3 runs each,
after the prompt gained the positioned wrapper and the paragraphs of an `html`
prop: 6 trials, 0.66 USD, 3.8 min. Both keep the contract 3 times out of 3.

| Case | Trials | Outcomes | Kept the contract | Corrections | Copied a target | Mean cost USD |
|---|---|---|---|---|---|---|
| quote-dark | 3 | 3 copy | 3/3 | 0 | 3/3 | 0.076 |
| table-compare | 3 | 3 component | 3/3 | 0 | - | 0.145 |

6 trials, 6 kept the contract (6 of 6 outside the cases of a known gap). Cost 0.66 USD, duration 3.8 min.

## Round 4

Played on 2026-11-13, the three composition cases, 3 runs each, once a
component of the site may draw another (`cms_component`, ADR 15) and their
contracts ask for the component drawn (`cms-site--carousel`, …): 9 trials,
0.46 USD, 3.0 min. Every trial answers `component` and draws the components
of the site the case names, without writing them again (Carousel; Quote and
KeyFigures; Steps and Faq); the stylesheets of the callers style their own
parts only. Without the browser, 9/9 keep the contract.

| Case | Trials | Outcomes | Kept the contract | Corrections | Copied a target | Mean cost USD |
|---|---|---|---|---|---|---|
| compose-quote-figures | 3 | 3 component | 0/3 | 0 | - | 0.051 |
| compose-steps-faq | 3 | 3 component | 3/3 | 0 | - | 0.044 |
| compose-text-carousel | 3 | 3 component | 0/3 | 0 | - | 0.057 |

9 trials, 3 kept the contract (3 of 9 outside the cases of a known gap). Cost 0.46 USD, duration 3.0 min.

The six failures come from the components of the seed they draw, not from
what the generator wrote:

- **compose-text-carousel**: `scrollable-region-focusable` on the track of
  `Site:Carousel`, which scrolls without `tabindex`, role or name (the seed
  was written before the prompt asked for them).
- **compose-quote-figures**: `color-contrast` on the 2 or 3 figures. The
  callers set no text colour at all; `Site:KeyFigures` draws its figures
  (`.figure__value`) in `--cms-color-accent`, `#ff7036` on white, 2.75:1
  (nodes read by axe on the previews of trials 1 and 2).

Both were fixed in `seed.json` the same day (the track of the carousel in a
positioned `carousel__viewport` with `tabindex="0"`, `role="region"` and the
title as its name; the figures in `--cms-color-text`), imported again on the
host (`thelia_cms:import --replace`), and the browser alone played again on
the 9 trials of round 4, with no new call: 9 of 9 keep the contract. The fix
of two components reached every component that draws them, which is what
composing is for. The oracle judges the whole drawing, the drawn components
included.

| Case | Trials | Outcomes | Kept the contract | Corrections | Copied a target | Mean cost USD |
|---|---|---|---|---|---|---|
| compose-quote-figures | 3 | 3 component | 3/3 | 0 | - | 0.051 |
| compose-steps-faq | 3 | 3 component | 3/3 | 0 | - | 0.044 |
| compose-text-carousel | 3 | 3 component | 3/3 | 0 | - | 0.057 |

## Round 5

Played on 2026-11-13, one composition case on a component of the theme:
`compose-text-related-products`, a text beside the related products the
editor picks (`Layouts:CrossSelling:Base`, opened to pages by the
`config/theliacms.yaml` proposed to Flexy and placed on the host for the
round). 3 trials, 0.27 USD, 1.4 min.

| Case | Trials | Outcomes | Kept the contract | Corrections | Copied a target | Mean cost USD |
|---|---|---|---|---|---|---|
| compose-text-related-products | 3 | 2 component, 1 error | 0/3 | 0 | - | 0.091 |

- Trials 1 and 2 compose as asked: a list of product references (`items`
  of `id`, `record: product`) handed to
  `cms_component('Layouts:CrossSelling:Base', {product_ids: …})`, the text
  beside it, nothing of the strip written again.
- Their sample leaves the list empty, as the prompt asks of a reference
  (the generator knows no id of the shop), and their template draws the
  strip only when the list holds products: the rendered sample has no
  `CrossSelling`, and the contract, which asks for it, fails.
- Trial 3 sets a minimum of one product, which the empty sample breaks;
  sent back twice, it ends with a sample the site refuses.

The composition works; what fails is the sample. A component whose point is
the records it names draws nothing to the editor, in the toolkit as in the
preview, until the generator can name records of the shop in its sample, as
it already names pictures of the media library.

## Round 6

Played on 2026-11-13, the same case once the generator is offered records of
the shop for its sample. 3 trials, 0.55 USD, 0/3. The model names products of
the list it is offered (ids 30 to 27) and a category; every sample is refused
as drawing nothing, for two causes found by drawing the sample of trial 1
again on the dev kernel, without any call:

- the round ran on the command line, with no request, session nor URL
  service: `resources()` of `Layouts:CrossSelling:Base` raised "No current
  request found" (fixed: the round runs in a visit of the shop);
- `button` of the band, an object (`{href, label}`), was read as a text: a
  text made the template fail on `button.label` and the band vanish (fixed:
  an object prop is one row of its fields).

## Round 7

Played on 2026-11-13 with both fixes, the same case. 3 trials, 0.18 USD,
0.9 min.

| Case | Trials | Outcomes | Kept the contract | Corrections | Copied a target | Mean cost USD |
|---|---|---|---|---|---|---|
| compose-text-related-products | 3 | 3 component | 3/3 | 0 | - | 0.062 |

- The three compose as asked: a heading, a text, a link, and
  `cms_component('Layouts:CrossSelling:Base', {title: props.products_title,
  product_ids: props.product_ids})`, the sample naming three products of the
  list (27, 28, 30; 26, 27, 28). None passes a button.
- Accepted by the generator at the first answer, no correction.
- The check first judged them 0/3: it draws the sample again, and ran outside
  the visit of the shop the round runs in. It runs in it now.

## Round 8

Played on 2026-11-13 on TheliaCMS 2.28.0, the case
`compose-text-related-products-button` (the same request, with a button
« Voir toute la collection » under the products), Flexy with the object prop
`button` of `Layouts:CrossSelling:Base`. 3 trials, 0.20 USD, 1.0 min.

| Case | Trials | Outcomes | Kept the contract | Corrections | Copied a target | Mean cost USD |
|---|---|---|---|---|---|---|
| compose-text-related-products-button | 3 | 3 component | 0/3 | 0 | - | 0.067 |

- The three compose the products as in round 7 (`product_ids`, products of
  the list) and add the button, but draw it themselves: two props
  `button_label` (text) and `button_link` (url), and a link of their own
  under `cms_component('Layouts:CrossSelling:Base', …)`. None passes
  `button` to the band, which the list describes (« one object, held as a
  list of one row: `href`, `label` »): the button of the theme is never drawn.
- Accepted by the generator at the first answer, no correction.
- Judged again with the contract of round 9 (rows of `text`, see below): still
  0/3, for the same two reasons.

## Round 9

Played on 2026-11-13, the same case, with the rule that a part the drawn
component offers is handed to it (`ComponentPrompt::OFFERED_PARTS_RULE`).
3 trials, 0.17 USD, 0.8 min.

| Case | Trials | Outcomes | Kept the contract | Corrections | Copied a target | Mean cost USD |
|---|---|---|---|---|---|---|
| compose-text-related-products-button | 3 | 3 component | 3/3 | 0 | - | 0.058 |

- The three hand the button to the band:
  `cms_component('Layouts:CrossSelling:Base', {title: props.products_title,
  product_ids: props.product_ids, button: props.button})`, `button` declared
  as a list of at most one row (`href`, `label`), the sample
  `[{"href": "/collection-salon", "label": "Voir toute la collection"}]`. The
  button of the theme is drawn (`button-style--secondary`).
- The contract first asked rows of `url` and `text`: the three declare `href`
  as `text`, the type the theme gives it (`{href: string, label: string}`),
  as the prompt asks. The contract asks rows of `text` now.
- Accepted by the generator at the first answer, no correction.

## Round 10

Played on 2026-11-13 with a component of the site that may draw a heading of
the site (`cms_component('cms:heading', …)`, ADR 17), the case
`compose-heading-team`. 3 trials, 0.17 USD, 0.9 min.

| Case | Trials | Outcomes | Kept the contract | Corrections | Copied a target | Mean cost USD |
|---|---|---|---|---|---|---|
| compose-heading-team | 3 | 3 component | 0/3 | 0 | - | 0.058 |

- The three draw the portraits as asked and write the heading themselves
  (`<h2 class="team__title">{{ props.title }}</h2>`), never with
  `cms:heading`. The prompt lists the heading as drawable and says a
  component may draw it; it does not say to.
- Accepted by the generator at the first answer, no correction.

## Round 11

Played on 2026-11-13, the same case, with the rule that the heading that
opens a component is the heading of the site
(`ComponentPrompt::SITE_HEADING_RULE`). 3 trials, 0.14 USD, 0.7 min.

| Case | Trials | Outcomes | Kept the contract | Corrections | Copied a target | Mean cost USD |
|---|---|---|---|---|---|---|
| compose-heading-team | 3 | 2 component, 1 existing | 2/3 | 0 | - | 0.047 |

- Trials 2 and 3 open on
  `{% if props.title is not empty %}{{ cms_component('cms:heading', {text: props.title, level: 'h2'}) }}{% endif %}`,
  and leave the heading unstyled in their stylesheet.
- Trial 1 answers `existing`: the Columns section of the module (a heading
  over columns of a picture, a heading and a text), which draws three
  portraits too. Outside the contract, which asks a new component; a
  defensible answer.

## Round 12

Played on 2026-11-16, the same case, with the heading that opens a component
set by the editor (ADR 18): the rule hands two `choice` props of the
component, `title_level` and `title_style`, to the heading of the site. On
Flexy, `style` offers `level` and the six headings of the toolkit. 3 trials,
0.20 USD, 1.2 min.

| Case | Trials | Outcomes | Kept the contract | Corrections | Copied a target | Mean cost USD |
|---|---|---|---|---|---|---|
| compose-heading-team | 3 | 3 component | 3/3 | 0 | - | 0.068 |

- All three open on
  `cms_component('cms:heading', {text: props.title, level: props.title_level, style: props.title_style})`,
  with `title_level` offering `h2`, `h3`, `h4` (`h2` by default) and
  `title_style` offering `level` and `h1` to `h6` (`level` by default):
  the values of the heading, no other.
- The labels of these choices are the generator's own (« Style 1 » to
  « Style 6 »): the list of drawable components gives the values of a
  choice, not its labels.
- The contract of the case does not check the two props; they were read in
  the trials (`var/eval/components/round-12/`).

## Round 13

Played on 2026-11-16, a control of the seed once its components open on the
heading of the site: Carousel, Faq, ImageText, KeyFigures and Steps no longer
draw their own `<h2>` but
`cms_component('cms:heading', {text: props.title, level: props.title_level, style: props.title_style})`,
as `thelia_cms:components:site-heading` offers them (the class of the old
heading lost). The nine cases that reuse, copy or draw these components.
27 trials, 1.61 USD, 10.7 min.

| Case | Trials | Outcomes | Kept the contract | Corrections | Copied a target | Mean cost USD |
|---|---|---|---|---|---|---|
| carousel-autoplay | 3 | 3 copy | 3/3 | 0 | 3/3 | 0.128 |
| compose-quote-figures | 3 | 3 component | 3/3 | 0 | - | 0.060 |
| compose-steps-faq | 3 | 3 component | 3/3 | 0 | - | 0.061 |
| compose-text-carousel | 3 | 3 component | 3/3 | 0 | - | 0.055 |
| figures-bars | 3 | 3 copy | 3/3 | 2 | 3/3 | 0.132 |
| reuse-faq | 3 | 3 existing | 3/3 | 0 | - | 0.009 |
| reuse-image-right | 3 | 3 existing | 3/3 | 0 | - | 0.010 |
| reuse-photo-carousel | 3 | 3 existing | 3/3 | 0 | - | 0.009 |
| steps-timeline | 3 | 2 component, 1 copy | 3/3 | 0 | 1/1 | 0.073 |

- Every component written (18 trials) opens on the heading of the site; none
  draws a heading of its own. A copy keeps the call of the component it
  copies.
- The reuse cases keep finding the component of the site, for the same cost.
- figures-bars needed two corrections: a bar drawn with `aria-valuemin`,
  `aria-valuemax` and `aria-valuenow` on a `<span>`, which a page does not
  keep, refused and sent back (3 corrections on round 0).
- carousel-autoplay costs more than on round 0 (0.128 against 0.101 USD):
  two turns, the copied template is longer by the call.
- The browser script ran on the 18 components written: no horizontal scroll
  at 375 and 1440 px, no axe-core violation.
- In the five components of the seed, the old heading was drawn at the size
  of body text (16 px, weight 400 on Flexy, whose reset leaves an `h2`
  unstyled); the heading of the site draws it at the size of an `h2` of the
  site (26 px on a phone, 36 px at 1440, bold). Measured in the preview of
  each version, the old `<h2>` put back in place of the call.
- The heading of the site has no space under it in a component: 0 px to what
  follows in Carousel, Faq, ImageText and KeyFigures (12 to 24 px before),
  and in 13 of the 18 components of this round; the others space it through
  their own layout.

## Findings

1. **Reuse works and costs nothing.** The 8 `existing` cases (and the
   newsletter trap) keep the contract 3 times out of 3, for about 0.01 USD
   and 3 s a trial. The `copy` outcomes name the expected target
   (carousel-autoplay, figures-bars, quote-dark: 3/3), except gallery-grid,
   which copies a component of the site outside its targets (0/2 in round 0,
   0/3 in round 1: counted, not failed).
2. **Contrast is the first cause of failure.** 26 of the 56 components drawn
   in round 0 and 6 of 24 in round 1 fail axe-core on `color-contrast`
   (serious). Most of them print text in `--cms-color-accent-strong`, which
   the Flexy theme maps to `--color-dark: #ff4822`: 3.4:1 on white, under the
   4.5:1 of a body text. Lead: tell the generator which colours of the theme
   are readable as text, and on which background.
3. **The comparison table scrolls sideways at 375 px** in 3 trials out of 3:
   the generator draws a wide `<table>` without a narrow layout.
4. **False negative of the oracle, fixed.** A carousel that loops draws its
   pictures twice, the copy inside an `aria-hidden="true"` block with
   `alt=""`: the right practice, which the oracle counted as a picture
   without alternative text (carousel-autoplay, 3 trials in each round). The
   oracle now leaves the pictures of a hidden block out of the alt check;
   carousel-autoplay keeps the contract 3/3 in round 1.
5. **Bias of the bench, fixed for the next round.** The generator offers the
   sample the 12 most recent pictures of the library; the bench imported 14,
   the landscape and the portrait first, so the generator never saw them:
   author-box draws no photo (0/3 in round 1, `photo: null`). The manifest
   now imports first the two pictures no case needs (the square pattern and
   the banner), and `thelia_cms:component-bench:media` names the pictures a
   manifest of more than 12 leaves out. The library of the host still holds
   the old order: a next round needs it emptied and imported again.

Other lines:

- image-hover-caption, round 0: the component is drawn without height in the
  browser (a caption over a picture absent from the sample); with media
  (round 1) it keeps the contract 3/3.
- compose-text-carousel (known gap N71), round 1, trial 3: a scrollable track
  without `tabindex` (`scrollable-region-focusable`, serious).
- image-before-after, round 0, trial 2: answers `existing` where the case
  asks for a new component.
