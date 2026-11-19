# 20. A module declares a kind of content

Date: 2026-11-19. Status: accepted. Generalises the header zone (2.14.0), whose
stored page and content screen it keeps as they were.

## Context

The header zone is one hidden page (`cms_page.model = 2`) written on the
content screen of the pages, which gives it a draft, versions, translations and
one publication per language, and drawn by the theme where nothing is a page:
no address, not in the list of pages, the search, the sitemap or the menus.
Everything that was special about it (where the screen links back to, what
publishing says, which cache keys it drops, its refusals) was written for it,
in six files, on `model = 2`.

A shop module that owns editorial content of its own (the size guides of a
shop, written once and shown on each product) needs the same thing, and would
otherwise copy the six places, or make pages nobody must see and filter them
out of every list.

## Decision

**A module declares a kind of content; its contents are hidden pages of that
kind, written on the content screen of the pages.**

- Stored as the zone is: a hidden page (`model = 2`) plus a new column,
  `cms_page.content_kind` (VARCHAR(32), NULL, indexed), the code of the kind.
  The zone is the kind `header`. A page and the model of a type have none.
- Declared by a service that implements `Content/Kind/ContentKind`, tagged by
  autoconfiguration (`thelia_cms.content_kind`). The interface carries what the
  screens needed to know about the zone: where to link back to and how to
  say it, the hint under the publish button, the message once online, the
  refusal to show it as a page, the notes at the top, the keys of the shared
  cache to drop, the name in the activity log, the refusal to delete it. The
  registry (`ContentKinds`) refuses two kinds of one code and a code that is
  not lowercase letters, digits and `_` (1 to 32) the first time something asks
  for a kind (a service of the container is built on first use, and nothing
  warms this one up): a module that declares a kind wrongly fails on the first
  screen or command that reads the kinds, not at compilation.
- Written with the screen, the rights and the routes of the pages
  (`/admin/cms/pages/{id}/content`): the right to change pages
  (`admin.cms.page`) and the right to publish (`admin.cms.page.publish`) where
  they already apply to a page and to the zone. No right, no route and no
  screen is added; the guard of `/admin/cms` is unchanged.
- Offered to modules as `Page/Kind/KindContents`: `create()` (a title per
  language, no address), `find()`, `all()` (the titles of a language in the
  same query), `publishedHtml()`, `moveToBin()` (refused when the kind says so;
  the purge of the bin deletes it later), `binned()`, `restore()` and `adopt()`.
- A page of the site can become a content in place (`adopt()`): same id, same
  texts, drafts, versions, translations and publications. It must be an
  ordinary page out of the bin, with no page under it (the bin included) and
  none the site points at: not the home page, the page shown for a missing
  address or for maintenance, nor the privacy policy of a form. A page that
  holds a published version and is offline on the site (hidden, or outside its
  publication window) is refused too: its version would come out published as
  a content. In one transaction it leaves the tree, loses its addresses and
  their redirections, its lines of the search, its entries of the menus (what
  hung under an entry hangs under its parent) and its publication dates, and
  the caches of the page, the menus and the kind are dropped.
- Carried by the export file (`kind`, format 5). The zone keeps `zone: true`,
  and a file written before the kinds imports it as it did. A content of
  another kind replaces nothing (it has no address to be found by), never takes
  a parent, a position or a visibility from the file, and is not imported when
  no active module declares its kind.

## Consequences

- The zone changes nothing for those who use it: same stored row (the update
  gives it `header`), same screen, same phrases, same log entries.
- A hidden content whose kind is no longer declared (its module is off) stays
  in the database and shows nowhere: one guard, `CmsPageAdminRepository::findLive()`,
  answers none for it, so the content screen, the settings, the documents and
  the components of the pages answer 404; no target is built for it
  (`ContentTargets` refuses), so no command publishes it, and
  `thelia_cms:render-published --all` leaves it out. Declared again, it comes back as it was.
- A content has no settings screen (it goes to its content screen), and the
  list never moves, copies or makes it the home page. It gets no address back
  by the reactivation of the module nor by an import.
- The header zone keeps the state badge it has always had, read from the
  visibility of its page; the other kinds read their content alone (written,
  online, changed since).
- The contents of a kind are not on the screen of the bin of the pages, nor in
  its count: the module that owns them lists them (`binned()`) and brings one
  back (`restore()`). The purge of the bin deletes them with the pages.
- Importing a file again brings a second content of the kind, as it never
  matches an existing one: nothing identifies a content across two sites, and a
  match on the title would replace the wrong one. A module that wants one
  content per key keeps its own key and cleans up after an import. Left as is.
- A kind that is not declared by a module of the site cannot be created,
  found or adopted: the registry names the code in the exception.
- The format of the export file moves to 5: a module on 4 would read a content
  of a kind as an ordinary visible page.
