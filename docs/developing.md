# Working on the module

What a contributor runs into when the module is developed inside a Thelia 3
host rather than installed from Composer. Each point was met once; the cure
is the one that worked.

## Where the clone goes

The clone lives in `local/modules/TheliaCMS` of the host, as the CI lays it
out: a symbolic link to a directory outside the project does not resolve
inside the DDEV container. The host requires the dependencies of the module
directly (`composer.json` of the module says which) and no longer
`thelia/cms-module` itself.

## Changing the schema

`TheliaCMS::update()` plays the files of `Config/update/` whose version is
strictly above the installed one, up to the new one. A change appended to the
file of a version some site already runs never reaches that site: the column
of the writing instructions, first added to `2.2.0.sql`, had to move to
`2.2.1.sql`. Every schema change therefore goes in a new
`Config/update/<version>.sql`, with the same version in `Config/module.xml`,
and in `Config/TheliaMain.sql` for a fresh install. Write the file to be
replayable (an `information_schema` check before an `ALTER`): a fresh install
plays it after `TheliaMain.sql`. `Tests/Integration/Module/PageTypeMigrationTest.php`
shows how to test an update, outside a transaction.

## Caches that outlive a change

- **Propel models** (`var/propel/<env>/model`) are built once and kept while
  `var/propel/<env>/hash` matches. After any change to `Config/schema.xml`,
  remove `var/propel/dev` and `var/propel/test` in the host; `cache:clear`
  alone rebuilds nothing.
- **The test container** (`var/cache/test`) is compiled by the bootstrap of
  the integration tests, not by `cache:clear`. After a change to the
  constructor of a service, a new route, a new controller or a new event
  listener, remove it; the first test recompiles. Switching the clone of the
  host between two branches when one adds a service does the same: the whole
  suite errors before its first assertion until the container is moved aside.
  Without removing anything,
  `env -u DATABASE_NAME php Thelia cache:clear --env=test --no-debug`
  rebuilds the container the tests run (without unsetting the variable, see
  below, the container built under DDEV connects the tests to the
  development database); `--env=test` alone rebuilds only the debug one, and a new listener
  stays unknown to the tests. That container does not check the templates
  either: a screen test keeps rendering the compiled copy of a template changed
  since, so the same command is due after editing one. When the constructor of
  a console command changed, that command fails too: it boots the stale
  container, which cannot build the command. Move `var/cache/test` aside,
  then run it. The same goes for `var/propel/test` after a change to
  `Config/schema.xml`: moved aside, it is rebuilt by the next boot, with the
  database the shell names at that moment.
- **The prompt reads translated words.** The catalogue sheet sent to the
  generator gives the label of each component and prop through the
  translator (`Generation/CatalogSheet.php`): a new or changed key of `I18n/`
  that matches one of them changes the prompt in that language. Keep such a
  change out of a round of the determinism corpus, or measure it as one.
- **Compiled Twig** is invalidated on the mtime of the template. A template
  restored within the same second as it was changed keeps the compiled copy of
  the change: `touch` the template, then remove `var/cache/test`.
- **Rendered partials** are kept in the `cache.app` pool for as long as their
  `cacheTtl()` says (a day for a map). A theme template added or changed
  for a partial shows up when that fragment expires, or after
  `php Thelia cache:pool:clear cache.app`.
- **The next scheduled change** of the site (`ScheduledChanges::next()`) is
  kept in `cache.app` until it passes, up to an hour, and outlives the rolled
  back transaction of a test. A test that dates a page with `save()`, or that
  reads cache headers with a TTL set, calls `ScheduledChanges::forget()` in
  its `setUp()` and `tearDown()`; a write through `CmsPageWriter` forgets it
  already.
- **The Twig cache of the test environment does not reload**: a template
  edited after a test compiled it is still rendered from the compiled copy
  in `var/cache/test/twig`, and a test asserting on the new markup can pass
  on the old one (or miss a Twig error the browser shows). After a change to
  a template, move `var/cache/test/twig` aside before the tests.
- **Module assets** go through `cms_asset()`, never `module_asset()` alone:
  the core copies an asset into
  `public/assets/backOffice/default-twig/TheliaCMS/assets/` the first time a
  page asks for it and, outside debug mode, never again, so a site kept the
  scripts of the version before an update. `cms_asset()` replaces the copy
  whenever it differs from the file of the module, by size or by date. Give
  the template an `assets_version` too, so the browser drops its copy: the
  version is read from the file of the module, and a page loaded between the
  edit and the copy caches the old stylesheet under the new version, which
  the browser keeps until a hard reload.

