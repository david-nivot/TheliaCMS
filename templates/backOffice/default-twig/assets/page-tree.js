/*
 * Opens and closes a branch of the tree of pages in place. The chevron of a
 * row and "Close every branch" are links to the list with the branch toggled:
 * without this script the screen is loaded again and lands on the row by its
 * anchor. With it, the list is asked in the background and only the card of
 * the tree is replaced, so the screen does not move: the row clicked stays
 * where it was, keeps the focus, and the address follows, for a reload or a
 * screen opened from a row to find the tree as it is.
 *
 * Anything unexpected (a session expired, a server error, no tree in the
 * answer) falls back to the link itself.
 */
(function () {
    'use strict';

    var TREE = '[data-cms-page-tree]';
    var TOGGLE = '[data-cms-tree-toggle]';

    function sameTarget(link) {
        return link.getAttribute('data-testid');
    }

    function keepTheOpenBranches(fresh) {
        var form = document.querySelector('[data-cms-pages-filters]');
        var given = fresh.querySelector('[data-cms-pages-filters] input[type="hidden"][name="open"]');

        if (!form || !given) {
            return;
        }

        var kept = form.querySelector('input[type="hidden"][name="open"]');

        if (kept) {
            kept.value = given.value;
        } else {
            form.insertBefore(given.cloneNode(), form.firstChild);
        }
    }

    function toggle(link, tree) {
        var url = new URL(link.href, window.location.href);
        var target = sameTarget(link);
        var before = link.getBoundingClientRect().top;

        tree.setAttribute('aria-busy', 'true');

        return fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'text/html' } })
            .then(function (response) {
                // A session that expired answers with the login screen: no tree in it.
                if (!response.ok) {
                    throw new Error('answered ' + response.status);
                }

                return response.text();
            })
            .then(function (html) {
                var answer = new DOMParser().parseFromString(html, 'text/html');
                var fresh = answer.querySelector(TREE);

                if (!fresh) {
                    throw new Error('no tree in the answer');
                }

                tree.replaceWith(fresh);
                keepTheOpenBranches(answer);

                var again = target ? fresh.querySelector('[data-testid="' + target + '"]') : null;

                if (again) {
                    // The rows above it did not change: this only absorbs what did.
                    window.scrollBy(0, again.getBoundingClientRect().top - before);
                    again.focus({ preventScroll: true });
                }

                url.hash = '';
                window.history.replaceState(window.history.state, '', url.toString());
            })
            .catch(function () {
                window.location.assign(link.href);
            });
    }

    document.addEventListener('click', function (event) {
        var link = event.target instanceof Element ? event.target.closest(TOGGLE) : null;

        // A new tab or window asked with a modifier is the browser's business.
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        var tree = link.closest(TREE);

        if (!tree || !window.fetch || !window.DOMParser) {
            return;
        }

        event.preventDefault();

        // One at a time: a second click while the first is on its way does nothing.
        if ('true' !== tree.getAttribute('aria-busy')) {
            toggle(link, tree);
        }
    });
})();
