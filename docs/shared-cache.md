# Putting a shared cache in front of the site

The module writes everything a proxy needs to cache the pages of the site and to
drop the right ones when they change. It does not talk to a proxy itself, and
this page explains what is missing between the two.

## Why nothing is cached out of the box

Thelia opens a session on every front-office request and sets `PHPSESSID` on the
way out, on plain anonymous requests included. A response that sets a cookie
must never be marked `public`: a proxy that stored one would serve the session
of the first visitor to everybody after them.

So the module marks a page `public` only when the response carries no cookie of
its own, which on a stock Thelia never happens. Setting `http_cache_ttl` in the
settings changes nothing until the proxy is configured to drop that cookie on
the addresses it is allowed to cache. That configuration is below.

Two headers are written in every case, cached or not:

```
Cache-Tag: cms,cms-menu,cms-page-12,cms-partial-cms-page-list
Surrogate-Key: cms cms-menu cms-page-12 cms-partial-cms-page-list
```

The two say the same thing under the two names proxies read: commas for
Cloudflare and Akamai, spaces for Fastly and for the Varnish `xkey` module.
`cms` and `cms-menu` are on every page, `cms-page-12` names the page, and a
`cms-partial-<name>` tag is added for each dynamic block the page shows (a list
of pages, a form), whether it sits in the content or is read by the template.

No `Vary` header is written: the language of a page comes from its address, its
domain or its `lang` parameter, which a cache keys on already, never from
`Accept-Language`.

## Scheduled pages and maintenance

A page with a `publish_at` or an `unpublish_at` changes the site when that date
passes, and nothing is written then, so nothing is purged. Menus, lists and
breadcrumbs show only the pages online: any page may change on the date of any
other. So `s-maxage` never runs past the next `publish_at` or `unpublish_at` of
a visible page of the site, and a page is not shared at all when less than a
second is left. The next date is read once and kept in `cache.app` until it
passes or a page is written. A proxy that ignores `s-maxage` keeps the page
until it is purged.

While the site is closed for maintenance, every answer that gets through (an
address on the allow list, a signed-in administrator, a page of the shop) is
`private, no-store`: shared, the real site would be served to everybody in
place of the closed one.

## With the shared cache of Thelia

From the Thelia release that brings the shared cache of the public pages, the
shop setting `shared_cache_mode` takes over. While it is `announce` or
`enabled`, the core decides whether a page of the module may be kept, removes
the cookies and writes `Cache-Control` and its own tag header (`xkey` or
`Surrogate-Key`, depending on `SHARED_CACHE_PURGER`), and the module only adds
its tags to that header: `cms` and `cms-menu` on every page, `cms-page-12` on
the page it renders, `cms-partial-<name>` on a page that shows a dynamic block.
It writes neither `Cache-Tag` nor its own `Cache-Control`
then, and `http_cache_ttl` is not read: the lifetime is `shared_cache_ttl`,
lowered to the next scheduled date of a page (on `s-maxage` and on the
`Surrogate-Control` the core announces), and nothing is shared while the site
is closed for maintenance, as above.

Every purge of the module goes to the proxy the core is configured for, so no
`CachePurgerInterface` has to be written for it. One configured anyway keeps
receiving the same tags.

## Varnish

The part that matters is the first block: on the addresses of the public site,
the request loses its session cookie on the way in and the response loses its
`Set-Cookie` on the way out. Everything else is a normal Thelia setup.

Adjust the list of public paths to the site. Anything an editor or a customer
signs into stays out of it: the back office, the account pages, the cart, the
checkout. So does any address that answers differently to two visitors.

