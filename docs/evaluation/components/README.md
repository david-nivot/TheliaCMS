# Bench of the generator of components

A set of requests an editor would type to make a component, each with a
contract a machine can check, played against the real generator. It measures
two things: whether the result is a good component, and whether the generator
reuses what the site already has instead of making it again. The design is in
`docs/plans/component-bench.md`; the command (`thelia_cms:component-bench`)
is step 3 of that plan, the browser script is step 4.

## What is here

- `seed.json`: the components of the site every run starts from.
- `cases/<id>.json`: one case per file, 28 cases.
- Runs are written to `var/eval/components/<round>/<case>/<n>.json`, out of
  the repository.

## Procedure

1. Start from a site with no component of its own (`cms_component` empty)
   and import the seed:

   ```bash
   ddev exec php Thelia thelia_cms:import local/modules/TheliaCMS/docs/evaluation/components/seed.json
   ```

   Expected: `components: 6 created`. The import checks each component like
   one the model wrote, so a seed that no longer validates is reported as a
   warning ("left out"). The command has no dry-run option.
2. Play a round (real calls, N runs per case): `thelia_cms:component-bench:run`.
3. Apply the oracles to the round, no API call: `thelia_cms:component-bench:check`.
4. Run the browser script on the candidates (375 and 1440 px, scroll, axe-core,
   captures).

No model judges the results: a judge varies as much as what it judges.

## Running a round

From the root of the host, with the seed imported (see above) and nothing else
running on it (a cache clear, a copy of the code or a test suite makes the calls
in flight fail):

```bash
# Real calls, paid. Round 0: 3 runs per case, 8 USD at most, about 1 h.
ddev exec php Thelia thelia_cms:component-bench:run --round round-0

# A few cases, or one more run, without touching the files already there
ddev exec php Thelia thelia_cms:component-bench:run --round round-0 --case quote-dark --case reuse-faq --runs 3

# Apply the oracles (no API call), write the verdict into each file, print the table
ddev exec php Thelia thelia_cms:component-bench:check --round round-0

# The table alone, as it goes into results.md
ddev exec php Thelia thelia_cms:component-bench:check --round round-0 --markdown

# The signed addresses expire after an hour: sign them again before the browser script
ddev exec php Thelia thelia_cms:component-bench:check --round round-0 --refresh-previews

# In the browser (on the host, out of DDEV): 375 and 1440 px, horizontal scroll,
# axe-core on the component, a capture next to each run file; then check again
BENCH_ROUND_DIR=var/eval/components/round-0 \
NODE_PATH=<dir>/node_modules CHROME_PATH=<chromium> \
node local/modules/TheliaCMS/Tests/Browser/component-bench.mjs
ddev exec php Thelia thelia_cms:component-bench:check --round round-0
```

The browser script needs `playwright` and `axe-core` in `NODE_PATH`; it writes
the `browser` block of each run file, which `check` reads. A run already looked
at is skipped unless `FORCE=1`.

The media library of a fresh shop is empty, and the generator of components
cannot search it: the sample of a component draws its pictures and videos only
when the model gives an address of elsewhere. The cases therefore ask for an
`image` or `video` prop, never for an `<img>` or a `<video>` in the markup.

`run` options: `--round` (required), `--cases` (default: `cases/` of this
folder), `--case` (repeatable, a filter), `--runs` (default 3), `--out`
(default `var/eval/components` of the host), `--budget-usd` (default 8).

- One run of every case is played before the next run of any case, so a budget
  that stops the round leaves every case with the same number of runs.
- A trial whose file exists is not played again: a round stopped by the budget,
  by an error or by hand is resumed with the same command.
- No trial starts once the cost of the round (every file under the round
  included, the ones from an earlier run too) reaches `--budget-usd`; the
  command says so and exits normally.
- A generation that fails (refused three times, provider down) is a trial: the
  file holds `error`, the answers received and the cost the ledger recorded, and
  the round goes on.