## Static analysis and code style

- From the module directory, `composer test`, `composer test:integration`,
  `composer stan`, `composer cs` and `composer cs:fix` run the tools of the
  install the module sits in (`../../../vendor/bin`). The commands below are
  the same, from the root of the install.
- PHPStan reads the Thelia install the module sits in, and the Propel models
  `php bin/test-prepare` builds under `var/propel/test` (`var/propel/dev`
  otherwise). From the root of the install:
  `vendor/bin/phpstan analyse -c local/modules/TheliaCMS/phpstan.dist.neon --memory-limit=-1`.
- `phpstan-baseline.neon` lists the errors present when the analysis came in.
  Fixing one means regenerating it (`--generate-baseline=local/modules/TheliaCMS/phpstan-baseline.neon`);
  a new error is fixed, never added to it.
- A file of `TheliaCMS\Content\` names no class of `TheliaCMS\Generation\`
  (ADR 12): `Tests/PHPStan/ContentDoesNotDependOnGenerationRule` is run by
  PHPStan and tested in `Tests/Unit/PHPStan/`.
- The code style is the one of the core. From the module directory, with the
  install's fixer: `../../../vendor/bin/php-cs-fixer fix --dry-run --diff`
  (drop `--dry-run --diff` to apply).

## Writing tests

- The unit suite loads no Propel model: its bootstrap is the install's
  autoloader alone, and the generated `Base` classes live under `var/propel`
  (`new Lang()` fails with `Class "Thelia\Model\Base\Lang" not found`).
  A service meant for unit tests takes plain values (an id rather than a
  `Lang`, for instance, as `Http/Admin/AdminControllerSupport` does); what
  needs a model goes to the integration suite.

- Under DDEV, the web container exports `DATABASE_NAME=db`, which wins over
  `.env.test` when the test caches are built: the integration tests then
  connect to the development database and their guard stops them. Build and
  run them with the variable unset (`env -u DATABASE_NAME vendor/bin/phpunit
  -c local/modules/TheliaCMS/phpunit.integration.xml.dist`), after removing
  `var/propel/test` and `var/cache/test` if they were built with it.

- One integration suite at a time on a host. Two suites run together, or a
  suite stopped halfway, leave rows behind (`Duplicate entry 'admin-40'`,
  `'ROUND-TRIP-REF'`) and the next run fails by the hundred, with no link to
  the code: `php bin/test-prepare` empties the test database.

- `TheliaCMS::setConfigValue($key, null)` stores an empty value; it does not
  put a setting back to "never saved", which a setting with a computed default
  tells apart (`ContentSecurityPolicySettings::hosts()`). A test that needs the
  never-saved state deletes the row with
  `ModuleConfigQuery::create()->deleteConfigValue(TheliaCMS::getModuleId(), $key)`,
  which also drops what the core kept in memory.

- A test that runs outside a transaction (`ALTER TABLE`, `TRUNCATE`) cleans in
  `tearDown()` what it inserted; a run interrupted halfway otherwise leaves
  rows behind for the next one.
- A test that uploads a file writes it where the site keeps its library
  (`local/library/images/`), the test database being separate but not the
  disk: remove what it left behind, or the directory shows files no row
  names.
- The cache pool of the test environment lives outside the transaction that takes
  the rows back. A test that writes pages without the writer (`->save()`) and
  then reads the sitemap must empty `SitemapCache::invalidate()` itself, in
  `setUp()` and after the direct write, as `SitemapThemeHookTest` does; through
  the writer, `PageInvalidator` does it.
- A test that counts statements (`Tests/Integration/CountsStatements.php`)
  calls `forgetWhatWasRead()` before each measure. Propel keeps the objects it
  has read, with their translations, and the core keeps the addresses it has
  resolved: a page made by `createPage()` costs nothing to read again, and a
  query asked once per page does not show.
- The CI runs MySQL 8, the DDEV host MariaDB: a date stored with a fraction of
  a second is rounded by the first and cut by the second, so a test comparing
  it to the second passes on the host and fails half the time in the CI. Build
  such a date without its fraction (`date('Y-m-d H:i:s', …)`).
- The published HTML prints an empty alternative text as a bare `alt`, not
  `alt=""` (the filter hands the rewriter an attribute without a value, and
  `DOMDocument` writes it back so). A test that looks for `alt=""` in a
  published page fails on a correct page: read the attribute through the DOM
  (`PublishAccessibilityTest::testAnImageDescribedInAnotherLanguageOnlyGoesOnlineWithoutATextAndIsReported`).
- A test is seen red once, by breaking the code it covers, before it is kept.
  For a template, that means `touch` and `var/cache/test` removed before and
  after the sabotage (see above).
- The content of a `StreamedResponse` is not in `$this->client->getResponse()`
  (its `getContent()` answers `false`): read
  `$this->client->getInternalResponse()->getContent()`, which holds what the
  callback wrote. The module flushes its event stream only outside the `cli`
  SAPI for the same reason.
- To put a fake in place of a service of the module in a screen test
  (`static::getContainer()->set()`), the service has to be public: a private
  service with one consumer is inlined into it and the replacement reaches
  nobody. `AiGatewayInterface` and `ReadUrlTool` are public for that. A service is
  replaced once per kernel: install a delegating fake in `setUp()` and change
  what it delegates to.
- The test database has no shop address (`store_email` is empty): every mail
  fails on its sender, and `SubmissionMailer` logs the failure rather than
  throw, so a test that expects a mail sees none without an error. Write
  `store_email` in `setUp()`, and read what was sent from the message logger of
  Symfony (`mailer.message_logger_listener`), which sees a mail whether the
  shop sends it at once or through a queue (`FormSubmissionTest`).
- A race between two requests only shows with real processes, each on its own
  connection: one PHP process runs one query at a time. `SenderBurstTest` forks
  with `pcntl`, opens a connection per child from the Propel configuration,
  keeps the inherited one referenced and ends each child with `SIGKILL`, so
  that no destructor closes the socket the parent still uses. It runs outside
  a transaction and is skipped without `pcntl`.
- The translator of Thelia takes its language from the session, not from its
  own locale: `setLocale()` changes nothing. A test that reads a message in
  French sets the language of the session of the test request
  (`FrenchFormMessagesTest`).
- A screen test runs in the default language of the shop, English on the test
  base: a message translated by the test's own translator matches the screen
  and proves nothing. `?lang=fr_FR` on the address of the screen sets the
  language of the back office for the rest of the session
  (`SectionEditScreenTest::testWhatTheDocumentRefusesByHandReadsInTheLanguageOfTheBackOffice`,
  `MediaSheetScreenTest`).
- The theme of the back office prints the help of a form field inside its
  label (`label[for="<id>"] .bo-form-label__help`), not in a `#<id>_help`
  element: a test that looks for the latter reads an empty string
  (`GeneratorSettingsScreenTest::testTheEffortSaysWhichModelsReadIt`).
