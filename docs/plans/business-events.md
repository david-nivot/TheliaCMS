# Plan: business events through the webhooks of the core (F3)

Status: decided (2026-11-19, by the maintainer); step 1 done on `feat/f3-business-events`. Read on `main` at e9331e8 (2.38.0),
core 3.5.0 in the test host. Wave 5 of the roadmap, lot F3 ("Événements métier"), size S. The
second step (read-only API resources) is F8-F3-bis, wave 6, and stays out of this plan. Each step
updates this plan in its own commit.

Abbreviations: CW = `Content/Editing/ContentWriter.php`, CT = `Catalogue/Admin/ContentTargets.php`,
PT = `Page/Admin/PageContentTarget.php`, KT = `Page/Admin/KindContentTarget.php`,
IT = `Catalogue/Admin/ItemContentTarget.php`, SS = `Form/SubmissionStore.php`,
FC = `Form/Front/CmsFormSubmitController.php`, TC = `TheliaCMS.php`, and in the core
(`core/lib/Thelia/`) WC = `Domain/Webhook/WebhookEventCatalog.php`,
WB = `Domain/Webhook/WebhookPayloadBuilder.php`, WP = `Domain/Webhook/WebhookPublisher.php`,
WL = `Domain/Webhook/EventListener/PublishWebhookEventsListener.php`,
AT = `Api/Bridge/Propel/Service/ApiResourcePropelTransformerService.php`.

## State verified on e9331e8

### The webhooks of the core

- WC declares five events (`order.created`, `order.status_changed`, `order.paid`,
  `customer.created`, `product.updated`), each with a resource class and serialization groups,
  and merges those of the modules from the parameter `thelia.webhook.events`, set to `[]` by
  `Config/Resources/parameters/webhook.php`. No module sets it yet (searched in `core/`,
  `local/modules/`, `vendor/thelia`).
- `WP::publish(string $eventName, ActiveRecordInterface $subject)` never throws, does nothing
  for a name outside the catalog or without an active subscription, writes one
  `webhook_delivery` per subscription in its own transaction, then dispatches `DeliverWebhook`.
- Each subscription picks its payload (`payload_mode`): `full`, the default, is the resource
  serialized with the groups of the event (AT `modelToResource()` then the normalizer, WB:39-44);
  `identifiers` is `{"id": <primary key of the subject>}` (WB:35-37). The envelope is
  `{id, type, created_at, data}` (WB:62-73).
- AT needs a class implementing `PropelResourceInterface`; it reads no API Platform metadata.
  It calls `afterModelToResource()` on the resource and dispatches `ModelToResourceEvent::AFTER_TRANSFORM`
  (`api_after_model_to_resource`). Read, not yet proven: step 1 proves it.
- The subscription form of the back office offers every name of the catalog
  (`templates/backOffice/default-twig/src/Form/Configuration/WebhookSubscriptionType.php:62`):
  an event the module declares shows up there with no screen to add.
- The full payload of `order.created` already carries personal data (both addresses and the
  customer, `Api/Resource/Order.php:303-313`, `:379`); `identifiers` is the core's answer for a
  receiver that wants none.
- `DeliverWebhook` is routed to `async` (`Config/Resources/packages/framework.php:346`), whose DSN
  falls back to an inline transport while `MESSENGER_TRANSPORT_DSN` is empty (the default of
  `.env`): the delivery then runs in the request that published.
- WL publishes from Thelia events at priority 32, each call wrapped so that a failure is logged
  and never reaches the action.

### Where the module publishes and takes offline

- Every path (settings screen, content screen, bulk actions, `thelia_cms:publish`) ends in
  `CW::publish()` (:244), which commits (:263) and then calls `afterPublished()` on its target
  (:270); `CW::unpublish()` (:295) calls `afterUnpublished($wasOnline)` (:304).
- CT:68 hands out a PT for a page and a KT for a content of a kind (ADR 20); KT wraps a PT but its
  `afterPublished()` and `afterUnpublished()` (KT:102, :114) do not call the PT's. IT (:103, :120)
  is the article of a product, category or brand.
- Out of these paths, and so out of the events: the visibility of a page, its publication window
  (`publishAt`, `unpublishAt`), the bin, and the site import, which writes the published version
  itself (`ImportExport/ImportCommand.php`).

### The form

- FC dispatches `SubmissionCheckEvent` before anything is kept (:108; F3-D3), then calls
  `SS::keep()` (:140). SS saves a `CmsFormSubmission` only when the form keeps its answers
  (`store_submissions`, SS:48-56), then mails the recipients and the receipt.
- `Form/LeadEvent.php` is a name pushed into the data layer of the front, not a Symfony event.

## Decided (2026-11-19, by the maintainer)

