# Plan: shared cache and deadlines (S7)

Status: done on `fix/cache-deadlines` (`Page/ScheduledChanges`, `Http/SharedCache/SharedMaxAgeCapListener`).
Read on `main` at `ff033b1` (2.9.0), not on the working tree.
Ask: `s-maxage = min(ttl, next deadline - now)`; no shared caching while
maintenance is active. F5 (scheduled publishing) depends on it.

## Findings verified (D = done, O = open)

- D. One writer of page headers: `Http/PageCacheListener.php:52-81` (RESPONSE,
  priority -1024) calling `PageCachePolicy::apply` (`Http/PageCachePolicy.php:88-120`):
  `public, max-age=0, s-maxage=<ttl>` or `private, no-store`. TTL = setting
  `http_cache_ttl`, default 0, max 86400 (`:36-50`). No `ETag`/`Last-Modified` on
  pages (only `Front/PageDocumentController.php:80-83`, document download,
  `max-age=300`).
- D. Only responses carrying `_cms_cache_tags` get headers
  (`Page/CmsPageViewListener.php:98`). The home page takes the same path
  (`Page/CmsHomeViewListener.php`). A shop page listing pages keeps the shop's
  headers (`PageCacheListener.php:76-78`).
- D. Core shared cache on (`CoreSharedCache::isActive`): the module only tags
  (`PageCacheListener.php:69-72`); the core writes `s-maxage = shared_cache_ttl`
  (`SharedCacheListener`, priority -1026, so after the module; core 3.4.0
  `core/lib/Thelia/Core/SharedCache/SharedCacheListener.php`, `makePublic`) or
  `Surrogate-Control: max-age` in ANNOUNCE mode. It refuses a response already
  `no-store` (`SharedCachePolicy::isShareable`).
- D. Sitemap, catalogue item content: no HTTP header set by the module (grep
  `Cache-Control|setPublic|setMaxAge` in `Sitemap/`, `Catalogue/`: none). The
  sitemap's server-side cache already stops at the next deadline:
  `Sitemap/SitemapThemeHook.php:189-215` + `SitemapBoundary` + `SitemapCache.php:66-69`.
  Catalogue contents have only `published_at`, no deadline.
- D. Columns `cms_page.publish_at` / `unpublish_at` (`Config/schema.xml:67-68`),
  language-independent. Read by `PublishedPageRepository::isLive` (`:364-379`):
  a future `publish_at` or past `unpublish_at` gives 404, never cached
  (`isCacheable` needs 200, `PageCachePolicy.php:68`). Also in menus, search, sitemap.
- O. Nothing caps the TTL by a deadline: a page cached `s-maxage=3600` that
  unpublishes in 5 min stays served 55 min more. A page's own date is not enough:
  lists, menus and breadcrumbs filter by `isLive`, so a page goes stale at the
  deadline of any page it shows (D1).
- D. Maintenance, visitors: `Vitrine/MaintenanceListener.php:67-117` (REQUEST,
  priority 40) answers 503 + `Retry-After` + `Cache-Control: no-store, private`
  (`:113`). Saving settings purges tag `cms` (`Settings/Admin/CmsSettingsController.php:143`),
  carried by every page (`Http/CacheTags.php::forPage`).
- O. Maintenance, bypass: an allowlisted IP (`MaintenanceListener.php:80`) or a
  signed-in admin (`:86`) reaches the real site; `PageCacheListener` and
  `PageCachePolicy` ignore maintenance (grep `isMaintenanceActive`: settings and
  the listener only). An allowlisted IP without cookie gets `public, s-maxage`
  (module's or core's): the proxy keeps the real site and serves it to everyone,
  bypassing the 503. An admin has a session: `hasPreviousSession`
  (`PageCachePolicy.php:72`) and the core's cookie rule already block it.
- Not verified: a real proxy; an index on the date columns (only `parent`
  indexes seen, `schema.xml:122-128`).

## Decisions to make

1. Which deadline caps the TTL. (a) The page's own `unpublish_at`. (b) The next
   deadline of the site (min of `publish_at > now`, `unpublish_at > now` over
   visible, unbinned, non-model pages), as the sitemap computes. (a) is wrong for
   any page showing a list or menu; (b) shortens TTLs more often and costs one
   `MIN` query per cacheable response. Reco: (b), result kept in `cache.app`
   until the boundary.
