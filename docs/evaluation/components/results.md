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