- The French guillemets of `I18n/fr_FR.php` take a plain space (`« %s »`), not
  a no-break space: an expectation typed with one fails on every message.
- A provider that cuts an answer at the output limit reports that limit as the
  tokens written, and the module reads it to tell which limit cut the answer
  (`GatewayFailures`). A fake cut answer says so too:
  `FakeAiGateway::response(finishReason: AiFinishReason::MaxTokens, outputTokens: …)`.
- The parser of Thelia sets the `app` global of Twig at the first render of
  the process, with the request of that moment. A screen test that renders a
  section through the writer before it asks for a screen leaves `app.request`
  null for the rest of the process: ask for any screen first
  (`SectionEditScreenTest::renderedOnce()`).

### The conversion of a video

The tests of the video conversion (`Tests/Integration/Media/Video`,
`Tests/Unit/Media/Video`) never run ffmpeg: `Tests/Support/FakeProcessExecutor`
answers `-version` and writes the file a conversion or a poster would have
written, at the last argument of the command, so the handler goes through its
real path. The commands themselves are pinned by `FfmpegBinaryTest`. The tests
that store a MOV skip, with their reason, on a host still on a TheliaLibrary
older than 2.7.0, which stores MP4 only (`QuickTimeVideos::libraryStoresThem()`). The real encoder is checked by
hand on a phone video (README, "Videos from a phone").

## Browser checks

`Tests/Browser/section-list-follows-preview.mjs` checks, in a real Chromium,
that the section list of the content screen follows the preview. It is a
standalone Node script, run by hand against a development shop: the module has
no JavaScript tooling, and it does not run in CI.

