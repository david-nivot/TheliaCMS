/*
 * What a preview of the Thelia CMS back office shows, and at which width:
 * the whole page or the content alone, a choice kept from one visit to the
 * next, and the width of a breakpoint of the theme, kept for the session like
 * in its toolkit. The content screen, the page of a component and the page of
 * a candidate share it, and so the choices.
 *
 * The markup is content/_preview_choices.html.twig: radios
 * `[data-cms-preview-modes] input` that carry the address of each reading in
 * `data-url`, a select `[data-cms-preview-width]`, and the frame
 * `[data-cms-frame]` in the same root.
 *
 * `CmsPreviewChoices.apply(root)` puts the stored choices on one copy of the
 * markup, before it is shown, so that its frame loads once at the right
 * address. `CmsPreviewChoices.bind(root, { onMode })` follows the controls
 * and applies; `onMode(input)`, when given, loads the frame for the reading
 * chosen instead of this script (the page of a component posts the values
 * typed into it).
 */
(function () {
    "use strict";

    var MODE_KEY = "theliacms:preview-mode";
    var WIDTH_KEY = "theliacms:preview-width";

    function stored(storage, key) {
        try {
            return window[storage].getItem(key);
        } catch (error) {
            return null;
        }
    }

    function store(storage, key, value) {
        try {
            if (value) {
                window[storage].setItem(key, value);
            } else {
                window[storage].removeItem(key);
            }
        } catch (error) {
            // A browser that keeps nothing shows the defaults next time.
        }
    }

    function setWidth(root) {
        var frame = root.querySelector("[data-cms-frame]");
        var select = root.querySelector("[data-cms-preview-width]");
        var preview = root.querySelector(".cms-content__preview");
        var width = select ? select.value : "";

        if (frame) {
            frame.style.width = width ? width + "px" : "";
        }

        if (preview) {
            preview.classList.toggle("cms-content__preview--sized", width !== "");
        }
    }

    /**
     * The element of `list` whose value is `value`, compared as text: a value
     * kept in the browser never becomes a selector, which one written by hand
     * or by another page of the site could break.
     */
    function withValue(list, value) {
        return value ? Array.prototype.find.call(list, function (element) { return element.value === value; }) || null : null;
    }

    /** The reading the stored choice names, or the one checked in the markup. */
    function checkedMode(root) {
        var choice = withValue(root.querySelectorAll("[data-cms-preview-modes] input"), stored("localStorage", MODE_KEY));

        return choice || root.querySelector("[data-cms-preview-modes] input:checked");
    }

    function apply(root) {
        var frame = root.querySelector("[data-cms-frame]");
        var link = root.querySelector("[data-cms-preview-link]");
        var checked = checkedMode(root);

        if (checked) {
            checked.checked = true;

            if (frame && frame.getAttribute("src") !== checked.getAttribute("data-url")) {
                frame.setAttribute("src", checked.getAttribute("data-url"));
            }

            if (link) {
                link.setAttribute("href", checked.getAttribute("data-url"));
            }
        }

        var select = root.querySelector("[data-cms-preview-width]");
        var width = stored("sessionStorage", WIDTH_KEY);

        if (select && withValue(select.options, width)) {
            select.value = width;
        }

        setWidth(root);
    }

    function bind(root, options) {
        var onMode = options && typeof options.onMode === "function" ? options.onMode : null;

        root.querySelectorAll("[data-cms-preview-modes] input").forEach(function (input) {
            input.addEventListener("change", function () {
                store("localStorage", MODE_KEY, input.value);

                if (onMode) {
                    onMode(input);
                } else {
                    apply(root);
                }
            });
        });

        var select = root.querySelector("[data-cms-preview-width]");

        if (select) {
            select.addEventListener("change", function () {
                store("sessionStorage", WIDTH_KEY, select.value);
                setWidth(root);
            });
        }

        apply(root);
    }

    window.CmsPreviewChoices = { apply: apply, bind: bind };
})();
