/*
 * Action bar of the settings screen of a page.
 *
 * Three small things the bar needs and plain HTML does not give:
 * - a second click on a button that saves or publishes is not sent (it would
 *   publish twice and take two revisions);
 * - on the Documents tab, which saves by its own forms, Save and Save and
 *   publish are hidden; Publish and Unpublish still apply to the language;
 * - the bar is sticky from the medium breakpoint up, so the field that has the
 *   focus must not stay under it: the space it takes is added to the scroll
 *   padding of the page.
 *
 * Without the script the bar still works, it only lacks these three.
 * Unsaved input is not tracked here.
 */
(function () {
    'use strict';

    var DOCUMENTS_TAB = '#tab-documents';
    var STICKY_FROM = '(min-width: 768px)';
    // Measured in Chromium: with the height of the bar alone, a field is left
    // with its last pixels under it; this margin keeps it clear.
    var BREATHING_SPACE = 16;

    function start() {
        var bar = document.querySelector('[data-cms-page-save-bar]');

        if (!bar) {
            return;
        }

        var buttons = bar.querySelectorAll('button[type="submit"]');
        var saveActions = bar.querySelectorAll('[data-cms-save-actions]');
        var forms = [];

        buttons.forEach(function (button) {
            var form = button.form;

            if (form && forms.indexOf(form) === -1) {
                forms.push(form);
            }
        });

        function enable() {
            buttons.forEach(function (button) {
                button.disabled = false;
            });
        }

        // After the submit event: a submitter disabled during it is left out of what is sent.
        forms.forEach(function (form) {
            form.addEventListener('submit', function () {
                window.setTimeout(function () {
                    buttons.forEach(function (button) {
                        button.disabled = true;
                    });
                }, 0);
            });
        });

        // Back to the screen from the history: the page comes back as it was left.
        window.addEventListener('pageshow', enable);

        document.addEventListener('shown.bs.tab', function (event) {
            var onDocuments = event.target.getAttribute('data-bs-target') === DOCUMENTS_TAB;

            saveActions.forEach(function (button) {
                button.classList.toggle('d-none', onDocuments);
            });
        });

        var sticky = window.matchMedia(STICKY_FROM);

        function keepFocusClear() {
            var root = document.documentElement;

            if (sticky.matches) {
                root.style.scrollPaddingBottom = (Math.ceil(bar.getBoundingClientRect().height) + BREATHING_SPACE) + 'px';
            } else {
                root.style.removeProperty('scroll-padding-bottom');
            }
        }

        keepFocusClear();

        if (typeof ResizeObserver === 'function') {
            new ResizeObserver(keepFocusClear).observe(bar);
        }

        sticky.addEventListener('change', keepFocusClear);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