```bash
NODE_PATH=<dir>/node_modules \
CMS_BASE_URL=http://<shop> CMS_ADMIN_USER=<user> CMS_ADMIN_PASSWORD=<password> CMS_PAGE_ID=<id> \
node Tests/Browser/section-list-follows-preview.mjs
```

- `NODE_PATH` points at a `node_modules` that holds the `playwright` (or
  `playwright-core`) library; nothing is installed by the module.
- `CMS_BASE_URL`, `CMS_ADMIN_USER`, `CMS_ADMIN_PASSWORD` and `CMS_PAGE_ID` are
  required, with no default. The page must have sections whose list is taller
  than the panel, and a preview that scrolls; the script fails with a message
  otherwise.
- `CHROME_PATH` (optional) is the Chromium executable to launch.
- `CMS_EDITOR_SCRIPT` (optional) is the path of a local `content-editor.js`,
  served in place of the one of the shop (`page.route`): use it to check a
  branch the shop does not load.

The window is 1440x620. The script exits with code 1 at the first failed check
and prints one line per check: the mark at opening, the mark and the panel
following a wheel on the preview (one `.is-current`, one `aria-current`, the
marked section visible, no page scroll, no focus move), the locate button (the
panel stays still for 1.2 s, the section is marked), an open edit dialog and a
held drag (the panel does not follow), no change of order after the drag, and
no console error. It reads only: nothing is saved and no form is sent but the
login. The held drag is simulated with dispatched `dragstart` and `dragend`
events, as native drag and drop is not reliable in headless Chromium.

`Tests/Browser/page-actions-stay-in-view.mjs` checks the action bar of the
settings screen of a page and the Publish button of the content screen. Same
shape, same variables (`CMS_PAGE_ID` is a saved page that is not a model, with
a long SEO tab), no `CMS_EDITOR_SCRIPT`. It reads only: it saves and publishes
nothing. Window 1440x620, then 360 wide and 1024 wide. It checks that the bar is
sticky and inside the window on the SEO tab, that tabbing through the fields
never leaves the focused one under the bar, that at 360 px the bar is not
sticky or covers at most a third of the window, that Publish is on the first
line of the actions of the header and not alone at 1440 and 1024, and that the
console is clean. The back office scrolls smoothly: the script waits for the
scroll to settle before it measures. A click on Save and publish is checked
by hand, on a page made for it.

### A sample site to check the page list on

The page list needs a site that is more than a handful of pages: a tree folded
by default (over forty pages), branches four levels deep, subsections of eight
pages and more, tags, images, dates over several years, drafts, hidden and
scheduled pages. `Tests/Browser/sample-site/` makes one on the development
database of a DDEV host: a cycling magazine started in 2021, written in French,
about 150 pages, 6 tags and 20 images, a subsection of 30 pages of the media library, part of it in
English. It is the same on every run (fixed seed). From the root of the host:

```bash
ddev exec php local/modules/TheliaCMS/Tests/Browser/sample-site/seed.php   # once
local/modules/TheliaCMS/Tests/Browser/sample-site/save.sh                  # keeps it
local/modules/TheliaCMS/Tests/Browser/sample-site/restore.sh               # puts it back
```

- Everything lands in `var/theliacms-sample-site/` (another directory as the
  argument of each script): `manifest.json` lists the ids made, `db.sql.gz` is
  the whole development database after the seed, `images.tar.gz` the files of
  its images. `seed.php` refuses to run while `manifest.json` exists.
- `restore.sh` replaces the whole development database, administrators
  included; it keeps the one it replaces next to the save first. After a new
  version of the module, `php Thelia module:refresh`.
- The seed writes through the writers of the module, read by reflection from
  the controllers that hold them (they are private services), then dates each
  page with SQL. A `save()` after that stamps the row with today: anything the
  seed writes on a page goes before the dates.
- Putting back a database from before the seed leaves its image files in
  `local/library/images/` (listed in `images.list`). The ids are reused by the
  next seed: a resized copy left in `public/image-library/<id>` would show the
  previous image.

