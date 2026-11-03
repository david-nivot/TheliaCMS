# Plan: front-office performance (P1, P3, P10)

Status: done on `perf/front-queries` (PR #65), four commits in the order
below. The plan was read on `origin/main` at 1753f25 (2.8.4); the numbers of
statements it gives under "Expected" were derived from the code before any
run. The measured ones are under "Measured", at the end.

## What is asked

- **P1**: the sitemap reads its pages with one query, resolves the addresses
  in one `IN` per language, and keeps its answer in a cache that
  `PageInvalidator` empties.
- **P3**: the callers that only need to know a page is reachable stop reading
  the whole page (`find()`) and ask `isReachable()`.
- **P10**: `ofType()` loads the translation of the pages with the pages, not
  one query per page.
- **The test**: an integration test that counts the SQL statements of the
  read, red on `main`, green after.

## Measuring statements (shared by the three tests)

### What is there today

- A counter already exists, private to
  `Tests/Integration/Page/PageListingCostTest.php:213-234`
  (`statementsFor()`): the Propel connection of the test
  (`getPropelConnection()`, `vendor/thelia/core/lib/Thelia/Test/IntegrationTestCase.php:234`)
  is a `ConnectionWrapper`; `useDebug(true)` then `getQueryCount()`
  (`vendor/thelia/propel/src/Propel/Runtime/Connection/ConnectionWrapper.php:621,667`).
  Prepared statements are counted too (`StatementWrapper.php:95,212` go
  through `callUserFunctionWithLogging`, which increments, `ConnectionWrapper.php:524`).
- The method of that test is the model: it does not assert a number, it
  asserts that the cost **does not depend on the number of pages**
  (`PageListingCostTest.php:34-51`, small tree against a tree four times the
  size, `assertSame`). The core has no `QueryCounter`
  (`grep -rn getQueryCount vendor/thelia/core`: nothing).
- No test of the module renders a CMS page through the kernel (patterns
  `client->request` in `Tests/Integration`: only
  `Front/PageDocumentDownloadTest.php`, on file routes). "The display of a
  page" is therefore measured at the service level, below.

### Principle

- Extract `statementsFor()` into a trait `Tests/Integration/CountsStatements.php`
  (used by `PageListingCostTest` and the three new tests): commit 0, no
  behaviour change.
- Assert **flat cost** (same count for 3 pages and for 12), plus, where the
  fix is a column choice (P3), the statement text. For the text: pass a
  `PSR\Log\LoggerInterface` fake to `ConnectionWrapper::setLogger()`
  (`ConnectionWrapper.php:711`) with `useDebug(true)`; every statement reaches
  `log()` (`:738`). Put the previous logger back in `finally`.
- Two pitfalls from the existing helper: `useDebug(false)` resets the counter
  (`ConnectionWrapper.php:669-673`), so read `getQueryCount()` before
  restoring the mode, as `statementsFor()` does; and Propel's **instance pool**
  makes a second `findPk()` of the same page free, so a count taken after a
  warm-up measures less than a cold request. Each measured read starts from
  `\Propel\Runtime\Propel::disableInstancePooling()` / re-enabled in `finally`
  (to confirm it exists in the Thelia fork before relying on it), or from a
  `CmsPageTableMap::clearInstancePool()` (generated map, to confirm).
- The core URL memoizer (`Tools/URL.php:260-270`) also makes the second
  `retrieve()` of one `(view, id, locale)` free: clear it with
  `URL::getInstance()->clearRewritingUrlCache()` (`URL.php:339`) before each
  measure.

### Estimate (commit 0)

15 min.

## P10: `ofType()` joins the translation

### What is there today

- `Page/PublishedPageRepository.php:185-196`: the pages of the type are read
  with their content joined, without the translation.
- In the loop over **every** page of the type (201-223, before the filter,
  the sort and the cut at 230): `valuesOf()` at 207 reads
  `getTranslatedFields()` (`Page/Field/PageFields.php`, after `setLocale`,
  when the type defines fields) and `getTitle()` at 218 after `setLocale`
  at 213. Each is a read of `cms_page_i18n`; the generated `getTranslation()`
  runs `CmsPageI18nQuery ... findOneOrCreate` when the translation is not
  already in the collection (`var/propel/dev/model/TheliaCMS/Model/Base/CmsPage.php:3770-3788`
  in the test host). So **one statement per page of the type** (the object
  keeps it per locale, `currentTranslations`, so one, not two).
- In the cut (232-250), per returned card: `getUrl()` (core
  `Model/Tools/UrlRewritingTrait.php:53-60`, one rewriting query unless
  memoized), `imageOf()` (`PublishedPageRepository.php:405`, library
  image query when the page has one), `tagOf()` / `tagColorOf()` (`getCmsTag()`,
  one query per page object, pooled across pages of the same tag).
- The same shape in `withTag()` (118-161: `getTitle()` 149, `getUrl()` 150).
- Precedent: the back office already joins the translation
  (`Page/Admin/CmsPageAdminRepository.php:111,204,266`,
  `joinWithI18n($locale, Criteria::LEFT_JOIN)`), and
  `PageListingCostTest` proves the cost flat.
- Callers: `Twig/CmsExtension.php:172` (`cms_pages_of_type`),
  `Partial/Definition/PageListPartial.php:109`. Nothing else
  (pattern `ofType(` outside tests).

### Principle

`->joinWithI18n($locale, Criteria::LEFT_JOIN)` on the query at 185 (same
signature as the admin: `CmsPageQuery.php:2211` generated, `with('CmsPageI18n')`,
one-to-many off). LEFT, as in the admin: a page with no row in the language
stays in the list (see edge cases).

Hypothesis to confirm on the first run: the hydration of the joined row fills
`currentTranslations` (generated `addCmsPageI18n`, `CmsPage.php:3584-3588`),
so `getTitle()` and `getTranslatedFields()` stop querying. If the statement
count does not move, `joinWithI18n` is not enough and the translation has
to be read once by `CmsPageI18nQuery::filterById($ids, IN)` and handed to the
loop.

### Files

`Page/PublishedPageRepository.php` (`ofType()`; `withTag()` has the same
loop, see "To settle" 3), `Tests/Integration/Page/PublishedPageCostTest.php`
(new), `CHANGELOG.md`.

### Test

`PublishedPageCostTest::testListingPagesOfATypeCostsTheSameWhateverTheirNumber`:
- fixture: a page type with at least one field (so that `valuesOf()` is not
  short-circuited at `PageFields.php:51`, `isEmpty()`), 3 pages then 12,
  all published, **no image, no tag**, `limit` 100, through
  `createPage()` (`Tests/Integration/CmsIntegrationTestCase.php:244`) with
  `PageTypeWriter` for the type (see `Tests/Integration/Page/PageTypeTest.php`
  for the way a type is made);
- `statementsFor(fn () => $repository->ofType($type, $locale))`, `assertSame`
  between the two sizes.
- Expected, before: slope of 1 statement per page from the translation, plus
  1 from `getUrl()` per page (rewriting on, memoizer cleared): count for N
  pages is about `2 + 2N` (hypothesis; the "2" is the page query, and the
  fields definitions). After the join alone: about `2 + N` (the URLs remain).
  After the join and a preload of the addresses (see "To settle" 2): flat,
  about 3 for any N.
- Red on `main`: the test fails because the counts differ (3 pages against
  12 give a difference of about 18 before, 9 with only the URLs left).
  Seen red by removing the `joinWithI18n` after the fix, and by
  checking that the failure message names the two counts.

### Edge cases

- **Offline page, scheduled publication** (`publish_at` in the future,
  `unpublish_at` in the past): filtered by `isLive()` at 202, in PHP, after
  the query; the join changes nothing. One test case with such pages in the
  set (they are read, then dropped: the count includes them, that is the
  current behaviour; the page, not the translation, is what costs).
- **Language missing**: a page with a published content in the language but
  no `cms_page_i18n` row (translated through an import, say). The LEFT JOIN
  keeps the page with an empty title; `getTranslation()` finds nothing in
  the collection and queries (`findOneOrCreate`), so that page costs 1 more,
  as today. The test with all translations present proves flatness; one
  case with a missing row asserts the card still comes out (empty title),
  not the count.
- **Replacement page** (404, maintenance): never listed by `ofType()` (they
  go through `find()`), untouched.
- A page of the type whose content is in another language only: not selected
  (filter on `locale` at 191), as today.

### Estimate

1 h 15 with the join alone (join 10 min, fixture and test 45 min, red
checks and CHANGELOG 20 min); **1 h 45** with the preload of the addresses
of the cut and `withTag()` (see "To settle" 2 and 3).

## P3: `isReachable()` where the page itself is not needed

### What is there today

`isReachable()` is `Page/PublishedPageRepository.php:314-328`, not 297: it
reads the page by pk (`isLive()`, 354-368: bin, `visible`, `model`,
`publish_at`, `unpublish_at`) then a `count()` on `cms_page_content` for the
locale with `published_at` and `published_html` not null. `find()` (45-89)
makes the same two checks and then **builds the whole page**: the HTML, the
translation (`getTitle`, `getChapo`, the SEO fields, `getTranslatedFields`),
the library image and its translation (`imageOf`, 405-448, plus `measure()`),
the og image, the tag and its colors. The conditions are the same (the
docblock says so, 306-313), so the swap does not change who is shown.

| Caller (origin/main) | Needs | Verdict |
|---|---|---|
| `Locale/AlternateUrls.php:122` (`cmsPage()`), for hreflang and the switcher | A yes or no, then `path()` for the address | `isReachable()`. Called once per active language by `all()` (61-63) and once per language by `CmsPageHreflangListener` (`Seo/CmsPageHreflangListener.php:50`) and the stand-in canonical (`Seo/StandInPageCanonicalListener.php:68`): the same `(page, locale)` is asked up to three times per request |
| `Menu/MenuTargetResolver.php:76` (`page()`) | A yes or no; the title comes from the `$page` read at 64 with `setLocale` at 70 | `isReachable()`. Run once per menu entry, on a cache miss of `MenuCache` (`Menu/MenuCache.php:47`) |
| `Page/CmsHomeViewListener.php:64` | A yes or no, then it only rewrites `_view` and `cmspage_id` (68-69) | `isReachable()`. The page is then read in full **once** by `CmsPageViewListener.php:76`, which does need it. Today the home page is built twice per request |
| `Form/FormCatalog.php:136` (`privacyPolicyUrl()`) | A yes or no, then `URL::retrieve` (147) | `isReachable()` |

Not changed, and why: `Page/CmsPageViewListener.php:76`,
`Vitrine/NotFoundPageListener.php:80`, `Vitrine/MaintenanceListener.php:124`
render the page (`$this->renderer->render($page)`), they need the whole page.
Already on `isReachable()`: `Seo/CmsPageSeoElement.php:140`,
`Vitrine/RewrittenAddressReachability.php:76`, `Generation/Tool/SearchPagesTool.php:84`.

### Principle

Replace `null === $this->pages->find(...)` by `!$this->pages->isReachable(...)`
at the four lines. No signature change.

Statement count: the two calls cost the same two statements for a bare page
(pk read, content read or count): **the count does not move for a page
without an image, tag or og image**. The gain is the columns (no
`published_html`, no `published_document`, which are CLOBs, and no
`provenance`) and, per language and per page, the statements `find()` adds
when the page has an image (library image, its translation), an og image or
a tag. So the test must give the page an image, an og image and a tag, and
assert on both the count and the text of the statements.

### Files

`Locale/AlternateUrls.php`, `Menu/MenuTargetResolver.php`,
`Page/CmsHomeViewListener.php`, `Form/FormCatalog.php`,
`Tests/Integration/Page/ReachabilityCostTest.php` (new), `CHANGELOG.md`.

### Test

`ReachabilityCostTest`, one case per caller, each on a page with an image, an
og image (`CmsPage::setOgImageId`), a tag and two published languages:
- `AlternateUrls::forLocale($locale)` for each language, with a
  `RequestStack` holding `Request::create('/x?cmspage_id=<id>')` and
  `_view=cmspage` (pattern: `Tests/Integration/Seo/StandInPageCanonicalTest.php:60,93`);
- `MenuTargetResolver::resolve()` on a menu item of type Page;
- `CmsHomeViewListener::onKernelView()` with `home_page_id` set (restore it in
  `tearDown`, as `NotFoundPageTest.php:40-55` does for `404_page_id`);
- `FormCatalog` (`privacyPolicyUrl()` is private: through the public method
  that calls it, to read in `Form/FormCatalog.php` before writing the case).
Each case asserts that **no statement selects `published_html`** (logger of the
shared helper) and that the count equals the expected (measured on the first
run, written in the test). Red on `main`: `find()` selects `published_html`
in `PublishedPageRepository.php:53-56` (the content is read whole), so the
text assertion fails before the count does; the count assertion is seen red
by putting `find()` back in one caller at a time (four breakages).

Statement counts, expected (hypothesis): per language,
`find()` = page pk (pooled after the first) + content row + translation
+ image + image translation + og image + tag; `isReachable()` = page pk
(pooled) + `count`. Gap per language and per page: between 3 and 5 statements
for a page with an image and a tag, to measure.

### Edge cases

- **Offline page** (`visible` 0, in the bin): `isLive()` is the one filter of
  both methods, the answer is the same; one case per caller with `visible=0`.
- **Scheduled publication**: `publish_at` in the future and `unpublish_at`
  in the past give `false` in both; the `$now` parameter (`isReachable(...,
  ?\DateTimeInterface $now)`) is not passed by the four callers, as it is not
  passed to `find()` today.
- **Language missing**: no `cms_page_content` row, or a row with
  `published_at` null (unpublished: `CmsPageWriter::unpublish()` nulls the
  date **and** the HTML, `Page/Admin/CmsPageWriter.php:533-538`): both return
  `false`. A row with a date and a null `published_html` is not reachable
  for either; the sitemap is looser (see P1).
- **Replacement page** (404 or maintenance served under another address):
  `AlternateUrls` reads the page id from the query string, which the
  listeners rewrite (`Tests/Integration/Vitrine/NotFoundPageTest.php:73-123`);
  the id handed to `isReachable()` is the one of the replacement page, as
  it is to `find()`. A case: `forLocale()` on a request rewritten to the 404
  page.
- **Home page**: the swap makes the listener cheaper but does not remove the
  second read by `CmsPageViewListener`; that one is needed.

### Estimate

1 h 30 (four swaps 10 min; four test cases with the image, tag and og image
fixture and the statement logger 50 min; four sabotages and PHPStan 20 min;
CHANGELOG 10 min).

## P1: the sitemap, one query and a cache

### What is there today

`Sitemap/SitemapThemeHook.php` (the hook answers `theme_hook('sitemap.urls')`,
`render()` 45-82):
- `locales()` (159-177): one query on the active languages (163).
- `publishedPages()` (109-155): one query on every page that is visible, not
  in the bin, not a model, with **all columns of `cms_page`** (113-117); one
  query on every `cms_page_i18n` row with `noindex` 1, hydrated whole
  (121); then, **per live page**, one query on `cms_page_content` with every
  column, `draft_html`, `published_html`, both documents and both
  provenances included (137-140). The window of publication is tested in PHP
  (128-133).
- `urlOf()` (179-194): `URL::retrieve()` for each `(page, locale)`, called at
  70 for the entry and again at 94 for every alternate. With rewriting on, the
  core memoizes the second call (`Tools/URL.php:260-270`), so one rewriting
  query per `(page, locale)`: **about N x L**. With rewriting off, none.
- Statements today for N live pages, L active languages all published, rewriting
  on (hypothesis from the code): `3 + N + N x L`, so about 303 for
  N = 100 and L = 2. The sitemap is regenerated on every crawler hit; no
  cache (patterns `cache|Cache` in `Sitemap/`: nothing).
- Looser than `isReachable()`: the entry needs `published_at` only
  (`SitemapThemeHook.php:139`), not `published_html` (`PublishedPageRepository.php:325`).
- `alternates()` (89-103) lists every locale the page has a published content
  in, **active or not**, while the entries are only for the active ones: a
  deactivated language would appear as an alternate (existing behaviour, see
  "Open questions").
- Tests: `Tests/Integration/Sitemap/SitemapThemeHookTest.php` (five cases;
  none on `publish_at` / `unpublish_at`, on a second language, or on a
  count), `Tests/Unit/Sitemap/SitemapEntryTest.php`.
- Invalidation, P4: `Page/PageInvalidator.php`. `contentChanged()` (50) and
  `presenceChanged()` (61) both end in `listsChanged()` (52, 77), which also
  runs after a move (`CmsPageWriter.php:952`). The writer calls one of the
  three on save (163-167), publish (409-412), unpublish (540), bin (587),
  restore (631), home change (818), and so on; the importer and the
  settings go through the writer
  (`Tests/Integration/Page/PageInvalidationTest.php:229`, "an import drops
  the whole site"). The model for a tagged cache is `Menu/MenuCache.php`
  (`TagAwareCacheInterface`, tag, `expiresAfter`, `invalidate()`; key by
  code, locale and a hash of the host, 51).

### Principle

1. **One query.** A single `CmsPageContentQuery` (or `CmsPageQuery` joined)
   with `select()` on the useful columns: `cms_page.id`, `publish_at`,
   `unpublish_at`, `cms_page_content.locale`, `published_at`, and the
   `noindex` of the translation of the same locale by a LEFT JOIN on
   `cms_page_i18n` (`id` and `locale`), conditions: not in the bin, `visible`
   1, `model` 0, `published_at` not null, and the window of publication in
   SQL with a bound `now` (the form already used at
   `Search/PageSearch.php:63-64`). No CLOB is read. Same entries as today
   (compare the XML before and after on the existing fixtures).
2. **The addresses in one `IN` per language.** Before building the entries:
   `URL::getInstance()->preloadRewrittenUrls(TheliaCMS::PAGE_VIEW, $locale,
   $ids)` for each locale of the entries and alternates
   (`Tools/URL.php:296-337`: one `RewritingUrlQuery` with `IN`, the row
   chosen as `getViewUrlQuery()` does, `RewritingUrlQuery.php:79-104`). The
   core of the test host (3.3.0) has it; so does the minimum of the module
   (`^3.3`, `composer.json`), to confirm on the 3.3.0 job of the CI. `urlOf()`
   keeps its `retrieve()` and finds the memoized value. Cut the ids by 500 so
   that the `IN` stays reasonable on a big site.
3. **A cache of the answer.** A `SitemapCache` next to `MenuCache`
   (`Sitemap/SitemapCache.php`, `final readonly`, same `TagAwareCacheInterface`):
   `get(string $key, callable $compute)` storing the **XML string**, tag
   `thelia_cms_sitemap`, `invalidate()`. It is emptied from
   `PageInvalidator::listsChanged()` (one place: both `contentChanged()` and
   `presenceChanged()` reach it, and so does a move, harmless), through a
   new constructor argument of `PageInvalidator`. Key and duration: see "To
   settle" 1 and 2.
   - Why the string and not the rows: it also saves the preload, the
     alternates and the escaping. The cost is one entry per
     `(context, lang, host)`.
   - The hook is `final readonly` and holds no state; the cache is injected.
4. **Scheduled publication has no event.** Crossing `publish_at` or
   `unpublish_at` changes the sitemap without any write, so no invalidation
   comes. The single query already reads both dates: compute the nearest
   future boundary and give the entry `expiresAt(min(boundary, now + ttl))`
   (see "To settle" 2). Zero extra statement.

### Files

`Sitemap/SitemapThemeHook.php` (query, preload, cache),
`Sitemap/SitemapCache.php` (new), `Page/PageInvalidator.php` (argument and
`listsChanged()`), the service definitions if `Config` declares
`PageInvalidator` explicitly (to check: autowiring is the default,
`configureServices`), `Tests/Integration/CmsIntegrationTestCase.php:218`
(`purgingWith()` rebuilds `PageInvalidator` by reflection and takes
unknown types from the container: `SitemapCache` is resolved by the
default branch, to check), `Tests/Integration/Sitemap/SitemapThemeHookTest.php`
(cache between tests, below), a new `Tests/Integration/Sitemap/SitemapCostTest.php`,
`Tests/Unit/Sitemap/` if the boundary computation is a pure function (it
should be: `SitemapBoundary::nextAfter(array $dates, \DateTimeInterface $now)`),
`docs/shared-cache.md` (one paragraph: what the sitemap keeps and what
empties it), `CHANGELOG.md`.

### Test

`SitemapCostTest`:
- **Flat cost**: 3 pages then 12, two languages, each page published in both;
  `statementsFor(fn () => $hook->render('sitemap.urls', ['lang' => ''...]))`
  on a **cold** cache (invalidate first), `assertSame` between the sizes.
  Expected, before: `3 + N + N x L` = 3 + 3 + 6 = 12 for 3 pages, 3 + 12 + 24
  = 39 for 12 (hypothesis). After: the query (1) + the languages (1) + one
  `IN` per language (L) = 4 for any N. Red on `main` by the difference, seen
  with the failure message.
- **Hit**: a second `render()` costs **0** statements (asserted equal to 0
  with the preload and the URL memoizer cleared, so that a zero cannot come
  from the memoizer). Seen red by removing the `get()` (the second call then
  costs 4).
- **Same output**: the XML of the cold render equals the XML of the render
  before the change (golden string built in the test from `SitemapEntry`, not
  copied from the output of the new code).
- **Invalidation** (one case each, the cache being warm first, then the
  event, then the sitemap read again): publish a new page, republish
  (`lastmod` changes: the case that a `presenceChanged()`-only hook would
  miss), unpublish, hide (`visible` 0 through the writer), bin, set `noindex`
  in the settings, change the address. Each seen red by taking
  `invalidate()` out of `listsChanged()`.
- **Scheduled publication**: a page with `publish_at` 2 s ahead: absent
  now, the cache entry expires at that date (`SitemapBoundary`, unit test
  with fixed dates: boundary inside the ttl, beyond it, none, an
  `unpublish_at` in the past ignored); and a page whose `unpublish_at` is
  ahead disappears after it. A real wait of 2 s is acceptable in one case;
  otherwise inject the clock (`$now` parameter of the query method).
- **Key**: two languages and two hosts give four entries that do not
  overwrite each other (`Request` with another `HTTP_HOST`).

The five existing cases of `SitemapThemeHookTest` write through
`$page->setVisible(0)->save()` and `->setNoindex(1)->save()`
(lines 49, 57), **not through the writer**, so nothing invalidates: with a
cache they read the previous test's entry (the cache lives outside the
transaction that rolls the rows back). `setUp()` calls
`SitemapCache::invalidate()` and these two cases call it after the direct
save, as `NativeContentMenuCacheTest.php:41-45` does for `MenuCache`.

### Edge cases

- **Offline page** (`visible` 0, bin, model): in the SQL conditions; cases
  already covered (`SitemapThemeHookTest.php:39-60`) stay green.
- **Scheduled publication**: see above; also the boundary must come from
  the pages that **would** appear or disappear, not only from the listed
  ones (a page whose `publish_at` is ahead is not in the result: the query
  must read it, in a second `SELECT MIN` or by keeping the rows of the window
  and filtering in PHP; settle at coding, the second `MIN` is one more
  statement on a miss only).
- **Language missing**: a page published in French only gives an entry
  for `fr_FR` and no alternate for the others (unchanged); a requested `lang`
  with no active match gives `''` and **is not cached under a key built from
  user input** (the `lang` parameter is normalized to an active locale or
  the empty string before it enters the key: otherwise a crawler can create
  as many entries as it sends values).
- **Replacement page**: not a sitemap case (a stand-in page is the answer to
  an address, never a row of the sitemap, `model` and `visible` conditions).
- **No request** (the hook is also played from a command): `URL::getInstance()`
  null gives no entry (`urlOf()`, 181-183), and the preload has to be
  guarded the same way; an answer built without a URL must not be cached.
- **Rewriting off**: `preloadRewrittenUrls()` returns at once
  (`URL.php:298-300`), the count falls to the query and the languages.

### Estimate

3 h 30 (single query 45 min; `SitemapCache`, invalidator, boundary and
wiring 45 min; tests with fixtures, cache pitfalls and sabotages 1 h 30; doc,
CHANGELOG, PHPStan and style 30 min).

## Order of the commits

Each commit carries its test and its CHANGELOG line, as the module asks
(`docs/project-instructions`: the entry in the same commit).
1. `test(hors-ticket): share the statement counter of the integration tests`
   (trait, `PageListingCostTest` moved onto it; no change of behaviour).
2. `perf(hors-ticket): read the translation of the pages of a type with the pages`
   (P10, smallest, sets the pattern of the flat-cost test).
3. `perf(hors-ticket): ask whether a page is reachable instead of reading it`
   (P3).
4. `perf(hors-ticket): read the sitemap with one query and keep it in a cache`
   (P1; the largest, and the one that needs P4).
Each is shippable alone. The three touch disjoint files except
`CHANGELOG.md` and the new trait; commits 2 to 4 can be written in parallel
by agents in separate worktrees and merged in this order.

## CHANGELOG

Under `[Unreleased]`, in `### Changed` (or `### Fixed` if the dev counts a
slow page as a defect):
- P10: `**A list of the pages of a type reads their titles in one query.** A list of recipes asked the database for the translation of each page one at a time; the cost no longer grows with the number of pages.`
- P3: `**The checks that a page is online no longer read the page.** The language switcher, the hreflang tags, the menus, the home page and the privacy link of a form only needed to know the page could be opened; they skipped reading its content, its image and its tag.`
- P1: `**The sitemap is read in a few queries and kept.** It asked the database for each page, each of its languages and each address; it now reads them together and keeps the answer until a page is published, hidden, renamed or moved, or until a scheduled publication date passes.`

## Decided (2026-11-03)

These choices are settled; do not reopen them.

1. **P1, cache key:** the language and the served host (a hash of it), one
   entry for each.
2. **P1, lifetime:** `min(cache_ttl, next publish_at or unpublish_at)`, so a
   scheduled page shows and goes on time without an event.
3. **P1, what is cached:** the rendered XML, not the rows.
4. **P10, scope:** `ofType()` and `withTag()` both join the translation, and
   `ofType()` preloads the addresses (`URL::preloadRewrittenUrls`) so the cost
   of a listing stays flat.
5. **P3:** no per-request memo of `isReachable()`.

## Open questions, settled

- Counts: measured, see "Measured". The sitemap had a fixed part of 7
  statements, not 3; the slope was the one predicted.
- `joinWithI18n` does fill the translations of the pages (P10: the cost went
  flat). The measures clear the instance pools rather than disable pooling.
- Sitemap and `published_html`: **aligned on `isReachable()`** (decided
  2026-11-03): a page with a date and no HTML is no longer listed.
- Sitemap alternates: **active languages only** (decided 2026-11-03), in the
  P1 commit.
- `docs/developing.md` says that a test writing pages without the writer
  empties `SitemapCache`.
- A `lang` that is a code but no active language is computed on a miss and
  stored already expired, rather than checked before the key: reading the
  languages first would cost a statement on every hit.

## The same pattern elsewhere

Patterns tried on `origin/main` (tree extracted without `Tests/` and
`Model/`): `->find(` on `PublishedPageRepository` (`git grep -E
'(pages|repository|published|publishedPages)\w*->(find|isReachable)\('`);
`PublishedPageRepository` (every injection); a script walking each PHP file
for `Query::create(`, `->findPk(`, `->findOne(`, `->count()`, `->retrieve(`,
`->getUrl(`, `->getTitle(` inside a `foreach`/`for`/`while` or an `array_map`
closure. The script's zero was seen to fall on a known case (it lists
`PublishedPageRepository.php:218`, `Sitemap/SitemapThemeHook.php:137`).

