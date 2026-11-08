/*
 * The menus of the filter bar of the list of pages. They are `<details>`, so
 * they open and work without this script; it only keeps one open at a time and
 * closes the open one on Escape (focus back on its button) or on a click
 * outside it.
 */
(function () {
    'use strict';

    function start() {
        var menus = Array.prototype.slice.call(document.querySelectorAll('details[data-cms-filter-menu]'));

        if (menus.length === 0) {
            return;
        }

        menus.forEach(function (menu) {
            menu.addEventListener('toggle', function () {
                if (!menu.open) {
                    return;
                }

                menus.forEach(function (other) {
                    if (other !== menu) {
                        other.open = false;
                    }
                });
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            menus.forEach(function (menu) {
                if (menu.open) {
                    menu.open = false;
                    menu.querySelector('summary').focus();
                }
            });
        });

        document.addEventListener('click', function (event) {
            menus.forEach(function (menu) {
                if (menu.open && !menu.contains(event.target)) {
                    menu.open = false;
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