`Tests/Browser/page-section-list.mjs` checks the list of the pages of one page
on that site: down from the tree by the number of pages of a row, the order,
the breadcrumb, the moves at both ends, Create a page here, a search kept
inside, the links of the pages above a result, the way back to the tree, no
horizontal scroll at 1440 and 1024 px, no console error. Same variables as the
other checks, without `CMS_PAGE_ID`, plus `CMS_LANGUAGE_ID`, the id of French
on the shop (the site is read in its language). It reads only: it moves,
saves and publishes nothing.

`Tests/Browser/page-tree-unfolds-in-place.mjs` checks, on the same site and
with the same variables, that a branch opens and closes without loading the
screen again (a mark left on `window` survives), the row clicked staying at its
place within 2 px and keeping the focus, that the address names the branches
open (a reload shows them), that the button of a page names its pages, that a
branch of 30 pages shows 20 and leads to the list of all of them, that
**Close every branch** works in place too, and
that without JavaScript the link lands on the row by its anchor. Window
1440x620, so that the tree scrolls. It reads only.

## CSS and the security policy

- `Content/Pipeline/CssAllowList.php` keeps of a `style` attribute or of a
  component stylesheet only the functions it lists. A function missing from
  `FUNCTIONS` (a new colour space, `anchor()`) makes the browser lose the whole
  declaration without a word: add it to the list with a test in
  `PublishedContentSanitizerTest`, never loosen the `url()` rule.
- The Content-Security-Policy goes only on a response whose request was marked
  by `ContentSecurityPolicyListener::mark($request)`. A new screen that renders
  a CMS page to visitors marks its request, and gets a case in
  `ContentSecurityPolicyListenerTest`; a page of the shop is never marked.

## Drawing for an administrator on the site

- Both previews of the content screen render through the layout of the theme,
  with the session of the administrator: the draft of a page
  (`cms.page.preview`, `base.html.twig` and its hooks) and the draft of an item,
  printed on the real page of the product or the category (`ItemDraftRequest`,
  `_view` is `product`). Whatever a layout hook draws for an administrator
  (`Front/EditButtonThemeHook.php`) decides on what was served, never on
  `_view` alone: `TheliaCMS::SERVED_PAGE_ATTRIBUTE` for a page,
  `Catalogue/PrintedDocuments` for the published document of an item.
- Something drawn for the one person reading the page sets
  `PageCacheListener::PERSONAL_ATTRIBUTE`: the page is answered
  `private, no-store`, which also keeps the shared cache of the core away.

## Calling the AI layer

- Every call goes through `Thelia\Domain\Ai\AiGatewayInterface`, under one of
  the five usages the generators declare with `#[AsAiUsage]`
  (`theliacms.page_generation`, `theliacms.page_translation`, `theliacms.text`,
  `theliacms.component`, and `theliacms.guardrail_reference` for the judge of
  a file joined to a brief). The
  provider, the key, the model and the budget are the core's: nothing here
  knows a provider SDK, and the options a provider reads travel in
  `providerOptions` (`effort`, read by Anthropic models, ignored by the others).
- The messages an editor reads when the layer refuses a call come from
  `Generation\Ai\GatewayFailures`, translated: never put the text of a provider
  in front of an editor. The cost of a call is the sum of the usage log of the
  core, found by the `log_id` of the response.
- A closure that reads a counter accumulated after its definition takes the
  variable by reference (`use (&$usage)`), never as an arrow function, which
  captures the value at definition.
- The answer is read as a stream (`Generation\Ai\ObservedStream`). An answer
  constrained to a schema may arrive in a burst at the end of the turn: the
  screen shows no text before then.
- A page cited by a brief is read by `ReadUrlTool` on the server, through the
  HTTP client of the core that refuses private addresses; its text goes to the
  model as `UntrustedContent`. Reading is off until the site turns it on.
- A round of the determinism bench (`docs/evaluation/determinism/run-round.sh`)
  calls the model for about half an hour on the host, and each call is paid.
  While it runs, copy no code into the module, clear no cache and run no
  suite: a container rebuilt under it makes the calls in flight fail. Two
  rounds run one after the other, never together: a second generation of a
  page is refused while one runs (`GenerationLog::runningFor()`).
- The grammar of structured outputs has limits the documentation does not
  spell out: a `const` needs a `type`, an enum holds strings only (a numeric
  PHP array key becomes an integer), at most 24 optional properties and 16
  union types, and a total size. With a catalogue of twenty components, only
  the envelope of the document fits (`DocumentSchema::strict()`); the props
  are validated server side and sent back for correction.
