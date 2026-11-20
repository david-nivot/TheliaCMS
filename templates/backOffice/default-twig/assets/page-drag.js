/*
 * Lets a page of the list be dragged by its handle and dropped on another one:
 * on the upper half of a row it goes just before that page, on the lower half
 * just after it, on the right third inside it, first of its pages. A row whose
 * branch is open has its first page right under it, so a drop below it goes
 * inside too, where the line is drawn.
 *
 * Progressive enhancement only: dragging is not operable with a keyboard, so the
 * arrows of each row and the parent page of the settings of a page do the same
 * thing without it. The server owns the tree: once the drop is sent, the list is
 * loaded again from the address it answers, which opens the branch the page went
 * into, rather than this script guessing what it made of the move.
 *
 * Listeners sit on the document: page-tree.js replaces the card of the tree when
 * a branch is opened in place, rows and all.
 */
(function () {
    'use strict';

    var LIST = '[data-cms-page-place]';
    var ROW = 'tr[data-row-id]';
    var MARKS = ['is-drop-before', 'is-drop-after', 'is-drop-inside'];

    var dragged = null;
    var where = null;

    function backOfficeToken() {
        var meta = document.querySelector('meta[name="bo-token"]');

        return meta ? meta.getAttribute('content') || '' : '';
    }

    function clearMarks(list) {
        if (!list) {
            return;
        }

        list.querySelectorAll(ROW).forEach(function (row) {
            MARKS.forEach(function (mark) {
                row.classList.remove(mark);
            });
        });
    }

    /*
     * Whether the row is the dragged page or one of the pages under it, as far
     * as the list shows them: a page cannot go inside its own branch. The server
     * refuses it anyway; this only avoids offering the drop.
     */
    function isInBranchOf(row, page) {
        var list = row.closest(LIST);
        var id = row.dataset.rowId;
        var seen = {};

        while (id && id !== '0' && !seen[id]) {
            if (id === page.dataset.rowId) {
                return true;
            }

            seen[id] = true;
            var above = list.querySelector('tr[data-row-id="' + id + '"]');
            id = above ? above.dataset.parent : null;
        }

        return false;
    }

    function isOpen(row) {
        var fold = row.querySelector('[data-cms-tree-toggle]');

        return !!fold && fold.getAttribute('aria-expanded') === 'true';
    }

    function placement(row, event) {
        var bounds = row.getBoundingClientRect();

        if (event.clientX - bounds.left > bounds.width * 0.66) {
            return 'inside';
        }

        if (event.clientY - bounds.top < bounds.height / 2) {
            return 'before';
        }

        return isOpen(row) ? 'inside' : 'after';
    }

    function reloadOn(address) {
        var url = new URL(address, window.location.href);

        if (url.pathname + url.search === window.location.pathname + window.location.search) {
            window.location.hash = url.hash;
            window.location.reload();

            return;
        }

        window.location.assign(url.href);
    }

    function send(list, page, target, placementOfTarget) {
        var body = new FormData();
        body.append('target', target.dataset.rowId);
        body.append('where', placementOfTarget);
        // The token of the back office, rendered by its layout: a write is
        // refused without it, and it travels in the body, never in the URL.
        body.append('_token', backOfficeToken());

        var card = list.closest('.card');

        if (card) {
            card.setAttribute('aria-busy', 'true');
        }

        fetch(list.dataset.cmsPagePlace.replace('{id}', page.dataset.rowId), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body,
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return {};
                });
            })
            .then(function (payload) {
                if (payload && payload.error) {
                    window.alert(payload.error);
                }

                if (payload && payload.url) {
                    reloadOn(payload.url);

                    return;
                }

                window.location.reload();
            })
            .catch(function () {
                window.location.reload();
            });
    }

    document.addEventListener('dragstart', function (event) {
        var handle = event.target.closest && event.target.closest('[data-cms-page-drag]');

        if (!handle || !handle.closest(LIST)) {
            return;
        }

        dragged = handle.closest(ROW);
        dragged.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', dragged.dataset.rowId);
        event.dataTransfer.setDragImage(dragged, 16, 16);
    });

    document.addEventListener('dragover', function (event) {
        if (!dragged) {
            return;
        }

        var row = event.target.closest && event.target.closest(ROW);
        var list = dragged.closest(LIST);

        clearMarks(list);
        where = null;

        if (!row || row.closest(LIST) !== list || isInBranchOf(row, dragged)) {
            return;
        }

        where = placement(row, event);
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        row.classList.add('is-drop-' + where);
    });

    document.addEventListener('dragend', function () {
        if (!dragged) {
            return;
        }

        clearMarks(dragged.closest(LIST));
        dragged.classList.remove('is-dragging');
        dragged = null;
        where = null;
    });

    document.addEventListener('drop', function (event) {
        if (!dragged || !where) {
            return;
        }

        var row = event.target.closest && event.target.closest(ROW);
        var list = dragged.closest(LIST);

        if (!row || row.closest(LIST) !== list || isInBranchOf(row, dragged)) {
            return;
        }

        event.preventDefault();
        clearMarks(list);
        send(list, dragged, row, where);
    });
})();