**`find()` called only to test existence.** Only the four callers above.
The three others (`CmsPageViewListener:76`, `NotFoundPageListener:80`,
`MaintenanceListener:124`) render the page. Nothing else
(`grep -n 'null === \$this->pages->find'`: the four).

**A query per row in a front-office loop**, besides P1 and P10:
- `Page/PublishedPageRepository.php:149-150` (`withTag()`): `getTitle()` and
  `getUrl()` per page of the tag; and 218 / 239 (`ofType()`): `getUrl()`,
  `imageOf()` (405: one library image query and its translation per page with
  an image) per returned card. `preloadRewrittenUrls()` covers the
  addresses; the images would need one `IN` on `LibraryImageQuery`
  (`Media/CmsMediaLibrary.php:293` already does that for the media screen).
- `Seo/CmsPageSeoElement.php:136-146`: the breadcrumb climbs the parents one
  `findLocalizedPage()` and one `isReachable()` per level (up to 3 statements
  per ancestor); bounded by the depth of the tree, not by a list. Signalled.
- `Generation/Tool/SearchPagesTool.php:81-90`: `isReachable()` and `getUrl()`
  per candidate, up to `LIMIT`; back-office tool, not the front.
- `Menu/MenuTargetResolver.php:62-90` and 92-162: one pk read plus one
  translation per entry; behind `MenuCache`, so paid on a miss only.
  Signalled, not worth a change.