- **F3-D1**: events go through the webhooks of the core, no mechanism of the module's own.
- **F3-D2**: step S only (events and documentation); API resources are F8-F3-bis.
- **F3-D3**: `SubmissionCheckEvent` (#224) stays as it is, before the message is kept; the
  submission event of F3 is a different one, after it is kept.
- **F3-D4, payload**: one class per event implementing `PropelResourceInterface` **without**
  `#[ApiResource]`: no route, no endpoint. F8-F3-bis may take it over or align with it.
- **F3-D5, the form**: the full payload carries the answers (form code, submission id, locale,
  date, consent, answers by field), as the core does for an order; `identifiers` carries the
  submission id. Only a kept submission publishes the event; the documentation says so.
- **F3-D6, scope and names**: pages only. `cms_page.published`, `cms_page.unpublished`,
  `cms_form.submitted`, prefixed by `cms_` like the tables of the module so that no event of the
  core can collide with them. Contents of a kind and articles of the catalogue publish nothing;
  listed as an extension in the documentation and the roadmap.

## Frozen with the decisions (defaults not objected to)

- The subject of a page event is the `CmsPage`, at the locale published (`setLocale()`, i18n
  behaviour of `cms_page`). In `identifiers` mode the receiver gets the page id without the
  language: a limit of the core, documented.
- The page payload: `id`, `locale`, `url` (absolute), `title`, `type` (code of the page type),
  `publishedAt`. `cms_page.published` is sent on every publication, a republication included;
  `cms_page.unpublished` only when the page was online in that language (`$wasOnline`).
- The events are published from PT only, after the commit (`afterPublished()`,
  `afterUnpublished()`), and from SS right after the submission is saved, before the mails. Each
  call is wrapped like WL's: a webhook never fails a publication or a message.
- Delivery stays the core's: inline unless the site sets `MESSENGER_TRANSPORT_DSN`. A slow
  receiver then slows the publication and the visitor who sends a form (up to the timeout of
  the core's client); the documentation tells the site to set a transport.
- The module adds its events to `thelia.webhook.events` by merging into the parameter in a
  compiler pass (`TC::getCompilers()`), never by setting it: a parameter set by two modules
  keeps only the last one.
- One ADR (number 22): events through the core's webhooks, payload classes without a route,
  personal data in the full payload of a form.

## Steps, in order

1. **Proof of the payload** (spike kept as a test): a payload class without `#[ApiResource]`
   goes through AT and the normalizer; the locale and the absolute URL are filled (by
   `afterModelToResource()` or a listener on `AFTER_TRANSFORM`); what the test environment does
   with `DeliverWebhook` (an inline transport would send a real request: read how the core's own
   tests of WP avoid it). Settles how the resource gets the URL of a page published from the
   command, which has no request of its own.
   **Done (2026-11-19).** `Webhook/PagePayload.php` (no `#[ApiResource]`, kept out of the
   services by `TC::configureServices()`) goes through WB unchanged:
   `Tests/Integration/Webhook/PagePayloadTest.php`, each assertion seen red under a sabotage (no
   address, no home rule, date read in another locale). Found on the way:
   - AT fills a property from the getter of the same name on the model: `id`, `locale`, `title`
     (i18n at the current locale) and `type` (`#[Column(propelFieldName: 'pageType')]`) come from
     `CmsPage`; `afterModelToResource()` adds the address and `publishedAt`, no listener needed.
   - A property named `url` would be filled by `CmsPage::getUrl()` (`UrlRewritingTrait`), which
     throws without the URL singleton and ignores the home page answering on "/": the property
     is `$address`, serialized as `url`.
   - The address is the sitemap's, moved out of `SitemapThemeHook::urlOf()` into
     `Page/PublicAddress.php`, which both use. It is null when the URL singleton does not exist;
     `thelia_cms:publish` creates it (`Http/ConsoleRequest.php:48`) and publishes inside
     `around()`.
   - In tests the `async` transport is `sync://` (`thelia.messenger.inline_transport_dsn`): a
     delivery is sent at once. The core's tests replace `thelia.webhook.http_client` by a
     `MockHttpClient` (`tests/Integration/Domain/Webhook/WebhookPublishingTest.php:61`); steps 3
     and 4 do the same.
2. **Catalog**: the compiler pass that merges the three names with their class and groups; a test
   that the catalog holds the core's five and the module's three, and that a second module's
   entry survives.
3. **Page events**: publication from PT; integration tests: an active subscription receives one
   delivery with the expected envelope in each mode; nothing for a refused publication, an
   unpublish of a page already offline, a content of a kind, an article; nothing without a
   subscription.
4. **Form event**: publication from SS; tests: full payload with the answers, `identifiers` with
   the submission id, nothing for a form that keeps no answers or a refused check.
5. **Documentation**: `docs/webhooks.md` (events, payloads with an example of each, the language
   limit of `identifiers`, the transport, what publishes nothing), a section of the README,
   ADR 22, CHANGELOG under `[Unreleased]`.

Every test is seen red by sabotaging the code it covers before it is committed. No schema change,
no migration.

## Estimate (pilot days, read on e9331e8)

| Step | Days |
|---|---|
| 1. Proof of the payload | 0.25 |
| 2. Catalog | 0.25 |
| 3. Page events | 0.25 |
| 4. Form event | 0.25 |
| 5. Documentation, ADR | 0.25 |
| Contingency of the lot (step 1 may need a listener, the test transport) | 0.25 |
| **Total** | **1.5** |

## Not checked

- The fields a form can hold beyond text (a file field would send its name only, never its
  content): to read in `Form/` at step 4.
