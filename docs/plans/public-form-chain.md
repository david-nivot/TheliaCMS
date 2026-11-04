# Plan: the public form chain (Q7, S3, P11)

Status: done on `fix/public-form-chain` (`Form/Throttle`, `Http/PageCacheListener`); see
"As built" at the end. Read on `main` at ff033b1 (2.9.0). Wave 2 of the roadmap, one PR:
the tests first (Q7), then the fixes they cover (S3, P11).
S9 (return path) is already done: `Form/Front/ReturnPath.php`, called at
`Form/Front/CmsFormSubmitController.php:134`, with `Tests/Unit/Form/ReturnPathTest.php`.

## Findings verified
Flow: `CmsFormSubmitController::__invoke` (62-123): closed form (75), trap and
stamp (86), validation (93), rate limit (105), `SubmissionStore::keep` (114),
flash, redirect. No CSRF token, on purpose (docblock 41-45).

- **Receipt echoes the answers: OPEN.** `SubmissionMailer::sendReceipt` (70-91) sends to the address typed by the
  visitor, with `answers: $answers` (87); `htmlBody` (124) and `textBody` (146) list every label and value. Default
  intro is "Here is a copy of what you sent" (86). Anyone can make the shop mail arbitrary text, in its own name, to
  any address. The admin help says "What the person reads above their own answers" (`Form/Admin/CmsFormType.php:111`).
- **Token is a signed timestamp, reusable: OPEN.** `AntiSpam::stamp` (54) = `issuedAt.HMAC(code|issuedAt)`. Accepted
  when 3 s <= age <= 12 h (40, 43, 99). Nothing is recorded on use: one stamp serves any number of posts for 12 h. A
  rejected stamp is told "sent" (controller 86-91) and only logged.
- **Counter not atomic: OPEN.** `SubmissionRateLimiter::allows` (54-91) does `findOne` (65), then
  `incrementAttempts()` (80), which in the core (`core/lib/Thelia/Model/FormFirewall.php:28`) is read, +1, `save()`.
  `form_firewall` has no unique key on (form_name, ip_address) (`local/config/schema.xml:2544-2554` of the host): a
  parallel burst on a fresh bucket inserts several rows and all pass. The bucket is the HMAC of the IP cut to 15
  characters (`VisitorFingerprint.php:37,60`).
- **No ceiling per destination address: OPEN.** The only key is the sender IP (`allows`, 54). Many IPs can post the
  same victim address as email field: one receipt each. Notification recipients are set by the admin, max 10
  (`Recipients.php:26`): not a target.
- **Purge and retention: DONE, untested end to end.** `SubmissionPurger::purge` (33-55) deletes per form past
  `RetentionPolicy::cutoff` (default 365, max 3650, 0 = keep; `RetentionPolicy.php:28-43`), soft-deleted forms
  included; run by `thelia_cms:forms:purge` and by `maintenance:purge` (`SubmissionPurgeListener.php:35`). Only
  `RetentionPolicyTest` exists. Rate-limit rows of the core table are deleted by `forgetOldAttempts` (101) per form
  name only when someone posts that form again.
- **Page cache vs the token: OPEN.** `FormPartial::context` (76-116) puts a fresh `stamp` in the HTML (93); the
  fragment is never cached (`cacheTtl()` null, 123). But the whole page is: `PageCacheListener` (52-82) calls
  `PageCachePolicy::apply` (82-121), TTL = `http_cache_ttl`, max 86 400 s (`PageCachePolicy.php:42,48`), `s-maxage`
  (109). Nothing tells the policy the page carries a form: `PartialRenderer.php:119` only files the tag
  `cms-partial-cms-form` via `ShownTags`. A page cached over 12 h serves a stamp that `stampIsPlausible` refuses, and
  the visitor is told "sent" while nothing is stored. With the core shared cache on, `PageCacheListener` stops at
  69-73 and the core listener (priority -1026, below the module's -1024) writes the TTL
  (`SharedCacheListener::makePublic`, `SharedCachePolicy::ttl()`): the module has no say.
- **Single use and a shared cache conflict.** A page served from a proxy gives the same stamp to every visitor. A
  strictly single-use stamp lets only the first of them post. Decision D1.

## Existing tests
- Unit: `AntiSpamTest` (9 cases: trap, too fast, too old, other form, other secret, back-dated, malformed),
  `SubmissionValidatorTest`, `RecipientsTest`, `RetentionPolicyTest`, `ReturnPathTest`, `SubmissionDataTest`,
  `SubmissionExportTest`, `FieldChoicesTest`, `FieldOptionsTest`.
- Integration: none for forms. `git grep` of `CmsFormSubmission`, `cms.form.submit`, `SubmissionRateLimiter`,
  `SubmissionMailer`, `SubmissionPurger`, `FormPartial` in `Tests/` finds only a mention in
  `Page/PageInvalidationTest.php`. Related: `Http/PageCacheHeadersTest.php` (policy headers; no form page),
  `Unit/Http/PageCachePolicyTest.php`.

## Decisions to make
**D1. Single-use token and cached pages.**
A) Single use per (stamp, visitor bucket), recorded in a table: the cache keeps
working, each visitor posts once per stamp; replay from one IP stops, replay
from many IPs is left to the rate limit. B) Strict single use, and a page with a
form is never public (`private, no-store`): safest, kills the cache on every
page with a form, so P11 becomes a no-op. C) Keep multi-use, only do P11.
Reco A: it honours the roadmap (token consumed, TTL capped) without making
the first visitor behind a proxy the only one who can write.

