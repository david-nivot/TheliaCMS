# Plan: skills that give the same page for the same brief

Status: control played on 2026-11-04 on module 2.10.0 (two rounds, 5.59 USD: noise is two briefs, the code changes since round 2c move nothing beyond it; `results.md`). Paused on 2026-10-27, after round 2e. The command additions, the corpus, the bench and
the script are done, and rounds 0 and 1 are played: the effort is not the
lever, the section ids are the largest source of variance
(`docs/evaluation/determinism/results.md`). Rounds 2a (fixed section ids)
2b (no section the brief does not ask for) and 2c (which component when two
fit) are kept: the same structure in 4 runs out of 5 on 15 briefs of 16.
2d (counts taken from the brief) and 2e (one `<h2>` per section of text)
made the shape less steady and were dropped. The shape and the length of the
sections are what remains, at the level of the noise of five runs; 21.14 USD
spent of the 30 planned. Settled on 2026-10-27:
the runs stay out of the repository (only their summary goes in), and the bar
for structure and shape is 4 runs out of 5.

## Why

An editor who writes a page from a brief, throws it away and asks again
expects the same page, with other words at most: the same sections, in the
same order, made of the same components. So does a site that writes a series
of pages from one template, or the other languages of a page. Today nothing
measures how much two answers to one brief differ, and a change to a skill
may make the pages better on average and less predictable at the same time.

The goal is to tighten the shipped skills until one brief gives one
structure, without losing the quality the current evaluation measures.

## What is there today

- **Quality, one run per brief.** `docs/evaluation/` holds ten briefs, a
  rubric of eight criteria and one run of each (`results.md`, average 7.1/8).
  It says whether a page is good, not whether the next one will be the same.
- **No sampling knob.** The generator sets the model, the effort (`high` by
  default) and the maximum of tokens (`Generation/GeneratorSettings.php`);
  no temperature. Determinism has to come from the instructions: the skills,
  the brief and model of a page type, the catalogue.
- **Seven shipped skills** (`skills/*/SKILL.md`), six always read and `seo`
  on request, plus the instructions of a page type when it has some. The prompt prefix is cached for an hour;
  the cache changes the cost, not the answer.
- **A command that writes without saving**: `php Thelia thelia_cms:generate
  --page <id> --locale <locale> --brief <file> --dry-run` prints the document
  as JSON, then the model, turns, tokens, cost and duration
  (`Generation/GenerateCommand.php`). It writes pages only, from a brief or as
  a revision: not a catalogue item, not a translation.

## What "the same page" means

Three levels, measured on every pair of runs of one brief:

| Level | Compared | Target |
|---|---|---|
| **Structure** | the list of `(component, id)` of the sections, in order | the same in at least 4 runs out of 5 |
| **Shape** | per section: the number of items of each list prop, the heading levels, whether a button, a link or an image is set, the products and pages referenced | the same in at least 4 runs out of 5, the counts within one item |
| **Text** | the words of the sections | not a target: only the facts (figures, names, dates) must be the same, and the length of a section within 30 % |

The quality of the existing rubric must not drop: average ≥ 7/8 and no brief
under 5 on the ten briefs, at every step.

## The corpus

- The ten briefs of `docs/evaluation/briefs/`, unchanged, on the same bench
  pages.
- Six briefs added for what the ten do not reach, numbered on from them under
  `docs/evaluation/determinism/briefs/`:
  - 11: a brief that fits two components equally (questions and answers that
    could be a list or the questions component);
  - 12: tabular data (pack sizes, opening hours), measured with the catalogue
    the bench has; the same brief without a component made for it waits for
    a bench that offers one;
  - 13: a page of a type that has a brief, instructions and a model page
    (`eval-workshop`);
  - 14: the content of a catalogue item (the product `PROD001` of the bench,
    with its summary and description);
  - 15: a translation into English of a finished French page, held as the
    draft of `eval-15` (no brief file);
  - 16: a brief of two lines, the minimum the skills must turn into a short
    page.
- **The bench**: `docs/evaluation/determinism/bench.json`, imported once with
  `php Thelia thelia_cms:import`. It holds a page per brief (`eval-01` to
  `eval-16`, hidden, slugs as their uid), the type `eval-workshop` with its
  brief, instructions and model page, and the French draft brief 15
  translates. The data is made up.

## The runs

- **Five runs per brief and per version of the skills**, same model, same
  effort, same catalogue and same bench data, one after the other.
