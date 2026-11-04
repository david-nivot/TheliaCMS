# 8. Model, effort and key

Date: 2026-10-20. Status: superseded in 2.4.0 (model, effort and key as settings of the module).

Superseded in 2.4.0: the provider, the key and the model are set in the AI settings of Thelia and the range of each usage chooses the model; the module keeps only the effort, the length of an answer and the reading of web pages (`Generation/GeneratorSettings.php`). `ANTHROPIC_API_KEY` is no longer read. The text below is the decision as it was taken.

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
