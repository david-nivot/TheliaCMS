# Plan: a bench for the generator of components

Status: started on 2026-11-10 on `feat/component-bench`. The design questions
are settled (see "Decisions taken").

## What is asked

A set of requests an editor of an editorial site would type to make a
component (text, images, carousel, video, columns, quote, steps…), each with
an expected result a machine can check, played against the real generator and
replayed for free afterwards. It also measures whether the generator reuses
what the site already has instead of making it again.

## What is there today

- `Generation/Component/ComponentGenerator.php` answers every request with a
  new component: the answer schema (`ComponentPrompt::schema()`) has no other
  outcome. The components the catalogue holds are listed in the user message
  only so that the model does not make them again
  (`ComponentPrompt::userMessage()`).
- A component of the site cannot draw another: the Twig sandbox has no
  `include` (`Content/Site/SiteTemplateRenderer.php`).
- No command makes a component: `thelia_cms:generate` writes pages only.
- `docs/evaluation/` measures pages (rubric, determinism rounds,
  `thelia_cms:compare-runs`); nothing measures components.
- `Tests/Support/FakeAiGateway.php` answers the generator in the tests.

## Decisions taken (2026-11-10)

1. **The reference is a contract per case, not a frozen output.** The model
   never gives the same component twice and has no temperature setting.
   Each case states what a machine can check (outcome, shape of the props,
   markers in the markup and the stylesheet); a few checks hold for every
   case (below). The browser checks the sample at 375 and 1440 px.
2. **Answers are recorded and replayed.** A run keeps the request it sent
   (system and user message, and their SHA-256) and every raw answer. The
   tests replay the recorded answers through `FakeAiGateway` and check that
   the verdict is the one recorded; a recording whose prompt hash no longer
   matches is reported stale, not failed.
3. **Rounds of N runs per case**, the rate of runs that keep the contract
   per case, compared from round to round. Round 0: 3 runs per case, about
   8 USD, granted on 2026-11-10.
4. **A fixed catalogue to start from**: a seed export
   (`docs/evaluation/components/seed.json`) imported before a round, so every
   run sees the same components of the site.
5. **The generator gains two outcomes** in this lot: `existing` (a
   component of the catalogue already does what is asked) and `copy` (the
   request is a variant of a component of the site: its source is sent back
   and the model writes the variant under a new code). Composing components
   (one drawing another) is a separate lot of the roadmap (N71), designed
   from what this bench measures.
6. No model acts as judge: a judge varies as much as what it judges.

## Frozen interfaces

### The answer of the generator

The schema gains `outcome`, `existing` and `reason`, all required (structured
output). With `existing` or `copy`, every field of the component is an empty
string.

```json
{"outcome": "component", "existing": "", "reason": "", "code": "Timeline", "label": "Frise", "description": "…", "props": "[…]", "sample": "{…}", "template": "<section>…</section>", "css": "…"}
{"outcome": "existing", "existing": "Site:Quote", "reason": "Site:Quote already shows a quote with its author.", "code": "", "label": "", "description": "", "props": "", "sample": "", "template": "", "css": ""}
{"outcome": "copy", "existing": "Site:Quote", "reason": "A darker look of Site:Quote with a picture of the author.", "code": "", "label": "", "description": "", "props": "", "sample": "", "template": "", "css": ""}
```

- `existing` names a component of `SectionCatalog::all()`; `copy` names a
  `Site:` component of the repository. Anything else is sent back for
  correction like any refused component.
- After `copy`, the generator sends the source (`ComponentPrompt::json()`)
  in the same conversation and asks for the variant under a new code; that
  answer must be `component`. The result carries `basedOn` = the copied name.
- A change of a component (`ComponentRequest::$current` set) only accepts
  `component`.

### The result

```php
new ComponentResult(
    component: ?SiteComponent,   // null when outcome is existing
    model, requestId, usage, costUsd, durationMs, turns, logId, provenance,
    outcome: 'component'|'existing',
    existing: ?string,           // the name, when outcome is existing
    basedOn: ?string,            // the copied Site: name, after a copy
    reason: string,
);
```

### A case

One file per case, `docs/evaluation/components/cases/<id>.json`:

```json
{
  "id": "quote-dark",
  "family": "reuse",
  "locale": "fr_FR",
  "request": "Une citation sur fond sombre, avec la photo de la personne citée.",
  "oracle": {
    "outcome": ["copy", "component"],
    "target": ["Site:Quote"],
    "props": [
      {"type": "image", "min": 1},
      {"type": "text|html", "min": 2}
    ],
    "markup": {"require": ["<blockquote"], "forbid": ["<video"]},
    "css": {"require": [], "forbid": []}
  }
}
```

