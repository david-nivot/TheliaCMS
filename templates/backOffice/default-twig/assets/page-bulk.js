/*
 * The selection of the list of pages: a box to select every page of the
 * list, how many are selected, and the tag select shown only for the tag.
 * The form works without it; the bin and the publication are asked again on
 * a screen of their own.
 */
(function () {
    'use strict';

    function start() {
        var form = document.querySelector('form[data-cms-pages-bulk]');

        if (!form) {
            return;
        }

        var boxes = Array.prototype.slice.call(document.querySelectorAll('input[data-cms-pages-select]'));
        var all = document.querySelector('[data-cms-pages-select-all]');
        var count = form.querySelector('[data-cms-pages-selected]');
        var action = form.querySelector('[data-cms-pages-bulk-action]');
        var tag = form.querySelector('[data-cms-pages-bulk-tag]');
        var tagSelect = tag ? tag.querySelector('select') : null;
        var apply = form.querySelector('[data-cms-pages-bulk-apply]');

        function refresh() {
            var selected = boxes.filter(function (box) { return box.checked; }).length;

            if (all) {
                all.checked = selected > 0 && selected === boxes.length;
                all.indeterminate = selected > 0 && selected < boxes.length;
            }

            count.textContent = selected > 0
                ? count.getAttribute('data-some').replace('%count%', String(selected))
                : count.getAttribute('data-none');
            apply.disabled = selected === 0 || action.value === '';

            if (tag) {
                tag.hidden = action.value !== 'tag';
                tagSelect.required = !tag.hidden;
            }
        }

        if (all) {
            all.hidden = false;
            all.addEventListener('change', function () {
                boxes.forEach(function (box) { box.checked = all.checked; });
                refresh();
            });
        }

        count.hidden = false;
        boxes.forEach(function (box) { box.addEventListener('change', refresh); });
        action.addEventListener('change', refresh);

        refresh();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
