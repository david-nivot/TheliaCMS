# Plan: CSS allow list and a Content-Security-Policy (S6)

Status: done on `fix/css-filter-csp` (2026-11-04). Read on `main` at ff033b1, coded on 2465698.

## Findings verified

### The CSS filters

- `PublishedContentSanitizer::css()` (`Content/Pipeline/PublishedContentSanitizer.php:190-216`)
  is a deny list of four regexes: `@import`, `expression(`, `url()` to another
  host (`//host`, `scheme://`), `behavior:`. Open: it is the only filter for page
  CSS; nothing checks it is CSS at all.
- Passes today, by reading (open): `url(data:text/html,...)`, `url(javascript:...)`
  and `url(data:image/svg+xml,...)` (the host pattern needs `//` right after the
  scheme); `image-set("https://x/p.png" 1x)`, `image()`, `src()`, `cross-fade()`
  (no `url(` token, the string form is a fetch); any backslash escape
  (`u\72l(https\3a //x)`, `\@import`). Escapes are refused only for components
  (below), not for page CSS.
- The same filter runs on every `style` attribute (`filterStyleAttributes`,
  `:154-163`), called from `sanitize()` at `:113`. `style` is allowed on every
  element (`:220`, `:264`). Done: attributes get the filter. Open: they inherit
  its holes.
