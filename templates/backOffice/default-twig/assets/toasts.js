/*
 * The notices of the full-window screens of the Thelia CMS back office (the
 * content screen, the page of a component, the page of a version proposed),
 * written by content/_toasts.html.twig: hidden until armed, a confirmation
 * leaves after six seconds unless the pointer or the focus is on it, an error
 * or a notice with details stays until closed.
 *
 * Armed on load; `CmsToasts.arm(toast)` arms one drawn later (the content
 * screen draws itself again), and arming twice does nothing.
 */
(function () {
    "use strict";

    function dismiss(toast) {
        clearTimeout(toast.cmsTimer);
        toast.classList.remove("cms-toast--visible");
        toast.addEventListener("transitionend", function () { toast.remove(); }, { once: true });
        setTimeout(function () { toast.remove(); }, 400);
    }

    function arm(toast) {
        if (toast.cmsArmed) {
            return;
        }

        toast.cmsArmed = true;
        toast.classList.add("cms-toast--visible");

        if (toast.getAttribute("data-cms-toast-permanent-value") === "true") {
            return;
        }

        var resume = function () {
            clearTimeout(toast.cmsTimer);
            toast.cmsTimer = setTimeout(function () { dismiss(toast); }, 6000);
        };
        var pause = function () { clearTimeout(toast.cmsTimer); };

        toast.addEventListener("mouseenter", pause);
        toast.addEventListener("mouseleave", resume);
        toast.addEventListener("focusin", pause);
        toast.addEventListener("focusout", resume);
        resume();
    }

    document.addEventListener("click", function (event) {
        var close = event.target.closest(".cms-toast__close");

        if (close) {
            dismiss(close.closest(".cms-toast"));
        }
    });

    function start() {
        document.querySelectorAll(".cms-toast").forEach(arm);
    }

    window.CmsToasts = { arm: arm, dismiss: dismiss };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", start);
    } else {
        start();
    }
})();
