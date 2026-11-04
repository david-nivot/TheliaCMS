# Plan: a ledger row from the start of a call, and a cut stream taken up once (G9 G5)

Status: coded on `fix/generation-run-tracking` (2026-11-04).

## Findings verified (module at 418afb2, core c5f6a38cd)

- The ledger row of a call is written at its end only: `PageGenerator::generate()`
  builds a `$record` closure (`Generation/PageGenerator.php:159-177`) called on
  success (`:247`) and in four `catch` blocks (`:251-269`): `GenerationRefusedException`,
  `InvalidGenerationException`, `GenerationException`, `AiExceptionInterface`.
  Any other `\Throwable` (Propel, a `TypeError` in a tool), a memory limit or a
  killed process leaves no row, though the call is paid. Same shape in
  `Generation/Text/TextGenerator.php:112` and `Generation/Component/ComponentGenerator.php:111-195`.
- `GenerationRunner` catches `GenerationException` only (`Generation/Admin/GenerationRunner.php:63`, `:93`):
  anything else breaks the stream with a 500 half way.
- Nothing stops a second call on the same target and language while one runs.
- The core gateway retries once, by itself, a retryable failure that came before
  any event (`core/lib/Thelia/Domain/Ai/Gateway/AiGateway.php:106-128`). A provider
  429 (`RateLimitExceededException`) and an `overloaded_error` / `api_error` (529, 500:
  `ServerException`) are retryable (`symfony/ai-platform` `RetryableExceptionInterface`).
- A failure after the first event reaches the module as `AiProviderUnavailableException`
  with `retryable` and the `logId` of a core row `aborted`, priced from the tokens
  received (`AiGateway.php:132-140`, `:186-221`). The module keeps that `logId` but
  adds nothing to the cost (`PageGenerator.php:191-194`): the ledger and `spentSince()`
  under-count. The core row is `Thelia\Model\AiUsageLog` (`getCost()`).
- `ObservedStream` (`Generation/Ai/ObservedStream.php:50`) throws when a stream ends
  without `StreamCompleted`; the core always yields it after a normal end, so this
  is a defensive path.
- `Tests/Support/FakeAiGateway.php:144-158` can throw only before the first event.
- `Config/update/2.10.0.sql` is not published (latest tag 2.9.0): it takes the new columns.

## Decided (2026-11-04)

1. **A row from the start, for the three generators** (pages and catalogue items
   through `PageGenerator`, texts, components). Status `running` written before the
   first gateway call, touched at every turn (so `updated_at` moves), completed at
   the end with the outcome. Every exit writes the outcome: the four existing
   catches, plus a `catch (\Throwable)` that writes `failed` with a sentence for the
   editor (translated), logs the exception with its trace, and rethrows it as a
   `GenerationException` (previous kept). `GenerationRunner` also catches
   `\Throwable` as a last resort (a `$save` that fails), logs it, and answers with
   the error event or a flash.
2. **Interrupted**: a `running` row not touched for 30 minutes is a call that
   died. It is written `interrupted` when the ledger reads it (history, `find()`,
   the guard below). The screen shows `running` and `interrupted` with their own
   labels, FR and EN.
3. **Second call refused** on the same target type, target id and language while
   a fresh `running` row exists (page and catalogue item calls of `PageGenerator`
   only; not texts, not components, whose new ones all share id 0). The refusal
   is a `GenerationUnavailableException` without a ledger row: "A generation is
   already running for this page in this language (started at %time% by %name%)."
   Check then insert, no lock: a race of a few milliseconds is accepted.
4. **A stream cut half way is taken up once**: `ObservedStream` tells a cut (a
   retryable `AiProviderUnavailableException` after at least one event, or an end
   without `StreamCompleted`) from a failure before any event, which the core has
   already retried and which stays a failure. On the first cut the same
   conversation is sent again; it counts neither as a turn nor as a correction.
   The screen says the answer was cut and is asked again (observer). A second cut
   fails with the current message.
5. **Cost of a failed or cut request**: whenever a core exception names a usage
   log row, its cost (`AiUsageLog::getCost()`) is added to the cost of the call.
6. **Columns**: `effort` (VARCHAR, null), `corrections` (INT, 0), `retries` (INT, 0),
   `tools` (JSON object `{name: count}`, null) on `cms_page_generation`, in
   `schema.xml`, `TheliaMain.sql` and `Config/update/2.10.0.sql` (replayable through
   `information_schema`), filled by the three generators, shown on the history line
   of the content screen ("2 corrections, 1 retry, read_url ×2, effort high").
7. **Tests** on `FakeAiGateway`, which learns to cut a stream after some events:
   running row seen during the call; unexpected `\Throwable` gives a `failed` row;
   stale row read as `interrupted`; guard refuses a fresh row and lets a stale one
   pass; one cut taken up, cost of the cut row added; second cut fails; 429 and 529
   before any event (retryable, no event) not taken up by the module; columns
   filled. Each test seen red by a sabotage.

## Out of scope

- A strict lock (row lock or Symfony Lock) against the millisecond race.
- A `$save` that fails after a `done` row: the row stays `done`; logged only.
