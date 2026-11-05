# 12. Content does not depend on Generation

Date: 2026-11-05. Status: accepted.

## Context

`Content` holds the document of a page, its sections, the catalogue, the
pipeline that validates, filters and publishes it (ADR 1, 2). `Generation`
holds the generator that writes documents from a brief (ADR 3, 5). The
document is there whether the generator is configured or not: a site without
an API key still writes, publishes and prints pages.

Two controllers of `Content/Admin` (the screens of the components of the site)
imported six classes of `Generation`: the stream of events, the candidates, the
generator, its request, its exception and its ledger. Nothing in the layout
said that `Content` depended on `Generation`, and nothing stopped a third file
from doing the same.

## Decision

**No file of `TheliaCMS\Content\` names a class of `TheliaCMS\Generation\`.**

- The two controllers moved to `TheliaCMS\Component\Admin\`, a namespace that
  may depend on both. Their routes, addresses and templates are unchanged.
- A PHPStan rule, `Tests/PHPStan/ContentDoesNotDependOnGenerationRule`,
  registered in `phpstan.dist.neon`, reads every name of a file of `Content`
  (imports, `new`, static calls, constants, types, `instanceof`, `catch`,
  attributes; written with a `use` or in full) and reports the ones that
  resolve into `Generation`. A name in a comment is not read.
- The rule sits in `Tests/`, which the container and the archive leave out:
  PHPStan is not installed in production, and a class that implements one of
  its interfaces must not be loaded there. The CI loads it through the
  autoloader of the install (`composer.json` maps the empty prefix to
  `local/modules/`).
- The rule is not in the baseline: a violation is fixed, never listed.

## Consequences

- `Generation` may use `Content`; the other way round is refused by the CI.
- A screen that needs both (a component made by the generator and kept in the
  catalogue) lives outside `Content`, under the feature it belongs to.
- The rule covers `Content` only. Other directions (`Page`, `Preview`) are
  not checked.
