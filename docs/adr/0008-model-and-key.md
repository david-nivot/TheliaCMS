# 8. Model, effort and key

Date: 2026-10-20. Status: superseded in 2.4.0 (model, effort and key as settings of the module).

Superseded in 2.4.0: the provider, the key and the model are set in the AI settings of Thelia and the range of each usage chooses the model; the module keeps only the effort, the length of an answer and the reading of web pages (the effort moved to the core in 2.42.0, see the amendment of 2026-11-21) (`Generation/GeneratorSettings.php`). `ANTHROPIC_API_KEY` is no longer read. The text below is the decision as it was taken.

## Context

The model and how hard it thinks are choices a site revisits; a key is a
secret that must never travel with an export.

## Decision

`claude-opus-5-5` with effort `high` and 16 000 output tokens by default,
all three as settings of the module (CMS > Settings > Generator). The key is
read from `ANTHROPIC_API_KEY` in the environment only; without it the screen
says so and nothing is called.

## Consequences

- An export carries the model and the effort, never the key.
- Every environment that generates needs the variable in its deployment.

## Amendment of 2026-11-04: the output limit

The default output limit of a page and of a component goes from 16 000 to
48 000 tokens; the texts beside a field keep 2 000. The 16 000 had no reason
written down, and a long page went over it: thinking and the whole document
count in the limit, a revision, a section or a translation writes the document
again, and an answer cut at the limit is paid and thrown away.

- Only what is written is billed, so the limit costs nothing until it is
  reached. The AI layer of Thelia reserves the budget of the whole limit on
  every call, though: on Claude Opus 5.5, 0.96 USD at 48 000 against 1.28 at
  64 000, which only matters near a tight monthly budget. 48 000 is three times
  the former margin and keeps that reservation under a dollar.
- The AI layer holds a call under the limit of its usage and of its model as
  well. An answer cut below the limit of the module says so and points at the
  AI settings of Thelia, where raising the limit helps.
- The settings screen writes the limit on every save. The update to 2.10.0
  raises a stored 16000, the former default, and keeps any other value.

## Amendment of 2026-11-21: the effort is the core's

Thelia 3.4 holds the reasoning effort itself: the merchant sets it per range
of model and, when a usage needs another, per usage (`AiEffort`,
`ai_tier_assignment.effort`, `ai_usage_setting.effort`), and the adapter of
Anthropic applies it to every call that carries no native `effort` of its own.
The module no longer keeps `generator_effort` and `generator_text_effort`
(2.42.0, which requires Thelia 3.4): its generators send no effort, and the
core's applies. The `--effort` option of `thelia_cms:generate` stays as an
explicit choice for one call, sent as the native option, which wins.

- Update 2.42.0 writes the value a site had chosen (`high` and `low` when it
  had chosen none, the former defaults) as the effort of each usage that has
  none yet, so no site changes cost or behaviour. An effort a merchant already
  set in the core is kept.
- `cms_page_generation.effort` stays: it records the effort a call asked for
  explicitly, and is empty when the AI settings decided.