- The generator keeps nothing but its line of the AI ledger: no component is
  saved. `thelia_cms:component-bench:check` calls no API and can be run as often
  as the contracts change; it rewrites `verdict` in each file.

The file of a trial, `<out>/<round>/<case>/<n>.json`, is the one of the plan
(`docs/plans/component-bench.md`, "A run") with, besides: `locale`, `request`,
`code` and `scope_class` of the component, `component` as its fields
(`props` and `sample` as JSON values, not strings), `preview_url` (the signed
preview of the candidate on the address of the shop, only when the outcome is
`component`) and `error`. `prompt_sha256` is the SHA-256 of the system prompt,
two line feeds, then the first message of the user. After `copy`, the outcome
stays `component` and `based_on` names the copied component; `corrections` is
`turns` minus the first answer, minus the copy turn.

The browser script aims at `.cms-site.<scope_class>` (the wrapper
`<div class="cms-site cms-site--price-table">` of `SiteComponentSection`: the
code in kebab case) and writes into the file, for `check` to read:

```json
"browser": {"375": {"scroll": false, "axe": []}, "1440": {"scroll": false, "axe": [{"id": "color-contrast", "impact": "serious"}]}}
```

A trial fails the browser check when `scroll` is true at either width or an
`axe` entry has the impact `serious` or `critical`.

## The oracle

The reference is a contract per case, not a frozen output: the model never
gives the same component twice. Each case states what a machine can check.

- `outcome`: the accepted outcomes (`component`, `existing`, `copy`).
- `target`: for `existing` and `copy`, the accepted names. A `component`
  outcome ignores it, but the report counts `basedOn` against it.
- `props`: each line counts the props of a type (`a|b` = either) at the top
  level and inside `items`; `min` / `max` bound the count. A line
  `{"type": "items", "min": 1, "with": ["image", "text|html"]}` asks for an
  `items` prop whose rows hold those types.
- `markup` and `css`: regular expressions (PCRE, case-insensitive) matched on
  the rendered sample and on the stylesheet; `require` must all match, no
  `forbid` may.
- `props`, `markup` and `css` apply to the `component` outcome only.
- Checks of every `component` outcome: valid without correction (counted, not
  failed), no `h1`, every `img` with an `alt` printed from a prop, an
  animation guarded by `prefers-reduced-motion`; in the browser, no horizontal
  scroll at 375 px and no axe-core violation of impact `serious` or `critical`.
- A component written after a `copy` (`based_on` set) counts as outcome `copy`
  when the case accepts it, and its `based_on` must then be in `target`; when
  the case does not accept `copy`, it counts as `component`, and `check` only
  counts whether `based_on` is in `target` (column "Copied a target").
- `gap` (composition cases only): `"N71"`, the known gap of the roadmap (a
  component cannot draw another). Counted apart from the others.

The contracts say what distinguishes a good result (a `<table` for a table,
an `<img` for a picture, no `<iframe`), not how it is written: no class name
is matched.

## The catalogue the generator sees

Read on 2026-11-10 from **CMS > Components** (`/admin/cms/components`) of the
dev site, before the seed: 6 components.

| Name | Origin | Role |
|---|---|---|
| `cms:columns` | Module | Two to four columns side by side, each an image over an optional heading and a short text (partners, promises, steps) |
| `cms:highlights` | Module | A band of one to three highlighted blocks, each a picture with a title, a catchphrase and a button; the top of a home page |
| `cms:image` | Module | One picture on its own with an optional caption |
| `cms:richtext` | Module | Paragraphs, headings and lists as markup, for text that fits no other section |
| `Partial:cms-form` | Partial | A form of the site, rendered live |
| `Partial:cms-page-list` | Partial | Pages of a type, rendered live |

By origin: 4 module, 2 partial, **0 theme**, 0 site. The active theme (Flexy)
has no `config/theliacms.yaml`, so it opens no component to pages: no case
targets a component of the theme. A case of that kind needs a theme that
declares some.

Once the seed is imported (all `ready`, ids 1 to 6 in `cms_component`):