- Back-office or command only (not the front): `Dashboard/ShowcaseStats.php:104-116`
  (one `count()` per language), `Form/Admin/CmsFormRepository.php:50-51`
  (two `count()` per form), `Page/SampleTextPageFinder.php:175`,
  `Page/Admin/PageListPresenter.php:155`, `ImportExport/SiteExporter.php:132,157`.
  Listed, left out.
- Already fixed, to keep as the model: `Page/Admin/CmsPageAdminRepository.php`
  (joins, `PageListingCostTest`).

Not looked at: the Twig templates of the front (`templates/front`) for a
call that hides a query (`cms_page_alternates`, `cms_pages_of_type` are
the two entries, `Twig/CmsExtension.php:79,172`), and the themes of the
host.

## Estimate

| Item | Time |
|---|---|
| Commit 0, shared counter | 0 h 15 |
| P10 (with preload of the addresses and `withTag()`) | 1 h 45 |
| P3 | 1 h 30 |
| P1 | 3 h 30 |
| **Total** | **7 h 00** |

6 h 30 if P10 keeps to the join alone (1 h 15 instead of 1 h 45, and the
test asserts the slope rather than a flat count); the cost of an agent
brief per commit is inside the figures. The measured counts may move each
test by 15 min, in either direction.

## Measured

Statements on the test host, cold start (pools and URL memoizer cleared).

| Read | Before (3 / 12 pages) | After |
|---|---|---|
| `ofType()` | 8 / 26 | flat |
| `withTag()` | 9 / 27 | flat |
| A check that a page is online, page with image, sharing image and tag | 5 to 6 more than a bare page | same as a bare page |
| Sitemap, two languages | 16 / 43 | 5 / 5, 0 on a hit |

Left out, signalled: `imageOf()` in the lists (one image query per card that
has one); a site of more than 500 pages and rewriting off were not measured.