**D2. Where the atomic counter lives.**
A) Keep the core `form_firewall`, one `UPDATE ... SET attempts = attempts + 1
WHERE id = ? AND attempts < ?` and test the affected rows: no migration, but the
first insert still races (no unique key; adding one to a core table is not ours).
B) A module table `cms_form_throttle` (unique key form, bucket; window start;
count) with `INSERT ... ON DUPLICATE KEY UPDATE`: one statement, race-free, also
serves the per-address ceiling; costs a migration and a model. The
`form_firewall_*` settings keep being read.
Reco B: the burst on a fresh bucket is the real attack and only a unique key
closes it.

**D3. Ceiling per destination address.** Key = HMAC of the lower-cased address
(never stored in clear), in the same table as D2. Proposal: 3 receipts per address
per 24 h, checked before sending the receipt only (the message is still stored
and notified). Alternative: refuse the whole submission. Reco: skip the receipt
silently, keep the submission; the values are constants first, settings later.

**D4. Receipt content.** Fixed text = the admin subject/body of the form, or a
default; no answers, no label, no echo of any field. Option: keep a switch to
include answers. Reco: no switch, remove the capability; change the default
intro (no more "copy of what you sent") and the admin labels "Copy subject",
"Copy message", "Send a copy" in `CmsFormType.php:101-115`, `I18n/en_US.php`,
`I18n/fr_FR.php`. Existing sites with a custom body keep it, minus the answers.

**D5. TTL cap with the core shared cache on.** A) Cap only the module path
(`PageCachePolicy`), document that `shared_cache_ttl` must be <= 11 h on a site
with forms. B) A listener below -1026 that lowers `s-maxage` on a response whose
shown tags contain `cms-partial-cms-form`. Reco B if the host's listener order
checks out in test, else A with a doc line.

## Steps in order
Each test is written, run red against `main` behaviour (sabotage or the
unfixed code), then the fix. Base class `CmsIntegrationTestCase`; mail captured
by a test subclass of `Thelia\Mailer\MailerFactory` overriding `send(Email)`
(public, class not final; `RecordingMailerFactory` of the core records only
templated mail, not `sendSimpleEmailMessage`).

1. **Q7 helpers.** Create a form through `CmsFormWriter`; a signed stamp via `AntiSpam::stamp($code, now - 10 s)`; a
   Request with session; call the controller service.
2. **Q7 tests, green on `main` (describe today):** submission stored with hashed IP and email; trap and bad stamp
   answer "sent", store nothing; invalid field flashes errors; closed form refused; notification sent to recipients
   with reply-to; seventh post from one IP refused; purge deletes past retention and keeps the rest, soft-deleted form
   included. Sabotage each assertion once.
3. **Q7 tests, red on `main` (the S3/P11 cases):** the receipt body contains no answer; the same stamp twice from one
   IP stores once; 20 posts through parallel connections on a fresh bucket let through at most 6 (run with `exec` of
   several PHP processes or `pcntl`; else assert the SQL is a single statement and test the limiter on a forged
   duplicate row); the same address from 4 IPs gets 3 receipts; a page with a form and `http_cache_ttl` 86 400 answers
   `s-maxage` <= the stamp validity. One commit with the failing tests.
4. **S3 receipt (D4).** `SubmissionMailer::sendReceipt` drops `answers`; a `receiptHtml`/`receiptText` pair; defaults
   and admin help; i18n.
5. **S3 counter (D2).** Migration `Config/update/<next>.sql` (replayable, `information_schema` check) +
   `TheliaMain.sql` + `schema.xml` + `sqldb.map`; `SubmissionRateLimiter` rewritten on one statement; purge of old
   rows in `SubmissionPurger` or its own listener.
6. **S3 token (D1).** `AntiSpam` gets a nonce in the stamp (or hashes the stamp), a table of spent stamps (unique,
   `INSERT IGNORE`, affected rows); the controller consumes it after validation passes, before `keep`, so that a
   mistyped form can be re-sent with the same stamp. Tell the visitor nothing more than today (same "sent").
7. **S3 ceiling (D3).** Same table as step 5, key = HMAC of the address.
8. **P11 (D5).** `PageCachePolicy::apply` receives the shown tags (already an argument, `$tags`); when they contain
   `CacheTags::partial(FormPartial::NAME)`, `ttl()` is min(ttl, `AntiSpam::MAXIMUM_SECONDS` minus margin). A constant
   on `AntiSpam` rather than a number in the policy. Core path per D5.