| Name | Role |
|---|---|
| `Site:Quote` | A quote with its author, their role and an optional portrait |
| `Site:ImageText` | A picture beside a heading and a text, on the left or on the right (`side` choice), with an optional link |
| `Site:KeyFigures` | Two to six key figures: a value, a label and a note |
| `Site:Steps` | The steps of a process in order, each a title and a sentence |
| `Site:Carousel` | Pictures that scroll sideways and snap on each one (CSS scroll-snap, no script), with a caption |
| `Site:Faq` | Questions that open to show their answer (`details`) |

## The cases

Family `reuse` holds the cases of `existing` and `copy`; the subject says
what is asked. Outcomes are the accepted ones.

| Id | Family | Subject | Outcome | Target |
|---|---|---|---|---|
| `text-two-columns` | text | long text on two columns | component | |
| `text-callout` | text | highlighted note | component | |
| `image-hover-caption` | image | photo with a caption on hover | component | |
| `image-before-after` | image | two photos, before and after | component | |
| `gallery-grid` | gallery | photo grid | component | |
| `carousel-autoplay` | carousel | carousel that runs by itself | copy or component | `Site:Carousel` |
| `video-presentation` | video | MP4 with title and sentence | component | |
| `cards-offers` | cards | three plans with price and button | component | |
| `logos-partners` | logos | band of partner logos | existing or component | `cms:columns` |
| `author-box` | author | author box at the end of an article | component | |
| `table-compare` | table | comparison of three offers | component | |
| `cta-banner` | cta | title, sentence, two buttons | component | |
| `reuse-legal-text` | reuse | legal notice | existing | `cms:richtext` |
| `reuse-single-photo` | reuse | one photo and its caption | existing | `cms:image` |
| `reuse-three-commitments` | reuse | three columns with icon | existing | `cms:columns` |
| `reuse-latest-articles` | reuse | latest blog articles | existing | `Partial:cms-page-list` |
| `reuse-client-quote` | reuse | a client quote | existing | `Site:Quote` |
| `reuse-photo-carousel` | reuse | a photo carousel | existing | `Site:Carousel` |
| `reuse-faq` | reuse | a FAQ | existing | `Site:Faq` |
| `reuse-image-right` | reuse | picture right, text left | existing | `Site:ImageText` |
| `quote-dark` | reuse | dark quote with a portrait | copy or component | `Site:Quote` |
| `figures-bars` | reuse | key figures with progress bars | copy or component | `Site:KeyFigures` |
| `steps-timeline` | reuse | vertical timeline of dated steps | copy or component | `Site:Steps` |
| `compose-text-carousel` | composition | text left, carousel right | component (gap N71) | `cms:richtext` + `Site:Carousel` |
| `compose-quote-figures` | composition | a quote above three figures | component (gap N71) | `Site:Quote` + `Site:KeyFigures` |
| `compose-steps-faq` | composition | steps followed by a FAQ | component (gap N71) | `Site:Steps` + `Site:Faq` |
| `trap-google-map` | trap | embedded Google map | component, no `<iframe` | |
| `trap-newsletter-form` | trap | sign-up field and button | existing `Partial:cms-form`, or component without `<form` / `<input` | |

Notes on the contracts:

- The composition cases name in `target` the components that would have to be
  composed; the expected outcome is `component` (a new one that redraws them),
  and the case is counted apart through `gap`.
- `trap-newsletter-form`: the site has `Partial:cms-form`, the forms of the
  module: pointing to it is the right answer, so the contract accepts
  `existing` with it, or a new component that draws no `<form` or `<input`.
- The `reuse-*` cases expect `existing` only: a new component made for what
  the catalogue already does counts as a failure of reuse.

## Budget

About 0.11 USD and 40 s per try, measured on a table of prices. Round 0 is 3
runs per case: 28 cases, 84 runs, about 9 USD and about 1 h of sequential
calls (granted: 8 USD, see the plan; the corpus is slightly larger than the
25 cases it was sized on, and `existing` answers should cost less).
