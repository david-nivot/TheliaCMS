/*
 * The controls of the page of a component, in the Thelia CMS back office:
 * the props typed in the form draw the frame of the preview, and nothing is
 * kept.
 *
 * The form is posted into the frame, to the signed address of the preview
 * (the whole page or the component alone, as the choice above the frame
 * says, preview-choices.js), a moment after the last keystroke. The behaviours of the fields (the
 * rows of a list, the pickers, the framing of a picture) are the ones of the
 * content screen, bound by section-form.js.
 *
 * Progressive enhancement: without this script the form is there, and its
 * "Show" button posts it into the frame.
 */
(function () {
    "use strict";

    // The quiet time after the last change before the frame is drawn again.
    var DELAY = 400;

    /**
     * Binds the controls of one page, and answers how the frame follows a
     * reading chosen above it (see preview-choices.js), or null without them.
     */
    function bindComponentPreview(root) {
        var form = root.querySelector("form[data-cms-preview-form]");
        var frame = document.querySelector("[data-testid=cms-site-component-frame]");

        if (!form || !frame) {
            return null;
        }

        var labels = {};

        try {
            labels = JSON.parse(root.getAttribute("data-cms-preview-labels") || "{}");
        } catch (error) {
            labels = {};
        }

        var show = form.querySelector("[data-cms-preview-show]");
        // Above the views of the props, out of the fields.
        var reset = document.querySelector("[data-cms-preview-reset]");
        var timer = null;
        // Until a value is typed the frame shows the sample the address draws.
        var typed = false;

        if (window.CmsSectionForm) {
            window.CmsSectionForm.bind(root, { labels: labels });
        }

        // The script draws the frame as the values change: the button is for
        // whoever has no script.
        if (show) {
            show.hidden = true;
        }

        if (reset) {
            reset.hidden = false;
        }

        function address() {
            var checked = document.querySelector("[data-cms-preview-modes] input:checked");

            return checked ? checked.getAttribute("data-url") : form.getAttribute("action");
        }

        // Posted into the frame, which is named for it.
        function draw() {
            window.clearTimeout(timer);
            typed = true;
            form.setAttribute("action", address());
            form.setAttribute("target", frame.getAttribute("name"));
            form.submit();
        }

        function schedule() {
            window.clearTimeout(timer);
            timer = window.setTimeout(draw, DELAY);
        }

        // Every kind of field says it changed: a text as it is typed, a choice,
        // a picture chosen in the library, the editor of a rich text.
        form.addEventListener("input", schedule);
        form.addEventListener("change", schedule);

        // A row added, removed or moved changes the list, and no field.
        if (window.MutationObserver) {
            new MutationObserver(function (mutations) {
                if (mutations.some(function (mutation) {
                    return mutation.target instanceof Element && mutation.target.matches("[data-cms-items-list]");
                })) {
                    schedule();
                }
            }).observe(form, { childList: true, subtree: true });
        }

        // Back to the sample: the page is loaded again, which gives the form
        // back its values and draws the sample in the frame.
        if (reset) {
            reset.addEventListener("click", function () {
                window.location.assign(window.location.href);
            });
        }

        mountRichText(form);

        // The whole page or the component alone: the frame follows at once,
        // with the values typed so far, or with the sample when none was.
        return function (mode) {
            if (typed) {
                draw();
            } else {
                frame.setAttribute("src", mode.getAttribute("data-url"));
            }
        };
    }

    // The textareas of rich text are mounted by the editor of the Tiptap
    // module when it is there; it says each change on the textarea. The ones
    // of a row added later are mounted as they come.
    function mountRichText(form) {
        function mount(textarea) {
            if (textarea.cmsMounted || !window.TiptapEditor || typeof window.TiptapEditor.mount !== "function") {
                return false;
            }

            textarea.cmsMounted = true;
            window.TiptapEditor.mount(textarea);

            return true;
        }

        function mountAll() {
            return Array.prototype.map.call(form.querySelectorAll("textarea[data-cms-html]"), mount).every(Boolean);
        }

        // The script of the editor is deferred like this one: given a moment.
        var tries = 20;

        (function wait() {
            if (!mountAll() && tries-- > 0 && !window.TiptapEditor) {
                window.setTimeout(wait, 250);
            }
        })();

        if (window.MutationObserver) {
            new MutationObserver(function () {
                form.querySelectorAll("textarea[data-cms-html]").forEach(mount);
            }).observe(form, { childList: true, subtree: true });
        }
    }

    // The drawer of the settings opens by its button (`command`) where the
    // browser knows the attribute, by this script elsewhere, as on the
    // content screen.
    function bindDialogs() {
        document.querySelectorAll("[data-cms-dialog-open]").forEach(function (button) {
            var dialog = document.getElementById(button.getAttribute("commandfor"));

            button.addEventListener("click", function (event) {
                event.preventDefault();

                if (dialog && !dialog.open) {
                    dialog.showModal();
                }
            });
        });
    }

    // The fields of the props or their table, one at a time: a choice kept
    // from one visit to the next, like the reading of the preview.
    var VIEW_KEY = "theliacms:component-props-view";

    function bindPropsViews(screen) {
        var views = screen.querySelectorAll("[data-cms-props-views] input");
        var panes = screen.querySelectorAll("[data-cms-props-pane]");

        if (!views.length) {
            return;
        }

        function show(view) {
            var panel = screen.querySelector("[data-cms-props-view]");

            if (panel) {
                panel.setAttribute("data-cms-props-view", view);
            }

            panes.forEach(function (pane) {
                pane.hidden = pane.getAttribute("data-cms-props-pane") !== view;
            });
        }

        var kept = null;

        try {
            kept = window.localStorage.getItem(VIEW_KEY);
        } catch (error) {
            kept = null;
        }

        views.forEach(function (input) {
            if (input.value === kept) {
                input.checked = true;
            }

            input.addEventListener("change", function () {
                try {
                    window.localStorage.setItem(VIEW_KEY, input.value);
                } catch (error) {
                    // The choice is only not remembered.
                }

                show(input.value);
            });
        });

        var checked = screen.querySelector("[data-cms-props-views] input:checked");

        show(checked ? checked.value : "edit");
    }

    // The choices above the frame are bound with or without controls: a
    // component the page cannot type props for is still drawn both ways.
    function start() {
        bindDialogs();
        document.querySelectorAll("[data-cms-component-screen]").forEach(bindPropsViews);

        document.querySelectorAll("[data-cms-component-screen]").forEach(function (screen) {
            var controls = screen.querySelector("[data-cms-component-preview]");
            var onMode = controls ? bindComponentPreview(controls) : null;

            if (window.CmsPreviewChoices) {
                window.CmsPreviewChoices.bind(screen, onMode ? { onMode: onMode } : {});
            }
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", start);
    } else {
        start();
    }
})();