- Components of the site: `SiteComponentValidator.php:103` refuses `\` and
  `url|image-set|image|cross-fade|element|src(` in the stylesheet (done, strictest
  of the module); `:109` also runs `css()`; `:112` runs `SiteCssScoper::scope()`
  which refuses at-rules other than `@media/@supports/@container/@keyframes`
  (`SiteCssScoper.php:100-146`) and strips comments (`:68`). The scoper's
  selector splitting relies on the no-backslash rule (`:300-ish`, docblock of
  `selectors()`). Open: no allow list of functions; `attr()`, `env()`, `paint()`,
  `-moz-binding`, `image-set` variants are caught only by name.
- Template markup of a component: `:76` refuses `<style|script>`, `on*=`, `javascript:`; `:82` refuses inline `animation`.
- Section appearance, `ElementStyle` and `SiteStylesCss` carry no free CSS (`#rrggbb` only, `SectionAppearance.php:31,90-99`; pattern-matched values). Done.
- Existing tests: `PublishedContentSanitizerTest.php:262-292`; its "keeps" test (`:283`) expects `url(/image-library/..)` and `url(data:image/png;..)` to pass. The roadmap wording (no function but colours, calc, var, gradients) would break it: decision 1.

### Headers

- Core 3.4.0 `ResponseHeadersListener` (`vendor/thelia/core/lib/Thelia/Core/EventListener/ResponseHeadersListener.php`,
  priority -1024, main request, only if absent) sets `X-Content-Type-Options: nosniff`,
  `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`
  on every page. Done for those three, on 3.4.0. Not verified on 3.3.0 (module
  requires `^3.3`, `composer.json:8`); CI also tests 3.3.0.
- No CSP anywhere in core, Flexy or the test host (`grep -i Content-Security|nonce`
  over `core/src`, `vendor/thelia`, `config`, `templates/frontOffice/flexy`:
  only `BinaryFileController`, `DocumentCacheProtection`, minified JS). `nelmio`
  in vendor is `nelmio_cors` only (`config/packages/nelmio_cors.yaml`). No nonce
  mechanism in core or Flexy; the only trace is Symfony AssetMapper's
  `ImportMapRenderer` copying a `nonce` attribute if one is passed (`importmap('app')`
  in `base.html.twig:91` passes none).
- Module headers today: `Front/PageDocumentController.php:67,88` (`nosniff`,
  `Content-Security-Policy: sandbox` on served documents; done); previews
  `Referrer-Policy: no-referrer` (`Preview/*Controller.php`, `ItemDraftResponse.php:42`;
  done, S8); `X-Robots-Tag` in `Page/CmsPageViewListener.php:92`. No CSP on
  public CMS pages.
- Cache: `Http/PageCachePolicy.php` may let a proxy keep a page up to a day (opt-in): a per-request nonce would go stale.

### What the consent layer injects (a CSP must not break it)

- `templates/front/consent.html.twig:10,23,37`: three inline `<script>` at
  `layout.head.top` (`Consent/ConsentThemeHook.php`): Consent Mode defaults,
  Axeptio loader (`//static.axept.io/sdk.js`, then the SDK itself loads from
  other `axept.io` hosts and images), reviver. Only when `ConsentSettings::isConfigured()`.
- `Script/ScriptRenderer.php:38` writes admin-entered snippets raw, at
  `layout.head.bottom` / `body.top` / `body.bottom` (`Script/ScriptPlacement.php:35-37`),
  wrapped by `Script/ConsentGate.php` in `<template data-cms-consent="vendor">`
  when a category is named: inert until the reviver rebuilds the nodes (images
  and iframes load then). Snippets without a category run at once. So image,
  frame, font and connect hosts depend on what the administrator pastes: unknown to the module.
- CHANGELOG 2.3.1 (`:558`) promises no tracker bypassing consent via `style`; an `img-src` CSP is the second line.

## Decisions to make

1. **Page CSS and `url()`.** (a) Literal roadmap: no `url()` at all in page CSS
   and `style`; breaks `background-image` from the media library and the
   existing test `:283`. (b) Allow list of functions with `url()` kept for a
   same-site path (`/...` not `//`) and `data:image/(png|jpeg|gif|webp|avif)`;
   components keep the full ban. Recommended (b): same reach as today's legitimate
   use, closes every hole above.
2. **Filter engine.** (a) Harden the regexes. (b) A small tokenizer in a new
   `Content/Pipeline/CssAllowList` (strings, comments, `\` refused, function
   names read as tokens, anything not on the list replaced by the declaration
   dropped). Recommended (b); regexes cannot see `image-set("..")` or escapes.
   Refusal policy: drop the declaration (page CSS is stored, not model-written),
   never rewrite the value.
3. **What "nonce-based" can mean.** A nonce only exists for `script-src` /
   `style-src`; it does nothing on `img-src`, `font-src`, `frame-ancestors`.
   Options: (a) nonce plus `script-src`: needs a nonce in the three inline scripts of
   `consent.html.twig`, in admin snippets (cannot be injected into pasted HTML
   reliably), in Flexy/core inline scripts (`checkout-gateway.html.twig:18`), and
   conflicts with the page cache. (b) Host-based `img-src`/`font-src`/`frame-ancestors`
   only, no `script-src`, no nonce. Recommended (b): it is what the roadmap
   actually protects; nonce goes to a later lot if a strict `script-src` is wanted.
4. **Rollout and scope.** Setting `csp_mode` in the module config: `off` (default
   on upgrade) / `report-only` / `enforce`, written by `TheliaCMS::setConfigValue`
   (same pattern as `http_cache_ttl`), a field on the "Scripts and measurement" screen.
   Report-only header gets a `report-uri` to a module route that logs only. Recommended;
   alternative "enforce by default on new installs only" is a follow-up.
5. **Whose `img-src` allowlist.** Default `'self' data:` plus hosts: (a) a free
   text field of extra hosts the administrator fills; (b) derive hosts from
   the snippets' `src`/`url` strings (fragile). Recommended (a), prefilled with
   `https://*.axept.io` when consent is configured; the screen warns that a
   snippet's pixel host must be listed. Fonts: `'self' data:` plus
   `fonts.gstatic.com` only if the theme loads Google Fonts (Flexy does,
   `base.html.twig:22`): detect nothing, list it in the same field.
6. **Where the header is set.** (a) Listener on `kernel.response` limited to
   routes the module renders (page, home, search, 404, maintenance). (b) Every front
   route. Recommended (a): the shop pages (cart, payment gateways) are outside the
   module's remit and would break (payment frames, `frame-ancestors`).
7. **N9 note (not planned).** A served SVG will need `nosniff` and `script-src 'none'` (plus `sandbox`, as `PageDocumentController.php:88`); reuse the helper of step 4.

## Steps in order (each test written first and seen red against `main`)

1. Unit tests on `css()`/`style` with the holes of the findings (`data:text/html`,
   `javascript:`, svg data URL, `image-set("https://..")`, `src()`, escape
   `u\72l(`, `\@import`, `-moz-binding`, `attr()`), plus "keeps" cases
   (colours incl. `rgb()/hsl()/oklch()/color-mix()`, `calc/min/max/clamp/var`,
   `linear/radial/conic-gradient`, `translate/rotate`? decision 1, `url(/image-library/..)`).
   Red now: the first group. Keep the existing hostile list green.
2. `CssAllowList` tokenizer (decision 2), wired into `css()`; run the whole
   `PublishedContentSanitizerTest` and `SiteComponentValidator` tests; add a test
   that a component stylesheet keeps its stricter refusals (`SiteComponentValidator.php:103`).
3. Inventory of stored page and component CSS in a real base copy: count declarations the new list drops.
4. `Http/ContentSecurityPolicy` (builds the header from settings, pure, unit-tested:
   off returns null; report-only uses `Content-Security-Policy-Report-Only`;
   directives `img-src`, `font-src`, `frame-ancestors 'self'`). Red first.
5. `Http/ContentSecurityPolicyListener` on `kernel.response`, main request, module
   routes only, never overwrites a header already set; integration test on a
   page, a 404, a preview and a document (the document keeps `sandbox`).
6. Setting + admin field + migration default `off`; report route logging
   (rate-limited, no personal data, bounded body); French and English strings
   (`I18n/fr_FR.php`).
7. `nosniff`: add in the listener only when absent, so core 3.3.0 gets it (test with a response lacking it).
8. Browser check on the test host (1440 px): with and without Axeptio, a categorised snippet stays inert then loads after consent, no CSP report in report-only, then enforce.
9. README, `docs/developing.md` pointer, CHANGELOG entry.

## Files touched

- New: `Content/Pipeline/CssAllowList.php`, `Http/ContentSecurityPolicy.php`, `Http/ContentSecurityPolicyListener.php`, report controller, their tests.
- Edited: `PublishedContentSanitizer.php` (css), `SiteComponentValidator.php` (only if the shared list absorbs `:103`), `Script/Admin/ConsentSettingsType.php` and its template, `TheliaCMS.php`, service wiring, `I18n/fr_FR.php`, README, CHANGELOG, `PublishedContentSanitizerTest.php`.
- Not touched: `Script/ConsentGate.php`, `templates/front/consent.html.twig` (no nonce, decision 3).

## Risks and pitfalls

- Enforce breaks pasted pixels, widgets and Axeptio images unless hosts are listed: report-only first, a week of real traffic before advising enforce.
- `frame-ancestors` is ignored in `<meta>`: header only. Do not set `'none'` (back-office previews frame same-origin); core already sends `X-Frame-Options: SAMEORIGIN`.
- Dropping declarations changes stored pages silently: step 3 and a CHANGELOG line; filter at render time, never rewrite stored data.
- Page cache: the header must be identical per route (another reason to avoid a nonce).
- Flexy loads Google Fonts: `font-src 'self'` would block them.
- Tokenizer: unquoted `url(`, backslash-newline, case, NUL/BOM; fuzz-style list.
- Core 3.3.0 header behaviour unverified (step 7). A header set by a theme or another module is kept and a notice logged.

## Estimate (senior dev + AI agent, quarter-hour granularity)

- Steps 1-3 (tests, tokenizer, data inventory): 5 h 30
- Steps 4-6 (policy, listener, setting, report route, i18n): 5 h 00
- Steps 7-9 (nosniff fallback, browser pass, docs): 2 h 45
- Total: about 13 h 15. Assumption: decisions 1b, 2b, 3b, 6a taken as recommended
  (no nonce, no `script-src`), core 3.3.0 behaves like 3.4.0 for headers, and a real
  database copy is available for step 3. With a nonce-based `script-src` (3a) add 8 to 12 h.

## Decided (2026-11-03)
Frozen by the maintainer, not to be reopened: the recommendations of this plan as written. A small allow-list CSS tokenizer (same-site `url()` and `data:image/*` kept on page CSS, components keep the full ban); a host-based CSP on `img-src`, `font-src` and `frame-ancestors`, no nonce, no `script-src`; `csp_mode` setting off by default / report-only / enforce, with a logging report route; host fields prefilled (axept.io when consent is configured); headers only on routes the module renders, never overwriting an existing header.

## Decided during the lot (2026-11-04)
- The "Scripts and measurement" screen answers 404 to everybody since `6118c65` (`Security/CmsAdminGuard.php:85`): the CSP fields go on the settings screen (`/admin/cms/settings`), under the settings permission, next to the shared cache TTL. A CSP only restricts: an administrator who weakens it goes back to today's state.
- Step 7 dropped: core 3.3.0 already sends `X-Content-Type-Options`, `X-Frame-Options` and `Referrer-Policy` (`ResponseHeadersListener`, `03b33292a`, in the 3.3.0 tag), as 3.4.0 does.
- Step 3 measured on the test host: 38 stored `style` attributes and 5 component stylesheets, none changes under the new filter (the comparison was seen to catch a planted case).
