# Webhooks: telling other tools what changed

In short, the module sends three events through the webhooks of Thelia:
a page goes online, a page goes offline, a visitor's message is kept from a
form. A tool subscribes under **Configuration > Webhooks**
(`/admin/configuration/webhooks`) and receives a signed `POST` for each. Sending,
signing, retries and the log of deliveries all belong to the core; the module
only says when, and what the body holds.

## The events

| Event | Sent when | `data` in a full payload |
|---|---|---|
| `cms_page.published` | A page goes online in a language, and every time it is published again | The page in that language |
| `cms_page.unpublished` | A page that was online in a language is taken offline in it | The page in that language |
| `cms_form.submitted` | A message sent through a form is kept | The message and its answers |

Every path that publishes ends in the same place, so the settings screen, the
content screen, the bulk actions of the list and `thelia_cms:publish` all send
the event. These send nothing:

- the contents of a kind (the header zone, a size guide of a shop module) and
  the content written for a product, a category, a content, a folder or a
  dealer of the catalogue;
- a change of visibility, of the publication window or a move to the bin: the
  page leaves the site, but nobody published or unpublished it;
- the import of a site, which writes the published version itself;
- a form set not to keep its answers (**Keep the answers in the back office**
  unticked): the message has no id to give;
- a message the form refuses (wrong field, robot, rate limit, a check from
  another module such as a captcha).

## Subscribing

1. Open **Configuration > Webhooks** and add a subscription.
2. Give the HTTPS address of the receiver and tick the events above.
3. Choose the **Content of the request**: the full payload, or the identifiers
   only (see below).
4. Keep the secret it shows: the receiver checks the signature with it.

## What a request holds

Every request has the same envelope, written by the core: `id` is the id of the
delivery (the same when it is sent again), `type` the event, `created_at` when
it was raised, `data` the payload. A page published:

```json
{
    "id": "0b7a6af0-b9cc-447b-8143-dfa187639097",
    "type": "cms_page.published",
    "created_at": "2026-11-19T18:07:23+01:00",
    "data": {
        "id": 8146,
        "locale": "en_US",
        "title": "Nos engagements",
        "type": "default",
        "url": "https://shop.example.com/nos-engagements",
        "publishedAt": "2026-11-19T18:07:22+01:00"
    }
}
```

`type` in `data` is the code of the page type. `url` is the address of the
page in that language, on the host of the language; the home page is given as
the root of the site. It is `null` when the address cannot be built.
`cms_page.unpublished` carries the same fields.

A message kept from a form:

```json
{
    "id": "afb15a40-6536-4159-b790-14d058d33fac",
    "type": "cms_form.submitted",
    "created_at": "2026-11-19T18:07:22+01:00",
    "data": {
        "id": 5261,
        "form": "contact",
        "locale": "en_US",
        "email": "jeanne@example.com",
        "consent": true,
        "answers": [
            { "name": "email", "type": "email", "label": "Email", "value": "jeanne@example.com" },
            { "name": "message", "type": "textarea", "label": "Message", "value": "I would like a quote" },
            { "name": "contact_me", "type": "consent", "label": "You may contact me", "value": true, "granted_at": "2026-11-19T18:07:22+01:00" }
        ],
        "createdAt": "2026-11-19T18:07:22+01:00"
    }
}
```

`form` is the code of the form, `id` the message as listed in the **Answers**
of the form. `answers` are the answers as they were kept: the question
as it was asked, the answer, and for an agreement the moment it was given.
`consent` is true when every agreement of the form was given, and when the
form asks for none.

### Personal data

A full `cms_form.submitted` payload carries what the visitor typed: name,
address, free text. The core does the same with the addresses of an order.
Sending it to a tool makes that tool a processor of the data, which the privacy
policy of the site has to cover. A receiver that needs none of it subscribes to
the **identifiers** only, and receives:

```json
"data": { "id": 5261 }
```

### The identifiers of a page do not say the language

With the identifiers only, a page event carries the id of the page and nothing
else: a page published in French then in English sends two requests that look
the same. A receiver that needs the language takes the full payload.

## Signature

The core signs each request:

```
X-Thelia-Webhook-Id: 0b7a6af0-b9cc-447b-8143-dfa187639097
X-Thelia-Webhook-Timestamp: 1795108043
X-Thelia-Webhook-Signature: v1=94f91c6b5f069a6a6e99aabc19d80a4e18cb6786b825a9ccb20d46b2934a8111
```

The signature is `v1=` followed by the HMAC-SHA256, keyed by the secret of the
subscription, of the timestamp, a dot and the raw body. Refuse a request whose
timestamp is more than five minutes off, and compare signatures in constant
time (`hash_equals()` in PHP). `Thelia\Domain\Webhook\WebhookSigner::verify()`
does both.

## Delivery

A shop with no queue (`MESSENGER_TRANSPORT_DSN` empty, the default) sends the
request while the action runs: the editor who publishes, and the visitor who
sends a form, wait for the receiver, up to 10 seconds. A failed request is
logged and not sent again.

With a queue, the request leaves the action at once and a worker sends it. A
failed request is tried again after 1 minute, 5 minutes, 30 minutes and
2 hours; after the fifth failure the subscription is set in error and sends
nothing until someone turns it back on. A site with a form on a busy page sets
a queue.

Every delivery, its status and the answer of the receiver are listed under
**Configuration > Webhooks**, where a failed one can be sent again.

## Not covered yet

- The events of the contents of a kind and of the content of the catalogue.
- Reading a page or a message back through the API: the payload is all the
  receiver gets for now.

See ADR 22 for why the events go through the webhooks of the core.