2. Where to cap. (a) In `PageCachePolicy::apply` only. (b) Also with the core on,
   by a listener below -1026 lowering `s-maxage` / `Surrogate-Control`. With (a)
   the core path is uncapped and F5 does not hold. Reco: (b).
3. Maintenance. (a) `PageCacheListener` forces `private, no-store` first thing
   when the setting is on, whatever let the request through. (b) Mark the request
   at the bypass in `MaintenanceListener`. (a) is one config read and survives a
   new bypass; (b) couples two listeners. Reco: (a).
4. Remaining time below 1 s: (a) `private, no-store`, (b) `s-maxage=0`. Reco: (a).
5. Deadline query: (a) extract `nextChange` from `SitemapThemeHook` into a shared
   service, (b) duplicate. Reco: (a), covered by `SitemapCostTest`.

## Steps in order (each test written first, seen red)

1. Service `ScheduledChanges::nextAfter(now): ?DateTimeImmutable` moved out of
   `SitemapThemeHook`. Integration test: dated, binned, hidden and model pages.
   Red: class absent. `SitemapCostTest` stays green.
2. `PageCachePolicy::sharedMaxAge(ttl, boundary, now): ?int` (null under 1 s =
   no-store). Unit tests: no boundary, beyond, inside, past; sabotage seen red.
3. `apply()` uses it. Integration: page `unpublish_at = now+120`, ttl 600, header
   `s-maxage <= 120`; same for a page listing a page leaving in 120 s.
4. Core mode: listener at -1030 lowers `s-maxage` and `Surrogate-Control`
   `max-age`, never raises. Test beside `CoreSharedCacheTest` (core on, ttl 300,
   boundary 60 s). Red: stays 300.
5. Maintenance: forced `private, no-store` in `PageCacheListener`. Tests:
   setting on, allowlisted IP without cookie, module mode and core mode; setting
   off keeps today's headers. Red on `main`.
6. Gates (existing cache tests, `composer stan`, `composer cs`), `CHANGELOG.md`,
   help text of `httpCacheTtl` (`Settings/Admin/CmsSettingsType.php:69`) + `I18n`.

## Files touched

- New: `Page/ScheduledChanges.php` (name open), core-mode cap listener in `Http/`, tests.
- Edited: `Http/PageCachePolicy.php`, `Http/PageCacheListener.php`,
  `Sitemap/SitemapThemeHook.php`, `Settings/Admin/CmsSettingsType.php`, `I18n/*.php`,
  `CHANGELOG.md`. Not touched: `MaintenanceListener`, schema.

## Risks and pitfalls

- Clock: `TIMESTAMP` vs PHP `now` vs proxy clock; the sitemap compares with PHP
  time. Inject one clock in tests. Test dates built to the second (MariaDB/MySQL
  rounding, see CLAUDE.md).
- The boundary query runs per cacheable response: measure, cache it, check for
  an index before adding any (a migration would be a new `Config/update` file).
- A proxy that ignores `s-maxage` keeps the page until purged: the cap does not
  unpublish on the minute; that is F5's purge.

## Needed by F5 (not a plan for F5)

F5 needs "next deadline of the site" as one service with an injected clock,
shared by the page cache (this lot), `SitemapCache` (today) and the server-side
`cache.app` partials, which drop only on writes and would show an expired page
past its deadline. It needs `publish_at` arrivals in the boundary (included
here), and a purge or scheduled command at the boundary for proxies that ignore
`s-maxage`.

## Estimate (senior dev with an AI agent)

4 h 30 min: 1 h service and sitemap extraction, 1 h policy and unit tests,
1 h 15 min core-mode listener and test, 45 min maintenance, 30 min gates and
changelog. Assumption: no index or migration is needed for the date query; with
one, add 1 h.

## Decided (2026-11-03)
Frozen by the maintainer, not to be reopened: the recommendations of this plan as written. Cap by the next deadline of the whole site (shared `nextChange` service extracted from the sitemap hook); cap also when the core shared cache is on (listener after -1026); `private, no-store` at the top of `PageCacheListener` while maintenance is on; `no-store` when less than 1 s remains.