- `docs/evaluation/determinism/run-round.sh <round> [effort] [brief…]`, run
  from the root of the host, plays the round and keeps each run as
  `var/eval/runs/<round>/<brief>/<n>.txt` of the host, with its cost line, out
  of the repository; only the summary goes in. A run already kept is not
  played again, so an interrupted round resumes where it stopped.
- **Three additions to the command**, so that the corpus runs without a
  browser: `--item <type>:<id>` to write the document of a catalogue item,
  `--translate-from <locale>` to write a language from another one, and
  `--effort` to play round 1 without touching the settings of the site. They
  reuse `GenerationRequest` as the back office does; nothing is saved with
  `--dry-run`.
- **A comparison command** (`php Thelia thelia_cms:compare-runs <round>
  [--details]`, `Evaluation/`, no API call) reads the runs of a round and
  prints, per brief: the structures met and how many runs
  each, the shape differences, the length spread per section, the facts that
  differ. It is the only judge of the three levels, so it was written and
  tested first, on hand-made documents (`Tests/Unit/Evaluation/`). A run is
  the output of `thelia_cms:generate --dry-run` as it prints it:
  `thelia_cms:generate ... --dry-run > runs/round-0/05/1.txt`.

## Rounds

0. **Baseline.** The current skills, effort `high`. Five runs of the sixteen
   briefs, the rubric on the first run of the ten briefs.
1. **Effort.** The same corpus at `medium` and `xhigh`: tells whether the
   effort moves the variance enough to be the default lever, and at what
   cost.
2. **One skill rule at a time.** Each round changes one rule, aimed at the
   largest source of variance the baseline shows, and runs only the briefs it
   concerns, then the whole corpus once the rule is kept. A rule is kept when
   the structure agreement rises and the rubric does not drop.
3. **Closing run.** The whole corpus with every kept rule, the rubric on the
   ten briefs, the results written in `docs/evaluation/determinism/results.md`.

The rules to try first, each a hypothesis the baseline confirms or not:

- **A canonical order per kind of page**, with no optional step left to the
  model: `page-structure` lists the sections of each kind in an order and says
  which ones a brief turns on, rather than describing them.
- **Tie-break rules** where two components fit: "questions and answers always
  go in the questions component", "a table of figures always goes in the
  component made for it when the catalogue has one, free text otherwise".
- **A fixed vocabulary of section ids** (`intro`, `features`, `faq`,
  `products`, `contact`…), so that a revision and a translation keep the same
  ids and the runs can be compared section by section.
- **Counts taken from the brief**: one question per question the brief asks,
  one card per point it makes, never a number of the model's choosing.
- **Headings said, not invented**: a heading names what the section holds in
  the words of the brief, with no slogan.
- **The page type as the anchor**: a series is written from its model page and
  its instructions; measure how much a model page alone fixes the structure.

## Cost and time

About 0.05 USD a page at effort `high` (the evaluation of 2026-10-20 cost
0.475 USD for ten pages). A round of the whole corpus: 16 briefs × 5 runs ≈
4 USD and about 25 minutes. Baseline, two efforts, four rule rounds and the
closing run: about 30 USD, in two to three days of work, the command
additions and the script included.

## Open questions

- Does a site get a setting to choose between a predictable writer and a
  freer one (the effort, or a skill switched on), or do the shipped skills
  aim for predictable only?

## Resuming

Read, in this order: this plan, `docs/evaluation/determinism/results.md`,
`docs/evaluation/README.md` (the rubric), then the rules of
`skills/catalogue/SKILL.md` and `skills/page-structure/SKILL.md`.

Frozen: the rules of rounds 2a, 2b and 2c stay; 2d and 2e are not tried
again as they were written; effort `high` stays the default; the runs stay
out of the repository; the bar is 4 runs out of 5.

Before playing anything:

1. The bench must be on the site (`bench.json` imported once, pages `eval-*`
   hidden, type `eval-workshop`), and its copy must match `bench.json`.
2. No key of `I18n/` changes during a round: the prompt reads translated
   labels (`docs/developing.md`).
3. The first round to play is a control: the skills of round 2c, unchanged,
   five runs per brief. How many briefs move between it and `round-2-tiebreak`
   is the noise; a rule is kept only when it moves more than that.

Prompt for a new session:

> Resume the determinism plan of TheliaCMS (`docs/plans/deterministic-skills.md`,
> section Resuming). Play the control round first with
> `docs/evaluation/determinism/run-round.sh round-3-control`, compare it with
> `thelia_cms:compare-runs` against `round-2-tiebreak`, write the noise in
> `results.md`, then propose the next rule (the length of the `intro` is the
> first candidate) with its expected effect and cost before playing it.

