/*
 * The drawer of the pages behind a number: "used by 3 pages", "carried by 2
 * pages". A button with data-cms-linked-pages opens one dialog for the screen,
 * on the right, and loads into it the list the server renders for the element
 * (data-url). Whatever the screen, the drawer is this one.
 *
 * - The dialog is modal: the focus goes into it on opening (the close button),
 *   stays in it, Escape closes it, and the focus goes back to the button that
 *   opened it, whichever way it was closed.
 * - The list is announced politely once loaded.
 * - Without the script the number stays a plain figure.
 */
(function () {
    'use strict';

    if (window.cmsLinkedPagesReady) {
        return;
    }

    window.cmsLinkedPagesReady = true;

    var dialog = null;
    var heading = null;
    var body = null;
    var opener = null;
    var request = 0;

    function element(tag, className) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        return node;
    }

    function build(trigger) {
        dialog = element('dialog', 'shadow');
        dialog.setAttribute('data-testid', 'cms-linked-pages-drawer');
        dialog.setAttribute('aria-labelledby', 'cms-linked-pages-title');
        // A drawer on the right, the whole height, as wide as a phone is.
        dialog.style.cssText = 'margin:0 0 0 auto;height:100vh;max-height:100vh;width:min(30rem,100vw);max-width:100vw;border:0;padding:0;background:var(--bs-body-bg,#fff);color:var(--bs-body-color,#212529)';

        var head = element('div', 'd-flex align-items-center justify-content-between gap-2 border-bottom px-3 py-2');
        heading = element('h2', 'h6 mb-0');
        heading.id = 'cms-linked-pages-title';

        var close = element('button', 'btn-close');
        close.type = 'button';
        close.setAttribute('aria-label', trigger.getAttribute('data-close') || 'Close');
        close.setAttribute('data-testid', 'cms-linked-pages-close');
        close.addEventListener('click', function () { dialog.close(); });

        head.appendChild(heading);
        head.appendChild(close);

        body = element('div');
        body.setAttribute('role', 'region');
        body.setAttribute('aria-live', 'polite');
        body.setAttribute('aria-labelledby', 'cms-linked-pages-title');

        dialog.appendChild(head);
        dialog.appendChild(body);

        // A click on the backdrop is a click on the dialog itself.
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dialog.close();
            }
        });

        dialog.addEventListener('close', function () {
            request += 1;

            if (opener && document.body.contains(opener)) {
                opener.focus();
            }

            opener = null;
        });

        document.body.appendChild(dialog);
    }

    function open(trigger) {
        if (!dialog) {
            build(trigger);
        }

        opener = trigger;
        heading.textContent = trigger.getAttribute('data-title') || '';
        body.setAttribute('aria-busy', 'true');
        body.textContent = trigger.getAttribute('data-loading') || '';
        body.className = 'p-3 text-body-secondary';

        if (!dialog.open) {
            dialog.showModal();
        }

        var current = ++request;

        fetch(trigger.getAttribute('data-url'), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error(String(response.status));
            }

            return response.text();
        }).then(function (html) {
            if (current !== request) {
                return;
            }

            // Rendered and escaped by the server, from the module's own route.
            body.className = '';
            body.innerHTML = html;
            body.removeAttribute('aria-busy');
        }).catch(function () {
            if (current !== request) {
                return;
            }

            body.className = 'p-3 text-danger';
            body.textContent = trigger.getAttribute('data-failed') || '';
            body.removeAttribute('aria-busy');
        });
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target instanceof Element ? event.target.closest('[data-cms-linked-pages]') : null;

        if (trigger) {
            event.preventDefault();
            open(trigger);
        }
    });
})();