```vcl
vcl 4.1;

import xkey;   # tag-based invalidation; drop it and use a ban if unavailable

backend default {
    .host = "127.0.0.1";
    .port = "8080";
}

acl purgers {
    "localhost";
    # the address the site purges from
}

sub is_public_page {
    # Addresses served the same way to everybody. Everything else keeps its
    # session and is never cached.
    set req.http.X-Public = "0";

    if (req.method == "GET" || req.method == "HEAD") {
        if (req.url !~ "^/(admin|account|cart|order|checkout|login|logout|register|_)") {
            set req.http.X-Public = "1";
        }
    }
}

sub vcl_recv {
    call is_public_page;

    if (req.method == "PURGE") {
        if (!client.ip ~ purgers) {
            return (synth(405));
        }
        if (req.http.Surrogate-Key) {
            set req.http.n-purged = xkey.purge(req.http.Surrogate-Key);
            return (synth(200, "purged " + req.http.n-purged));
        }
        return (purge);
    }

    if (req.http.X-Public == "1") {
        # The session cookie the backend sets on every response would make the
        # page uncacheable, and it means nothing to a visitor who has not
        # signed in.
        unset req.http.Cookie;
    } else {
        return (pass);
    }
}

sub vcl_backend_response {
    if (bereq.http.X-Public == "1" && beresp.http.Surrogate-Key) {
        unset beresp.http.Set-Cookie;
        set beresp.http.X-Cacheable = "yes";
    }
}

sub vcl_deliver {
    # Cache plumbing is nobody's business but ours.
    unset resp.http.Surrogate-Key;
    unset resp.http.Cache-Tag;
}
```

Without `xkey`, the same invalidation is a ban on the tag header:

```vcl
if (req.method == "BAN") {
    if (!client.ip ~ purgers) {
        return (synth(405));
    }
    ban("obj.http.Cache-Tag ~ " + req.http.X-Cache-Tag);
    return (synth(200, "banned"));
}
```

## Fastly and Cloudflare

Both read `Surrogate-Key` as it is written, and both purge by key through their
API. Nothing is needed in the VCL beyond stripping the session cookie on public
addresses, which Fastly does in `vcl_recv` exactly as above.

## Telling the proxy what to drop

The module works out which tags are stale, and drops no more than what changed:

- a page published again, or whose settings or fields were saved: `cms-page-N`,
  and the lists of pages;
- a page that goes online or offline (published for the first time in a
  language, unpublished, hidden, shown, binned, restored), renamed, or given a
  new address: the page and the pages under it, whose breadcrumb names it, the
  lists of pages, and `cms-menu` only when a menu links to one of them;
- a new home page: the former one, whose tag the root of the site was filed
  under, and the new one;
- a page moved among its siblings: the lists of pages;
- a tag renamed or deleted: the pages that carry it, and the lists of pages;
- a content, a folder, a category or a product written by the shop:
  `cms-menu`, only when a menu links to it;
- an import: `cms`, so every page;
- a menu: `cms-menu`, so every page; the site settings: `cms`, so every page.

`cms` and `cms-menu` are never purged for a single page: every page of the site
carries them, and purging them would empty the whole cache, the shop included.
It hands them to every service tagged `thelia_cms.cache_purger`, and ships none
of its own. What a purge means depends on the proxy, on its address, on its
authentication and on whether it invalidates by key or by ban, and a wrong guess
is worse than doing nothing at all.

Writing one is a few lines. `docs/examples/VarnishCachePurger.php.example` is a
complete one; copy it into a project, rename it to `.php`, and the container
picks it up through the tag on the interface.

A template file is not something the module sees change. A theme that adds
`cmspage-{type}.html.twig` changes the pages of that type at the next
expiry of `http_cache_ttl`, unless the deployment purges the site.

A purger that throws is logged and skipped: a CDN that cannot be reached must
not turn publishing a page into an error the editor has to understand.

Projects already running [FOSHttpCache](https://foshttpcache.readthedocs.io)
have proxy clients for Varnish, Fastly, Cloudflare and Nginx. An implementation
of `CachePurgerInterface` forwarding to one of those is three lines, and it is
the better road: that library has years of production behind it, and a second
client written here would have none.

## The sitemap, kept by the module

Apart from the proxy, the module keeps one thing itself: the XML its hook adds to
the sitemap of the theme, in the cache pool of the site (`cache.app`), one entry
for each language asked and each host served. It lives for `cache_ttl` at most,
and ends sooner on the next `publish_at` or `unpublish_at` of a visible page, so
a scheduled page shows and goes on time without anyone saving anything. Every
change that empties the lists of pages empties it too (publishing, unpublishing,
hiding, binning, renaming, a new address, the `noindex` of a page, an import);
`php Thelia cache:pool:clear cache.app` does it by hand. Run from a command, with
no request to build addresses from, the hook gives nothing and keeps nothing.