- `outcome`: the accepted outcomes; `target`: for `existing` and `copy`, the
  accepted names (a `component` outcome ignores it, but the report counts
  `basedOn` against it).
- `props`: each line counts the props of a type (`a|b` = either) at the top
  level and inside `items`; `min` / `max` bound the count. A line
  `{"type": "items", "min": 1, "with": ["image", "text|html"]}` asks for an
  `items` prop whose rows hold those types.
- `markup` and `css`: regular expressions (PCRE, case-insensitive) matched
  on the rendered sample and on the stylesheet.
- Checks of every `component` outcome: valid without correction (counted,
  not failed), no `h1`, every `img` with an `alt` printed from a prop, an
  animation guarded by `prefers-reduced-motion`; in the browser, no
  horizontal scroll at 375 px and no axe-core violation of impact
  `serious` or `critical`.

### A run

`var/eval/components/<round>/<case>/<n>.json`, out of the repository:

```json
{
  "case": "quote-dark", "round": "round-0", "run": 1,
  "module": "2.24.0", "model": "claude-opus-5-5", "effort": "high",
  "prompt_sha256": "…", "system": "…", "user": "…",
  "answers": ["{…raw answer of turn 1…}", "{…turn 2…}"],
  "outcome": "component", "existing": null, "based_on": "Site:Quote",
  "component": {"code": "QuoteDark", "…": "…"},
  "turns": 2, "corrections": 0, "cost_usd": 0.12, "duration_ms": 41000,
  "ledger_id": 812, "error": null,
  "verdict": {"pass": true, "checks": {"outcome": true, "props": true, "markup": true}}
}
```

## Steps

1. **Outcomes of the generator** (decision 5): schema, prompt, generator,
   result, the three callers (`Component/Admin/CmsSiteComponentController`,
   `Generation/Admin/CmsPageComponentController`,
   `Generation/Tool/MakeComponentTool`), messages, tests, docs.
2. **Corpus and seed**: about 25 cases over the families text, image,
   gallery, carousel, video, columns, cards, quote, figures, steps, FAQ,
   call to action, logos, author, table, reuse (`existing`, `copy`) and
   composition (known gap, N71); `seed.json`; `docs/evaluation/components/README.md`.
3. **The command** (done 2026-11-10, not committed yet): `thelia_cms:component-bench:run` (real calls, N runs
   per case, nothing kept, run files as above) and `thelia_cms:component-bench:check`
   (applies the oracles to a round, prints the table; calls no API;
   `--refresh-previews` signs the preview addresses again). Commands in
   `docs/evaluation/components/README.md`, "Running a round".
4. **Browser**: a Playwright script that opens the signed preview of each
   candidate at 375 and 1440 px, measures the scroll, runs axe-core, takes
   the captures, and writes its verdict into the run file.
5. **Round 0**: 3 runs per case, results in
   `docs/evaluation/components/results.md`, captures shown to the
   maintainer to validate the visual references.
6. **Replay**: recordings of round 0 under `Tests/Fixtures/component-bench/`,
   an integration test that replays them.
7. Docs and changelog; PR.
8. **Media of the sample** (decided by the maintainer on 2026-11-10, after
   round 0 was started). Code written on `feat/component-bench-media`: the
   list in the prompt (`SampleMedia`) and the command
   `thelia_cms:component-bench:media` with their tests, not run yet; the
   pictures and the manifest, the `<img` requirement of the cases and round 1
   remain. Until now the prompt says an image of the sample is
   null unless the request gives an `https` address, and the generator does
   not know the media library: a sample never draws a picture, so neither the
   preview of the editor nor the bench sees one.
   - The user message of the generator of components lists a few media of the
     library for the sample: id, image or video, its text (alt or title),
     width × height. The most recent ones, a dozen pictures and a few videos
     at most; nothing when the library is empty. The rule of the sample
     becomes: an `image` or `video` prop of the sample takes the id of one of
     them chosen for what it shows, or null when none fits.
   - The bench fills the library before a round: pictures made by GD
     (deterministic: a landscape, a portrait, a square, four logos, a few
     scenes, each with its alt), committed under
     `docs/evaluation/components/media/` and imported by a command of the
     bench; a short MP4 if one can be made on the host without a tool the
     host lacks (ffmpeg is not there), otherwise the video case stays without
     a `<video>` requirement and the report says so.
   - The image cases ask again for `<img` in the rendered sample.
   - Round 1: the cases with an image or a video prop only, 3 runs each,
     3 USD at most (granted on 2026-11-10), played after round 0.