9. Gates from `local/modules/TheliaCMS`: `composer stan`, `composer cs`, `composer test`, `composer test:integration`
   (`bin/test-prepare` first after the schema change, `var/propel/test` removed, `DATABASE_NAME` unset: see
   `docs/developing.md`). CHANGELOG `[Unreleased]`, `docs/shared-cache.md` (form pages), a line in the form docs on
   the receipt.

## Files touched
`Form/SubmissionMailer.php`, `Form/SubmissionRateLimiter.php`, `Form/AntiSpam.php`,
`Form/Front/CmsFormSubmitController.php`, `Form/SubmissionStore.php`,
`Form/SubmissionPurger.php` (or a new listener), `Form/Admin/CmsFormType.php`,
`Http/PageCachePolicy.php` (+ a listener if D5-B), `Config/schema.xml`,
`Config/TheliaMain.sql`, `Config/sqldb.map`, `Config/update/<next>.sql`,
`Config/module.xml`, `I18n/en_US.php`, `I18n/fr_FR.php`, `CHANGELOG.md`,
`docs/shared-cache.md`. New: `Tests/Integration/Form/` (submission, limiter,
mailer, purge), `Tests/Unit/Http` cases, new Propel model files for the tables.

## Risks and pitfalls
- A stamp in HTML cached for 12 h and consumed once per visitor: tests must cover two visitors on one stamp (D1-A),
  else the fix breaks the contact form.
- A rejected stamp is still answered "sent": the visitor whose stamp expired loses the message silently. Out of scope,
  worth a flash asking to reload.
- The test needs the 3 s minimum: forge the stamp with `$now`, never sleep.
- Schema change: Propel models (`var/propel/*`) and the compiled test container must be rebuilt, else the tests run on
  stale classes (`docs/developing.md`).
- `TINYINT attempts` of the core table caps at 127: another reason for D2-B.
- Parallel-burst test needs real concurrency; transactions of `IntegrationTestCase` hide rows from other connections:
  run that case with `$useTransaction = false` and clean up by hand, only on the test database.
- Removing the answers from the receipt changes mail sent by existing sites: CHANGELOG entry under "Changed", noting
  the admin text is still sent.

## Estimate
Senior dev with an AI agent, tests and gates included: 13 h.
Q7 helpers and tests 3.5 h; receipt 1 h; migration, model, atomic limiter 2.75 h;
single-use token 2 h; destination ceiling 1 h; P11 (incl. core-path check) 1.5 h;
docs, i18n, changelog 0.75 h; contingency 0.5 h (included).
Assumption: D1-A, D2-B, D3, D4 and D5-B as recommended; the host runs one PHP
process model that can fork for the burst test. D1-B or D2-A cuts about 2 h;
a real-concurrency test that needs a harness of its own adds 1.5 h.

## Decided (2026-11-03)
Frozen by the maintainer, not to be reopened:
- D1, single-use token: **no public cache for a page that carries a form** (not the recommended option A). The page is served `private` (no shared cache), so each display gets its own token, consumed once. P11 and D5 (capping the shared TTL at the validity of the token) become moot for such pages: check that nothing else caches them (core shared cache included) rather than cap a TTL.
- D2, D3, D4: the recommendations. Module table `cms_form_throttle` with a unique key and `INSERT ... ON DUPLICATE KEY UPDATE` (new update SQL version); 3 receipts per address per 24 h (HMAC of the address), the receipt skipped silently beyond, the submission kept; receipt with a fixed text (admin text or default), never the answers, no switch.

## As built (2026-11-04)
Where the code departs from the steps above, within the decisions:
- One table, `cms_form_throttle` (primary key scope + keyed hash, `hits`, `expires_at`), serves the three counters:
  sender per form (the `form_firewall_*` settings), receipts per address (3 per 24 h) and spent stamps (1 per 12 h).
  One upsert, the count read back through `LAST_INSERT_ID(expr)`. The window is fixed: it opens with the first hit,
  where the core table moved with every refused attempt. Migration `Config/update/2.10.0.sql`, which also deletes the
  `cms_form_*` rows of `form_firewall`: the release that ships it must be 2.10.0 or later.
- The stamp is `moment.nonce.signature`; a stamp without a nonce (pages open during the update) is still accepted
  while current. It is spent after the rate limit, just before the message is kept.
- P11/D5: `PageCacheListener` sets `private, no-store` on any response whose tags show the form partial, module path,
  shop pages and core shared cache alike (the core does not share a `no-store` response, `SharedCachePolicy::isShareable`).
- Out of plan, fixed: `maintenance:purge --dry-run` deleted the submissions; it now counts them.
- Tests: `Tests/Integration/Form/` (chain, cache, purge, burst by forked processes) and
  `Tests/Integration/Module/FormThrottleMigrationTest.php`. On `main`, the burst let 20 of 20 through (limit 6).

