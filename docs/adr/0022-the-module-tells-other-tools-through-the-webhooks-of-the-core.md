# 22. The module tells other tools through the webhooks of the core

Date: 2026-11-19. Status: accepted.

## Context

A site wants a tool outside the shop to know when a page goes online or offline,
and when a visitor sends a message: a CRM that takes the leads, a search index
or a static cache that follows the pages. Thelia 3.3 brought webhooks: a
catalog of events, subscriptions with a secret, signed deliveries, retries
through Messenger and a screen that lists them. The catalog names, for each
event, a resource and its serialization groups, and the body of a full payload
is that resource serialized. The module had no resource of its own and no event
after a publication or a message: `SubmissionCheckEvent` (2.36.0) is dispatched
before a message is kept, so that another module can refuse it.

## Decision

**The module adds its events to the catalog of the core and publishes them once
the change is committed, with payload classes of its own that no route serves.**

- Three events, named as the core names its own and prefixed like the tables
  of the module: `cms_page.published`, `cms_page.unpublished`,
  `cms_form.submitted`. They are merged into `thelia.webhook.events` by a
  compiler pass, never set: a parameter set by two modules keeps only the last.
- The payload of each is a class that implements `PropelResourceInterface`
  without `#[ApiResource]` (`Webhook/PagePayload.php`,
  `Webhook/SubmissionPayload.php`): the transformer of the core reads no
  metadata of API Platform, so the body is built as the core builds its own,
  and no endpoint opens.
- A page publishes after the commit, from its target of the content writer, so
  every path that publishes sends the event; a content of a kind and the
  content of an item of the catalogue publish nothing. Taking a page offline
  publishes only when it was online in that language. The subject is the page
  at the locale published.
- A message publishes right after it is kept, so a form that keeps no answers
  publishes nothing. Its full payload carries the answers, as the core's
  carries the addresses of an order; a subscriber that wants none of them asks
  for the identifiers only.

## Consequences

- Subscribing, signing, retrying and the log of deliveries are the core's: the
  module has no table, no screen and no setting for them.
- Without a queue, the core sends the request while the action runs: a slow
  receiver slows the editor who publishes and the visitor who sends a form, up
  to 10 seconds. `docs/webhooks.md` tells a busy site to set a queue.
- With the identifiers only, a page event carries the id of the page and not
  its language, the subject of a delivery being one row. Left as is: the full
  payload carries the language.
- The payload classes are what a read-only API of the pages and the messages
  would start from (lot F8-F3-bis of the roadmap); until then the payload is
  all a receiver gets.
- A change of visibility, of the publication window, a move to the bin and the
  import of a site take a page off the site or put it back without an event.
