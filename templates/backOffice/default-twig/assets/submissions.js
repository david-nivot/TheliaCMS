/*
 * The selection of the answers of a form: a box to select the answers of the
 * page, how many are selected, and a question before deleting them. The form
 * works without it.
 */
(function () {
    'use strict';

    function start() {
        var form = document.querySelector('form[data-cms-submissions-bulk]');

        if (!form) {
            return;
        }

        var boxes = Array.prototype.slice.call(document.querySelectorAll('input[data-cms-submissions-select]'));
        var all = form.querySelector('[data-cms-submissions-select-all]');
        var count = form.querySelector('[data-cms-submissions-selected]');
        var action = form.querySelector('select[name="action"]');
        var apply = form.querySelector('[data-cms-submissions-bulk-apply]');
        var label = count.textContent;

        function refresh() {
            var selected = boxes.filter(function (box) { return box.checked; }).length;
            all.checked = selected > 0 && selected === boxes.length;
            all.indeterminate = selected > 0 && selected < boxes.length;
            count.textContent = selected > 0 ? label + ' (' + selected + ')' : label;
            apply.disabled = selected === 0 || action.value === '';
        }

        all.hidden = false;
        count.hidden = false;
        all.addEventListener('change', function () {
            boxes.forEach(function (box) { box.checked = all.checked; });
            refresh();
        });
        boxes.forEach(function (box) { box.addEventListener('change', refresh); });
        action.addEventListener('change', refresh);

        form.addEventListener('submit', function (event) {
            if (action.value === 'delete' && !window.confirm(form.getAttribute('data-confirm-delete'))) {
                event.preventDefault();
            }
        });

        refresh();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
