/*
 * Content screen of the Thelia CMS back office.
 *
 * Two jobs, both progressive enhancement: the forms that ask the generator
 * to write are posted in the background, the progress of the call is shown
 * as it runs instead of a blank half minute, and the screen is drawn again
 * in place when the call ends, so the editor keeps their scroll and their
 * open panels; and the notices over the stage can be closed and dismiss
 * themselves.
 *
 * A plain script rather than a Stimulus controller: a module cannot register
 * a controller in the application of the theme.
 */
(function () {
    "use strict";

    var first = document.querySelector("[data-cms-content]");

    if (!first) {
        return;
    }

    var labels = JSON.parse(first.getAttribute("data-cms-content-labels") || "{}");
    var running = false;
    // Set when a section is added: the preview drawn next is scrolled to the
    // last section, the one just added, rather than left at the top.
    var revealLast = false;
    // Set when the screen is drawn again: where the preview stood, so the
    // preview drawn next shows the same place rather than the top of the page.
    var keptPosition = null;

    // The colour of the back office, for what the screen writes into the
    // preview: the theme of the site has its own.
    function accent() {
        return getComputedStyle(document.documentElement).getPropertyValue("--bs-primary").trim() || "#0d6efd";
    }

    /* ---- Notices --------------------------------------------------- */

    function dismiss(toast) {
        clearTimeout(toast.cmsTimer);
        toast.classList.remove("cms-toast--visible");
        toast.addEventListener("transitionend", function () { toast.remove(); }, { once: true });
        setTimeout(function () { toast.remove(); }, 400);
    }

    function arm(toast) {
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

    // The help of a field is a tooltip on the "?" beside its label, shown
    // by the stylesheet while the pointer or the focus is on it; a click
    // holds it open, for a touch screen. Rows a list adds later follow, the
    // handler being on the document.
    document.addEventListener("click", function (event) {
        var toggle = event.target.closest("[data-cms-help-toggle]");

        if (!toggle) {
            return;
        }

        var field = toggle.closest(".cms-content__field");
        var open = !field.hasAttribute("data-cms-help-open");
        field.toggleAttribute("data-cms-help-open", open);
        toggle.setAttribute("aria-expanded", open ? "true" : "false");
    });

    function notify(type, message) {
        var root = document.querySelector("[data-cms-content]");
        var container = root.querySelector(".cms-toasts");

        if (!container) {
            container = document.createElement("div");
            container.className = "cms-toasts";
            root.appendChild(container);
        }

        var toast = document.createElement("div");
        toast.className = "cms-toast cms-toast--" + type;
        toast.setAttribute("role", type === "danger" ? "alert" : "status");
        toast.setAttribute("data-cms-toast-permanent-value", type === "danger" ? "true" : "false");

        var icon = document.createElement("i");
        icon.className = "bi cms-toast__icon " + (type === "danger" ? "bi-exclamation-octagon-fill" : "bi-info-circle-fill");
        icon.setAttribute("aria-hidden", "true");

        var text = document.createElement("p");
        text.className = "cms-toast__message";
        text.textContent = message;

        var close = document.createElement("button");
        close.type = "button";
        close.className = "cms-toast__close";
        close.setAttribute("aria-label", labels.close || "Close");
        close.textContent = "×";

        toast.append(icon, text, close);
        container.appendChild(toast);
        requestAnimationFrame(function () { arm(toast); });
    }

    /**
     * Puts the screen the server answered in place of the one on show, with
     * what the reader had: the panels they opened, the scroll of the side
     * panel. The outcome of the call takes the focus, so it is read out.
     */
    function swap(html, target) {
        var next = new DOMParser().parseFromString(html, "text/html").querySelector("[data-cms-content]");
        var current = document.querySelector("[data-cms-content]");

        if (!next || !current) {
            throw new Error("no screen in the answer");
        }

        // The frame is drawn again with the screen and loads at the top: what
        // it showed is read now, and given back once the new one has loaded.
        // A section just added is shown by revealLastSection() instead.
        keptPosition = revealLast ? null : previewPosition(current, target);

        var opened = [];
        current.querySelectorAll("details[open]").forEach(function (panel) {
            opened.push(panelKey(panel));
        });
        var side = current.querySelector(".cms-content__panel");
        var scrolled = side ? side.scrollTop : 0;

        applyPreviewChoices(next);
        current.replaceWith(next);
        running = false;
        enhance(next);

        next.querySelectorAll("details").forEach(function (panel) {
            if (opened.indexOf(panelKey(panel)) !== -1) {
                panel.open = true;
            }
        });
        side = next.querySelector(".cms-content__panel");

        if (side) {
            side.scrollTop = scrolled;
        }

        var outcome = next.querySelector(".cms-toast");

        if (outcome) {
            outcome.setAttribute("tabindex", "-1");
            outcome.focus();
        }
    }

    function frameDocument(frame) {
        try {
            var inner = frame && frame.contentDocument;

            return inner && inner.body && inner.defaultView ? inner : null;
        } catch (error) {
            return null;
        }
    }

    /**
     * Where the preview of `root` stands: the sections on show, each with its
     * distance to the top of the frame, the one the call is about first, and
     * the scroll of the frame for when none of them is drawn again. A section
     * out of sight is not one to keep in place: the call then keeps what the
     * reader was looking at.
     */
    function previewPosition(root, target) {
        var inner = frameDocument(root.querySelector("[data-cms-frame]"));

        if (!inner) {
            return null;
        }

        var height = inner.defaultView.innerHeight;
        var anchors = [];

        inner.querySelectorAll("[data-cms-section]").forEach(function (section) {
            var box = section.getBoundingClientRect();

            if (box.bottom <= 0 || box.top >= height) {
                return;
            }

            var anchor = { id: section.getAttribute("data-cms-section"), top: box.top };

            if (anchor.id === target) {
                anchors.unshift(anchor);
            } else {
                anchors.push(anchor);
            }
        });

        return { anchors: anchors, scroll: inner.defaultView.scrollY };
    }

    /**
     * Scrolls the preview so that the first section of `position` still drawn
     * stands where it stood: a section changed by hand keeps its top under
     * the eye, whatever its new height, and a section removed hands over to
     * the next one on show.
     *
     * The page of the theme moves once scrolled (a header that shrinks when
     * it sticks, an image that loads): the section is put back in place each
     * time the page changes size for a moment, until the reader scrolls.
     */
    function restorePreviewPosition(frame, position) {
        var inner = frameDocument(frame);

        if (!inner) {
            return;
        }

        var view = inner.defaultView;
        var anchor = null;
        var section = null;

        for (var index = 0; index < position.anchors.length && !section; index++) {
            anchor = position.anchors[index];
            section = inner.querySelector('[data-cms-section="' + CSS.escape(anchor.id) + '"]');
        }

        if (!section) {
            view.scrollTo({ top: position.scroll, behavior: "instant" });

            return;
        }

        function place() {
            view.scrollTo({ top: view.scrollY + section.getBoundingClientRect().top - anchor.top, behavior: "instant" });
        }

        place();

        if (!view.ResizeObserver) {
            return;
        }

        var watcher = new view.ResizeObserver(place);
        var events = ["wheel", "touchstart", "keydown", "mousedown"];

        function stop() {
            watcher.disconnect();
            events.forEach(function (name) { view.removeEventListener(name, stop); });
        }

        watcher.observe(inner.body);
        events.forEach(function (name) { view.addEventListener(name, stop, { passive: true }); });
        view.setTimeout(stop, 1500);
    }

    // A panel is told apart by its section and its rank in it, which a
    // section kept by the call keeps too.
    function panelKey(panel) {
        var item = panel.closest("[data-section]");

        return item
            ? item.getAttribute("data-section") + ":" + Array.prototype.indexOf.call(item.querySelectorAll("details"), panel)
            : "";
    }

    /**
     * Draws the screen again from what the server answers for `url`: the
     * outcome of the call, the new sections, the history. The address bar
     * keeps the address of the screen, so a reload by hand does not show the
     * outcome a second time. When the answer cannot be read, the browser
     * goes there instead, which is what the screen does without its script.
     */
    function refresh(url) {
        fetch(url, {
            credentials: "same-origin",
            headers: { "X-Requested-With": "XMLHttpRequest" }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error(String(response.status));
            }

            return response.text();
        }).then(swap).catch(function () {
            window.location.assign(url);
        });
    }

    /**
     * Reads a stream of server-sent events and calls back for each one.
     * Written by hand: EventSource only sends GET requests, and the call is
     * a POST carrying the brief and the token.
     */
    function readEvents(response, onEvent) {
        var reader = response.body.getReader();
        var decoder = new TextDecoder();
        var buffer = "";

        function handle(chunk) {
            var lines = chunk.split("\n");
            var name = "message";
            var data = "";

            lines.forEach(function (line) {
                if (line.indexOf("event:") === 0) {
                    name = line.slice(6).trim();
                } else if (line.indexOf("data:") === 0) {
                    data += line.slice(5).trim();
                }
            });

            if (data !== "") {
                onEvent(name, JSON.parse(data));
            }
        }

        return reader.read().then(function step(result) {
            if (result.done) {
                if (buffer.trim() !== "") {
                    handle(buffer);
                }

                return;
            }

            buffer += decoder.decode(result.value, { stream: true });
            var parts = buffer.split("\n\n");
            buffer = parts.pop();
            parts.forEach(handle);

            return reader.read().then(step);
        });
    }

    /* ---- What the preview shows, and at which width ------------------ */

    // The draft of a catalogue item reads in the page of the item or alone,
    // a choice kept from one visit to the next; the width is the one of a
    // breakpoint of the theme, kept for the session like in its toolkit.
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

    /**
     * Puts the stored choices on one copy of the screen: called on the copy
     * the server answers before it is shown, so its preview loads once, at
     * the right address.
     */
    function applyPreviewChoices(root) {
        var frame = root.querySelector("[data-cms-frame]");
        var link = root.querySelector("[data-cms-preview-link]");
        var mode = stored("localStorage", MODE_KEY);
        var choice = mode ? root.querySelector('[data-cms-preview-modes] input[value="' + mode.replace(/"/g, "") + '"]') : null;
        var checked = choice || root.querySelector("[data-cms-preview-modes] input:checked");

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

        if (select && width && select.querySelector('option[value="' + width.replace(/"/g, "") + '"]')) {
            select.value = width;
        }

        setWidth(root);
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

    function bindPreviewChoices(root) {
        root.querySelectorAll("[data-cms-preview-modes] input").forEach(function (input) {
            input.addEventListener("change", function () {
                store("localStorage", MODE_KEY, input.value);
                applyPreviewChoices(root);
            });
        });

        var select = root.querySelector("[data-cms-preview-width]");

        if (select) {
            select.addEventListener("change", function () {
                store("sessionStorage", WIDTH_KEY, select.value);
                setWidth(root);
            });
        }

        applyPreviewChoices(root);
    }

    /**
     * Binds the behaviour of the screen to one copy of its markup: the one
     * the page was loaded with, then each one `refresh()` puts in its place.
     */
    // The part of a picture a section keeps, said in words, with the slot the
    // crop button of image-crop.js goes in. `box` tells that script the box the
    // picture fills (see `proportionBox`), or null when there is none to crop.
    function keptSummary(scope, after, fields, box) {
        var summary = document.createElement("div");
        summary.className = "cms-content__kept";
        summary.setAttribute("data-cms-kept", "");
        var text = document.createElement("span");
        text.setAttribute("data-cms-kept-text", "");
        var slot = document.createElement("span");
        slot.setAttribute("data-cms-kept-slot", "");
        slot.cmsCropScope = scope;
        slot.cmsCropBox = box;
        summary.append(text, slot);
        after.after(summary);

        function valueOf(input, fallback) {
            var number = parseInt(input.value, 10);

            return isNaN(number) ? fallback : number;
        }

        function refresh() {
            text.textContent = (labels.kept || "Centre %x% % / %y% %, zoom %zoom% %")
                .replace("%x%", String(valueOf(fields.x, 50)))
                .replace("%y%", String(valueOf(fields.y, 50)))
                .replace("%zoom%", String(valueOf(fields.zoom, 100)));
        }

        scope.addEventListener("input", refresh);
        scope.addEventListener("change", refresh);
        refresh();

        return summary;
    }

    // The width the picture is shown at, for the sharpness the dialog tells:
    // what `blocks.css` gives each width of an Image section.
    var SHOWN_WIDTHS = { narrow: 768, column: 1200, full: 1440 };

    // The box a choice of proportions gives: "16:9" is a box of that shape,
    // "original" one of the proportions of the picture itself.
    function proportionBox(choice, image, shown) {
        var sides = /^(\d+):(\d+)$/.exec(choice || "");

        if (!sides) {
            return { boxWidth: image.width, boxHeight: image.height, shown: shown, ratio: "original" };
        }

        return { boxWidth: parseInt(sides[1], 10), boxHeight: parseInt(sides[2], 10), shown: shown, ratio: choice };
    }

    function checkedValue(radios) {
        var checked = Array.prototype.filter.call(radios, function (radio) { return radio.checked; })[0];

        return checked ? checked.value : "";
    }

    // The precise room of a section: its four fields show while "Precise
    // room" is checked among the room buttons, as the pixels of an image
    // show for "A size in pixels". Hidden, they are not read on save.
    function bindPreciseRoom(root) {
        root.querySelectorAll("[data-cms-precise-room]").forEach(function (fields) {
            var form = fields.closest("form");
            var rooms = form ? form.querySelectorAll('input[type="radio"][name$="[cms_appearance][padding]"]') : [];

            if (fields.hasAttribute("data-cms-precise-room-bound") || !rooms.length) {
                return;
            }

            fields.setAttribute("data-cms-precise-room-bound", "");

            function show() {
                fields.hidden = checkedValue(rooms) !== "precise";
            }

            Array.prototype.forEach.call(rooms, function (radio) {
                radio.addEventListener("change", show);
            });
            show();
        });
    }

    // The size and the framing of an image section. Without this script every
    // field of the form is there and the form works; with it, the pixels show
    // only for "A size in pixels", the proportions are one click, and the kept
    // part (three numbers the form still sends) reads as a sentence. The
    // button that frames the image is image-crop.js, in the slot left for it.
    function bindImageDimensions(root) {
        root.querySelectorAll("form[data-cms-section-form]").forEach(function (form) {
            if (form.hasAttribute("data-cms-dimensions-bound")) {
                return;
            }

            function field(name) {
                return form.querySelector('[name$="[' + name + ']"]');
            }

            function rowOf(element) {
                return element.closest(".cms-content__field") || element.closest(".mb-3") || element.parentElement;
            }

            var widthPixels = field("width_px");
            var heightPixels = field("height_px");
            var focusX = field("focus_x");
            var focusY = field("focus_y");
            var zoom = field("zoom");
            var modes = form.querySelectorAll('input[type="radio"][name$="[width]"]');
            var fits = form.querySelectorAll('input[type="radio"][name$="[fit]"]');
            var proportions = form.querySelectorAll('input[type="radio"][name$="[proportions]"]');

            if (!widthPixels || !heightPixels || !focusX || !focusY || !zoom || !modes.length || !fits.length || !proportions.length) {
                return;
            }

            form.setAttribute("data-cms-dimensions-bound", "");

            var widthRow = rowOf(widthPixels);
            var heightRow = rowOf(heightPixels);
            var fitRow = fits[0].closest("fieldset");
            var proportionsRow = proportions[0].closest("fieldset");

            // The caption and the text of the image each keep a line of their own.
            ["caption", "alt"].forEach(function (name) {
                var input = field(name);

                if (input) {
                    rowOf(input).classList.add("cms-content__field--line");
                }
            });

            // The ratios of a size in pixels: they only fill the height from the width.
            var ratios = document.createElement("div");
            ratios.className = "cms-content__ratios";
            ratios.setAttribute("role", "group");
            ratios.setAttribute("aria-label", labels.ratios || "Proportions");
            ratios.setAttribute("data-cms-ratios", "");

            function setHeight(height) {
                heightPixels.value = String(Math.max(80, Math.min(2000, Math.round(height))));
                heightPixels.dispatchEvent(new Event("input", { bubbles: true }));
                heightPixels.dispatchEvent(new Event("change", { bubbles: true }));
            }

            function ratioButton(text, ratio) {
                var button = document.createElement("button");
                button.type = "button";
                button.className = "btn btn-sm btn-outline-secondary";
                button.textContent = text;
                button.addEventListener("click", function () {
                    var width = parseInt(widthPixels.value, 10);

                    if (!(width > 0)) {
                        widthPixels.focus();

                        return;
                    }

                    var value = ratio();

                    if (value > 0) {
                        setHeight(width / value);
                    }
                });
                ratios.appendChild(button);
            }

            [[16, 9], [4, 3], [3, 2], [1, 1]].forEach(function (pair) {
                ratioButton(pair[0] + ":" + pair[1], function () { return pair[0] / pair[1]; });
            });
            ratioButton(labels.imageRatio || "Ratio of the image", function () {
                // The picture the picker shows: its thumbnail keeps the proportions.
                var picture = form.querySelector("[data-cms-image-field] img:not([hidden])");

                return picture && picture.naturalWidth > 0 ? picture.naturalWidth / picture.naturalHeight : 0;
            });
            heightRow.after(ratios);

            function valueOf(input, fallback) {
                var number = parseInt(input.value, 10);

                return isNaN(number) ? fallback : number;
            }

            function isCustom() {
                return checkedValue(modes) === "custom";
            }

            function fills() {
                return checkedValue(fits) !== "contain";
            }

            // The box the crop dialog works in: with a size in pixels, that box when
            // it has both sides and fills; with a width of the page, the proportions.
            function cropBox(image) {
                if (isCustom()) {
                    var width = valueOf(widthPixels, 0);
                    var height = valueOf(heightPixels, 0);

                    return width > 0 && height > 0 && fills() ? { boxWidth: width, boxHeight: height, shown: width, ratio: null } : null;
                }

                return proportionBox(checkedValue(proportions), image, SHOWN_WIDTHS[checkedValue(modes)] || SHOWN_WIDTHS.narrow);
            }

            // The kept part, said in words; the three fields stay in the form.
            var summary = keptSummary(form, fitRow, { x: focusX, y: focusY, zoom: zoom }, cropBox);

            function show() {
                var custom = isCustom();
                var framed = custom && valueOf(heightPixels, 0) > 0;

                widthRow.hidden = !custom;
                heightRow.hidden = !custom;
                ratios.hidden = !custom;
                proportionsRow.hidden = custom;
                fitRow.hidden = !framed;
                summary.hidden = custom && !(framed && fills());

                [focusX, focusY, zoom].forEach(function (input) {
                    rowOf(input).hidden = true;
                });
            }

            form.addEventListener("input", show);
            form.addEventListener("change", show);
            show();
        });
    }

    // The pictures of the columns: the kept part of each one, set row by row
    // when the proportions are not the band. A row added without reloading gets
    // the same, once `cms-item-added` says it is there.
    function bindColumnsFraming(root) {
        root.querySelectorAll("form[data-cms-section-form]").forEach(function (form) {
            var items = form.querySelector("[data-cms-items]");
            var proportions = form.querySelectorAll('input[type="radio"][name$="[proportions]"]');

            if (form.hasAttribute("data-cms-framing-bound") || !items || !proportions.length || (items.getAttribute("data-prototype") || "").indexOf("[focus_x]") < 0) {
                return;
            }

            form.setAttribute("data-cms-framing-bound", "");
            var rows = items.querySelector("[data-cms-items-list]");

            // A column is a share of the 75 rem of the content, less the gaps of 2 rem.
            function columnWidth() {
                return Math.round((1200 - 32 * (rows.children.length - 1)) / rows.children.length);
            }

            function banded() {
                var choice = checkedValue(proportions);

                return choice === "" || choice === "band";
            }

            function prepare(row) {
                var fx = row.querySelector('[name$="[focus_x]"]');
                var fy = row.querySelector('[name$="[focus_y]"]');
                var zoomed = row.querySelector('[name$="[zoom]"]');
                var picker = row.querySelector("input[data-cms-image-picker]");

                if (row.hasAttribute("data-cms-framing-row") || !fx || !fy || !zoomed || !picker) {
                    return;
                }

                row.setAttribute("data-cms-framing-row", "");

                [fx, fy, zoomed].forEach(function (input) {
                    (input.closest(".cms-content__field") || input.closest(".mb-3") || input.parentElement).hidden = true;
                });

                // Under the image, and under the other ways of giving one when the form folds them.
                var anchor = picker.closest(".cms-content__field") || picker.closest(".mb-3") || picker.parentElement;

                if (anchor.nextElementSibling && anchor.nextElementSibling.matches("details")) {
                    anchor = anchor.nextElementSibling;
                }

                var summary = keptSummary(row, anchor, { x: fx, y: fy, zoom: zoomed }, function (image) {
                    return banded() ? null : proportionBox(checkedValue(proportions), image, columnWidth());
                });
                summary.classList.add("cms-content__kept--row");
                summary.hidden = banded();
                row.cmsKept = summary;
            }

            function show() {
                rows.querySelectorAll(":scope > [data-cms-item]").forEach(function (row) {
                    if (row.cmsKept) {
                        row.cmsKept.hidden = banded();
                    }
                });
            }

            rows.querySelectorAll(":scope > [data-cms-item]").forEach(prepare);
            rows.addEventListener("cms-item-added", function (event) {
                prepare(event.target);
                show();
            });
            form.addEventListener("change", show);
            show();
        });
    }

    // The pictures of a component the module does not draw (one of the theme,
    // one made from a brief): each picture `photo` has its proportions and its
    // kept part in fields named `photo__proportions`, `photo__focus_x`,
    // `photo__focus_y` and `photo__zoom`, at the top of the form or in a row of a
    // list. The three numbers read as a sentence next to the button that sets
    // them. The component keeps its own box: the dialog only knows the shape
    // asked for, and a picture kept elsewhere cannot be cropped.
    function bindPictureFraming(root) {
        // The width the component shows a picture at is not known: the width of the content.
        var SHOWN = 1200;

        function prepare(fx) {
            var name = fx.name;

            if (fx.hasAttribute("data-cms-framing-bound") || name.slice(-"__focus_x]".length) !== "__focus_x]") {
                return;
            }

            var base = name.slice(0, -"__focus_x]".length);
            var scope = fx.closest("[data-cms-item]") || fx.form;
            var fy = scope.querySelector('[name="' + base + '__focus_y]"]');
            var zoomed = scope.querySelector('[name="' + base + '__zoom]"]');
            var radios = scope.querySelectorAll('input[type="radio"][name="' + base + '__proportions]"]');
            var picker = scope.querySelector('input[name="' + base + ']"]');

            if (!fy || !zoomed || !picker) {
                return;
            }

            fx.setAttribute("data-cms-framing-bound", "");

            [fx, fy, zoomed].forEach(function (input) {
                (input.closest(".cms-content__field") || input.closest(".mb-3") || input.parentElement).hidden = true;
            });

            // Under the picture, and under the other ways of giving one when the form folds them.
            var holder = picker.closest(".cms-content__field") || picker.closest(".mb-3") || picker.parentElement;
            var anchor = holder;

            if (anchor.nextElementSibling && anchor.nextElementSibling.matches("details")) {
                anchor = anchor.nextElementSibling;
            }

            // The proportions sit under the picture they shape, before the sentence.
            var shape = radios.length ? radios[0].closest("fieldset") : null;

            if (shape) {
                anchor.after(shape);
                anchor = shape;
            }

            var summary = keptSummary(scope, anchor, { x: fx, y: fy, zoom: zoomed }, function (image) {
                var box = proportionBox(checkedValue(radios), image, SHOWN);

                // Nothing is served for a picture kept elsewhere, and the component prints its address as it is.
                box.libraryOnly = true;

                return box;
            });
            summary.classList.add("cms-content__kept--picture");

            var slot = summary.querySelector("[data-cms-kept-slot]");
            slot.cmsCropField = function () { return holder.querySelector("[data-cms-image-field]"); };
            slot.cmsCropFields = { x: fx, y: fy, zoom: zoomed };
        }

        root.querySelectorAll("form[data-cms-section-form]").forEach(function (form) {
            form.querySelectorAll('input[name$="__focus_x]"]').forEach(prepare);

            if (!form.hasAttribute("data-cms-picture-framing")) {
                form.setAttribute("data-cms-picture-framing", "");
                // A row added without reloading: its prototype carries the fields.
                form.addEventListener("cms-item-added", function (event) {
                    event.target.querySelectorAll('input[name$="__focus_x]"]').forEach(prepare);
                });
            }
        });
    }

    function enhance(root) {
        var progress = root.querySelector("[data-cms-progress]");
        var steps = root.querySelector("[data-cms-progress-steps]");

        root.querySelectorAll(".cms-toast").forEach(arm);
        bindPreviewChoices(root);

        /* ---- Generation ------------------------------------------------ */

        function addStep(message, warning) {
            var item = document.createElement("li");
            item.textContent = message;

            if (warning) {
                item.classList.add("is-warning");
            }

            steps.appendChild(item);
        }

        function setBusy(busy) {
            running = busy;
            root.querySelectorAll("form[data-cms-generate] button[type=submit]").forEach(function (button) {
                button.disabled = busy || button.hasAttribute("data-cms-disabled");
            });
            progress.hidden = !busy;

            if (busy) {
                steps.replaceChildren();
            }
        }

        root.querySelectorAll("form[data-cms-generate] button[type=submit][disabled]").forEach(function (button) {
            button.setAttribute("data-cms-disabled", "");
        });

        function generate(form) {
            setBusy(true);
            var finished = false;

            fetch(form.action, {
                method: "POST",
                body: new FormData(form),
                credentials: "same-origin",
                headers: {
                    "Accept": "text/event-stream",
                    "X-Requested-With": "XMLHttpRequest"
                }
            }).then(function (response) {
                if (!response.ok || (response.headers.get("Content-Type") || "").indexOf("text/event-stream") === -1) {
                    throw new Error(labels.failed.replace("%status%", response.status));
                }

                return readEvents(response, function (name, event) {
                    if (name === "start" || name === "turn") {
                        addStep(event.message, false);
                    } else if (name === "delta") {
                        // The same step, updated: one line that counts up rather
                        // than a list that scrolls.
                        var last = steps.lastElementChild;

                        if (last && last.hasAttribute("data-cms-delta")) {
                            last.textContent = event.message;
                        } else {
                            addStep(event.message, false);
                            steps.lastElementChild.setAttribute("data-cms-delta", "");
                        }
                    } else if (name === "correction" || name === "cut") {
                        addStep(event.message, true);
                    } else if (name === "done") {
                        finished = true;
                        refresh(event.redirect);
                    } else if (name === "error") {
                        finished = true;

                        if (event.redirect) {
                            refresh(event.redirect);
                        } else {
                            setBusy(false);
                            notify("danger", event.message);
                        }
                    }
                });
            }).then(function () {
                if (!finished) {
                    setBusy(false);
                    notify("danger", labels.network);
                }
            }).catch(function (error) {
                setBusy(false);
                notify("danger", error.message || labels.network);
            });
        }

        root.querySelectorAll("form[data-cms-generate]").forEach(function (form) {
            form.addEventListener("submit", function (event) {
                // A brief that replaces a draft holding sections asks first,
                // with or without the script's own sending.
                var warning = form.getAttribute("data-cms-confirm");

                if (warning && !running && !window.confirm(warning)) {
                    event.preventDefault();

                    return;
                }

                if (!window.fetch || !window.ReadableStream || running) {
                    return;
                }

                if (!form.reportValidity()) {
                    event.preventDefault();

                    return;
                }

                event.preventDefault();

                // A section rewritten from its dialog: the dialog gives way to
                // the progress of the generation.
                var dialog = form.closest("dialog");

                if (dialog) {
                    dialog.close();
                }

                generate(form);
            });
        });

        /* ---- The list and the preview ---------------------------------- */

        // Each section of the list is numbered, and so is its rendering in
        // the preview: hovering one shows the other, clicking the label
        // scrolls the preview to it. The preview is a page of the same site
        // in a frame; a frame from elsewhere stays silent.
        var frame = root.querySelector("[data-cms-frame]");
        var items = root.querySelectorAll("[data-cms-section-item]");

        function previewDocument() {
            try {
                var inner = frame && frame.contentDocument;

                return inner && inner.body && inner.readyState !== "loading" ? inner : null;
            } catch (error) {
                return null;
            }
        }

        function previewSection(id) {
            var inner = previewDocument();

            return inner ? inner.querySelector('[data-cms-section="' + id.replace(/"/g, '\\"') + '"]') : null;
        }

        // A section of free text is edited where it stands: a double click
        // mounts the editor of the back office on its text, in the frame,
        // and Save posts the form of the section with what the editor
        // holds, the appearance and the token included. Without the Tiptap
        // module, the text is edited as it is.
        function editableInPlace(item) {
            return item.getAttribute("data-component") === "cms:richtext"
                && item.querySelector("form[data-cms-section-form] textarea[data-cms-html]") !== null;
        }

        // The editor of the back office, loaded once into the frame from the
        // files of the screen; null when the module is not there.
        function withEditor(inner, then) {
            var frameWindow = inner.defaultView;

            if (frameWindow.TiptapEditor) {
                then(frameWindow.TiptapEditor);

                return;
            }

            var script = document.querySelector('script[src*="tiptap-editor.js"]');

            if (!script || inner.cmsEditorFailed) {
                then(null);

                return;
            }

            if (!inner.cmsEditorLoading) {
                inner.cmsEditorLoading = [];
                var link = document.querySelector('link[href*="tiptap-editor.css"]');
                var config = document.getElementById("tiptap-editor-config");

                if (link) {
                    var css = inner.createElement("link");
                    css.rel = "stylesheet";
                    css.href = link.href;
                    inner.head.appendChild(css);
                }

                // The settings of the editor, with a toolbar of plain buttons:
                // its menus need the scripts of the back office, which the
                // frame of the theme does not load.
                var settings = {};

                try {
                    settings = JSON.parse(config ? config.textContent || "{}" : "{}");
                } catch (error) {
                    settings = {};
                }

                settings.toolbarItems = "undo,redo,|,bold,italic,underline,|,bulletlist,orderedlist,|,link,unlink";
                var json = inner.createElement("script");
                json.id = "tiptap-editor-config";
                json.type = "application/json";
                json.textContent = JSON.stringify(settings);
                inner.head.appendChild(json);

                var loader = inner.createElement("script");
                loader.src = script.src;
                loader.onload = function () {
                    var api = frameWindow.TiptapEditor || null;
                    var waiting = inner.cmsEditorLoading || [];
                    inner.cmsEditorLoading = null;
                    waiting.forEach(function (callback) { callback(api); });
                };
                loader.onerror = function () {
                    inner.cmsEditorFailed = true;
                    var waiting = inner.cmsEditorLoading || [];
                    inner.cmsEditorLoading = null;
                    waiting.forEach(function (callback) { callback(null); });
                };
                inner.head.appendChild(loader);
            }

            inner.cmsEditorLoading.push(then);
        }

        function frameButton(inner, className, label) {
            var button = inner.createElement("button");
            button.type = "button";
            button.className = className;
            button.textContent = label;

            return button;
        }

        function editInPlace(item, section) {
            var inner = section.ownerDocument;
            var text = section.matches(".cms-richtext") ? section : section.querySelector(".cms-richtext");

            if (!text || text.querySelector("[data-cms-inline]")) {
                return;
            }

            // The text of the section, the buttons of the preview left out.
            var kept = Array.prototype.filter.call(text.childNodes, function (node) {
                return !(node.nodeType === 1 && node.classList.contains("cms-content-tools"));
            });
            var holder = inner.createElement("div");
            kept.forEach(function (node) { holder.appendChild(node); });
            var html = holder.innerHTML;

            var box = inner.createElement("div");
            box.className = "cms-inline";
            box.setAttribute("data-cms-inline", "");
            box.setAttribute("data-testid", "cms-inline-" + item.getAttribute("data-section"));
            var field = inner.createElement("textarea");
            field.className = "wysiwyg";
            field.value = html;
            box.appendChild(field);
            var bar = inner.createElement("div");
            bar.className = "cms-inline__bar";
            var save = frameButton(inner, "cms-inline__save", labels.save || "Save");
            var cancel = frameButton(inner, "cms-inline__cancel", labels.cancel || "Cancel");
            bar.appendChild(save);
            bar.appendChild(cancel);
            box.appendChild(bar);
            text.insertBefore(box, text.firstChild);
            section.classList.add("cms-section--editing");

            var plain = null;

            function leave() {
                box.remove();
                var tools = text.querySelector(".cms-content-tools");
                kept.forEach(function (node) { text.insertBefore(node, tools); });
                section.classList.remove("cms-section--editing");
            }

            function value() {
                return plain ? plain.innerHTML : field.value;
            }

            function send() {
                var form = item.querySelector("form[data-cms-section-form]");
                var textarea = form.querySelector("textarea[data-cms-html]");
                var data = new FormData(form);
                data.set(textarea.name, value());
                save.disabled = true;

                fetch(form.action, {
                    method: "POST",
                    body: data,
                    credentials: "same-origin",
                    redirect: "follow",
                    headers: { "X-Requested-With": "XMLHttpRequest" }
                }).then(function (response) {
                    if (!response.ok) {
                        throw new Error(String(response.status));
                    }

                    return response.text();
                }).then(function (html) {
                    swap(html, item.getAttribute("data-section"));
                }).catch(function () {
                    save.disabled = false;
                    notify("danger", labels.network);
                });
            }

            cancel.addEventListener("click", function (event) {
                event.preventDefault();
                leave();
            });
            save.addEventListener("click", function (event) {
                event.preventDefault();
                send();
            });
            box.addEventListener("keydown", function (event) {
                if (event.key === "Escape") {
                    event.preventDefault();
                    event.stopPropagation();
                    leave();
                } else if (event.key === "Enter" && (event.ctrlKey || event.metaKey)) {
                    event.preventDefault();
                    send();
                }
            });

            withEditor(inner, function (editor) {
                if (editor && !box.isConnected) {
                    return;
                }

                if (editor) {
                    // The editor keeps the textarea up to date as the text changes.
                    editor.mount(field);
                    var prose = box.querySelector(".ProseMirror");

                    if (prose) {
                        prose.focus();
                    }
                } else {
                    plain = inner.createElement("div");
                    plain.className = "cms-inline__plain";
                    plain.contentEditable = "true";
                    plain.innerHTML = html;
                    box.insertBefore(plain, bar);
                    field.hidden = true;
                    plain.focus();
                }
            });
        }

        function markPreview() {
            var inner = previewDocument();

            if (!inner || inner.getElementById("cms-content-marks")) {
                return;
            }

            var style = inner.createElement("style");
            style.id = "cms-content-marks";
            style.textContent = "[data-cms-section]{position:relative;scroll-margin-top:1rem}" +
                "[data-cms-section][data-cms-rank]::before{content:attr(data-cms-rank);position:absolute;top:0.25rem;left:0.25rem;z-index:10;min-width:1.5rem;padding:0 0.4rem;border-radius:1rem;background:#0d6efd;color:#fff;font:600 0.75rem/1.5rem system-ui,sans-serif;text-align:center;opacity:0;transition:opacity .15s}" +
                "[data-cms-section].cms-section--hover{outline:2px solid #0d6efd;outline-offset:2px}" +
                "[data-cms-section].cms-section--hover::before{opacity:1}" +
                ".cms-content-tools{position:absolute;top:0.25rem;right:0.25rem;z-index:11;display:flex;gap:0.25rem;opacity:0;transition:opacity .15s}" +
                ".cms-content-tools button{padding:0.25rem 0.75rem;border:0;border-radius:0.375rem;font:600 0.8125rem/1.5 system-ui,sans-serif;cursor:pointer}" +
                ".cms-content-edit{background:" + accent() + ";color:#fff}" +
                ".cms-content-delete{background:#fff;color:#b02a37;box-shadow:inset 0 0 0 1px #b02a37}" +
                ".cms-content-delete:hover,.cms-content-delete:focus-visible{background:#b02a37;color:#fff}" +
                "[data-cms-section]:hover>.cms-content-tools,.cms-section--hover>.cms-content-tools,.cms-content-tools:focus-within{opacity:1}" +
                "[data-cms-section]:has(>.cms-content-tools):hover{outline:2px solid " + accent() + ";outline-offset:2px}" +
                ".cms-section--editing>.cms-content-tools{display:none}" +
                ".cms-inline{position:relative;outline:2px solid " + accent() + ";outline-offset:4px;border-radius:.25rem}" +
                ".cms-inline__plain{min-height:3rem;outline:0}" +
                ".cms-inline .tiptap-toolbar{display:flex;flex-wrap:wrap;gap:.25rem;margin-bottom:.5rem}" +
                ".cms-inline .tiptap-toolbar button{padding:.25rem .5rem;border:1px solid #ccc;border-radius:.25rem;background:#fff;color:#1c1f22;font:.8125rem/1.5 system-ui,sans-serif;cursor:pointer}" +
                ".cms-inline .tiptap-toolbar button.is-active{background:#e9ecef}" +
                ".cms-inline .tiptap-toolbar .bi::before{font-style:normal}" +
                ".cms-inline .bi-arrow-counterclockwise::before{content:'\\21B6'}.cms-inline .bi-arrow-clockwise::before{content:'\\21B7'}" +
                ".cms-inline .bi-type-bold::before{content:'B';font-weight:700}.cms-inline .bi-type-italic::before{content:'I';font-style:italic}.cms-inline .bi-type-underline::before{content:'U';text-decoration:underline}" +
                ".cms-inline .bi-list-ul::before{content:'\\2022 \\2261'}.cms-inline .bi-list-ol::before{content:'1.'}" +
                ".cms-inline .bi-link-45deg::before{content:'a';text-decoration:underline}.cms-inline [data-tiptap-action=unlink] .bi::before{content:'\\2298'}" +
                ".cms-inline .tiptap-content{min-height:3rem}" +
                ".cms-inline__bar{display:flex;gap:.5rem;justify-content:flex-end;margin-top:.5rem}" +
                ".cms-inline__bar button{padding:.25rem .75rem;border:1px solid " + accent() + ";border-radius:.375rem;background:#fff;color:" + accent() + ";font:600 .8125rem/1.5 system-ui,sans-serif;cursor:pointer}" +
                ".cms-inline__save{background:" + accent() + " !important;color:#fff !important}";
            inner.head.appendChild(style);

            items.forEach(function (item) {
                var section = previewSection(item.getAttribute("data-section"));
                var rank = item.querySelector(".cms-content__item-rank");

                if (section && rank) {
                    section.setAttribute("data-cms-rank", rank.textContent.trim());
                    section.addEventListener("mouseenter", function () { item.classList.add("is-hover"); });
                    section.addEventListener("mouseleave", function () { item.classList.remove("is-hover"); });

                    // Hovering a section of the preview offers to edit it, which
                    // opens its form in the list, and to delete it, which sends
                    // the remove form of the list, confirmation included.
                    var tools = inner.createElement("div");
                    tools.className = "cms-content-tools";

                    if (item.querySelector("[data-cms-edit]")) {
                        var button = inner.createElement("button");
                        button.type = "button";
                        button.className = "cms-content-edit";
                        button.setAttribute("data-testid", "cms-content-edit-" + item.getAttribute("data-section"));
                        button.textContent = labels.edit || "Edit";
                        button.addEventListener("click", function (event) {
                            event.preventDefault();
                            event.stopPropagation();
                            editSection(item);
                        });
                        tools.appendChild(button);
                    }

                    var remove = item.querySelector("form[data-cms-remove]");

                    if (remove) {
                        var deleteButton = inner.createElement("button");
                        deleteButton.type = "button";
                        deleteButton.className = "cms-content-delete";
                        deleteButton.setAttribute("data-testid", "cms-content-delete-" + item.getAttribute("data-section"));
                        deleteButton.textContent = labels.delete || "Delete";
                        deleteButton.addEventListener("click", function (event) {
                            event.preventDefault();
                            event.stopPropagation();
                            remove.requestSubmit();
                        });
                        tools.appendChild(deleteButton);
                    }

                    if (tools.firstChild) {
                        section.appendChild(tools);
                    }

                    if (editableInPlace(item)) {
                        section.setAttribute("data-cms-inline-editable", "");
                        section.title = labels.inline || "Double-click to edit this text where it stands";
                        section.addEventListener("dblclick", function (event) {
                            if (section.querySelector("[data-cms-inline]") || event.target.closest(".cms-content-tools")) {
                                return;
                            }

                            event.preventDefault();
                            editInPlace(item, section);
                        });
                    }
                }
            });
        }

        // The form of a section opened from the preview: its dialog opens and
        // its first field takes the focus.
        function editSection(item) {
            var dialog = item.querySelector("[data-cms-edit]");

            if (dialog) {
                openEditDialog(dialog);
            }
        }

        // The rich-text editor is mounted when the panel opens, a moment
        // later: the first field is looked for until it shows.
        function focusFirstField(panel, tries) {
            // The inputs of the editor's toolbar are not fields of the section.
            var fields = Array.prototype.filter.call(
                panel.querySelectorAll(".ProseMirror, input:not([type=hidden]), textarea, select"),
                function (field) { return !field.closest(".tiptap-toolbar"); }
            );

            for (var index = 0; index < fields.length; index++) {
                if (fields[index].offsetParent !== null) {
                    fields[index].focus({ preventScroll: true });

                    return;
                }
            }

            if (tries > 0) {
                setTimeout(function () { focusFirstField(panel, tries - 1); }, 100);
            }
        }

        // Adding a section happens where it lands: a button written into the
        // preview right after the last section, in the flow of the page. It
        // only exists in this frame: the page itself, and its preview opened
        // on its own, never carry it.
        var addDialog = root.querySelector("[data-cms-add-dialog]");

        function openAddDialog() {
            if (addDialog && !addDialog.open && !running) {
                addDialog.showModal();
            }
        }

        root.querySelectorAll("[data-cms-add-open]").forEach(function (button) {
            button.addEventListener("click", openAddDialog);
        });

        function addSlot() {
            var inner = previewDocument();
            var sections = inner ? inner.querySelectorAll("[data-cms-section]") : [];

            if (!addDialog || !sections.length || inner.getElementById("cms-content-add-slot")) {
                return;
            }

            var color = accent();
            var style = inner.createElement("style");
            style.textContent = "#cms-content-add-slot{display:flex;justify-content:center;width:min(75rem,100% - 2rem);margin:2rem auto;padding:1.25rem;border:2px dashed " + color + ";border-radius:.5rem;box-sizing:border-box}" +
                "#cms-content-add-slot button{display:inline-flex;align-items:center;gap:.5rem;padding:.5rem 1rem;border:1px solid " + color + ";border-radius:.375rem;background:#fff;color:" + color + ";font:600 .875rem/1.5 system-ui,sans-serif;cursor:pointer}" +
                "#cms-content-add-slot button:hover,#cms-content-add-slot button:focus-visible{background:" + color + ";color:#fff}";
            inner.head.appendChild(style);

            var slot = inner.createElement("div");
            slot.id = "cms-content-add-slot";
            var button = inner.createElement("button");
            button.type = "button";
            button.setAttribute("data-testid", "cms-content-add-slot");
            button.textContent = "+ " + (labels.add || "Add a section");
            button.addEventListener("click", openAddDialog);
            slot.appendChild(button);

            var last = sections[sections.length - 1];
            last.parentNode.insertBefore(slot, last.nextSibling);
        }

        function revealLastSection() {
            var inner = previewDocument();
            var sections = inner ? inner.querySelectorAll("[data-cms-section]") : [];

            if (revealLast && sections.length) {
                revealLast = false;
                sections[sections.length - 1].scrollIntoView({ block: "start" });
            }
        }

        // A component prototyped from this screen is drawn in the frame in
        // place of the draft: it has no section to mark or to add after.
        if (frame && !frame.hasAttribute("data-cms-frame-prototype")) {
            frame.addEventListener("load", markPreview);
            frame.addEventListener("load", addSlot);
            frame.addEventListener("load", revealLastSection);
            frame.addEventListener("load", function () {
                var position = keptPosition;
                keptPosition = null;

                if (position) {
                    restorePreviewPosition(frame, position);
                }
            });
            markPreview();
            addSlot();
        }

        items.forEach(function (item) {
            var id = item.getAttribute("data-section");

            function light(on) {
                var section = previewSection(id);

                if (section) {
                    section.classList.toggle("cms-section--hover", on);
                }
            }

            item.addEventListener("mouseenter", function () { light(true); });
            item.addEventListener("mouseleave", function () { light(false); });
            item.addEventListener("focusin", function () { light(true); });
            item.addEventListener("focusout", function () { light(false); });

            var locate = item.querySelector("[data-cms-locate]");

            if (locate) {
                locate.addEventListener("click", function () {
                    var section = previewSection(id);

                    if (section) {
                        section.scrollIntoView({ behavior: "smooth", block: "start" });
                    }
                });
            }
        });

        /* ---- Reordering by drag and drop -------------------------------- */

        // A section is dragged by its handle only: the whole item draggable
        // would steal the selection of its text and the clicks of its forms.
        // It moves in the list while it is dragged, and its new rank is
        // posted when it is dropped; the arrows stay the way to do it from
        // the keyboard.
        root.querySelectorAll("[data-cms-reorder]").forEach(function (list) {
            var dragged = null;
            var from = -1;

            function items() {
                return Array.prototype.slice.call(list.querySelectorAll(":scope > [data-cms-section-item]"));
            }

            list.querySelectorAll("[data-cms-grip]").forEach(function (grip) {
                var item = grip.closest("[data-cms-section-item]");

                grip.addEventListener("pointerdown", function () { item.draggable = true; });
                grip.addEventListener("pointerup", function () { item.draggable = false; });
            });

            list.addEventListener("dragstart", function (event) {
                var item = event.target.closest && event.target.closest("[data-cms-section-item]");

                if (!item || !item.draggable || running) {
                    event.preventDefault();

                    return;
                }

                dragged = item;
                from = items().indexOf(item);
                event.dataTransfer.effectAllowed = "move";
                event.dataTransfer.setData("text/plain", item.getAttribute("data-section"));
                item.classList.add("cms-content__item--dragged");
            });

            list.addEventListener("dragover", function (event) {
                if (!dragged) {
                    return;
                }

                event.preventDefault();
                var over = event.target.closest && event.target.closest("[data-cms-section-item]");

                if (!over || over === dragged) {
                    return;
                }

                var box = over.getBoundingClientRect();
                list.insertBefore(dragged, event.clientY < box.top + box.height / 2 ? over : over.nextSibling);
            });

            list.addEventListener("drop", function (event) {
                if (dragged) {
                    event.preventDefault();
                }
            });

            list.addEventListener("dragend", function () {
                if (!dragged) {
                    return;
                }

                var item = dragged;
                var to = items().indexOf(item);
                dragged = null;
                item.draggable = false;
                item.classList.remove("cms-content__item--dragged");

                if (to === from) {
                    return;
                }

                var body = new FormData();
                body.append("_token", list.getAttribute("data-cms-reorder-token"));
                body.append("section", item.getAttribute("data-section"));
                body.append("position", String(to));
                running = true;

                fetch(list.getAttribute("data-cms-reorder"), {
                    method: "POST",
                    body: body,
                    credentials: "same-origin",
                    redirect: "follow",
                    headers: { "X-Requested-With": "XMLHttpRequest" }
                }).then(function (response) {
                    if (!response.ok) {
                        throw new Error(String(response.status));
                    }

                    return response.text();
                }).then(function (html) {
                    swap(html, item.getAttribute("data-section"));
                }).catch(function () {
                    window.location.reload();
                });
            });
        });

        /* ---- Editing by hand ------------------------------------------- */

        // A section saved by hand comes back with the screen the server
        // redirects to, drawn in place like the end of a generation. When
        // the answer cannot be read, the form is sent the plain way.
        root.querySelectorAll("form[data-cms-section-form], form[data-cms-remove], form[data-cms-restore], form[data-cms-move], form[data-cms-duplicate], form[data-cms-add], form[data-cms-keep]").forEach(function (form) {
            form.addEventListener("submit", function (event) {
                if (form.hasAttribute("data-cms-remove") && !window.confirm(labels.remove)) {
                    event.preventDefault();

                    return;
                }

                if (form.hasAttribute("data-cms-restore") && !window.confirm(labels.restore)) {
                    event.preventDefault();

                    return;
                }

                if (!window.fetch || running) {
                    return;
                }

                if (!form.reportValidity()) {
                    event.preventDefault();

                    return;
                }

                event.preventDefault();
                var button = form.querySelector("button[type=submit]");

                if (form.hasAttribute("data-cms-add")) {
                    form.closest("dialog").close();
                    revealLast = true;
                }

                if (button) {
                    button.disabled = true;
                }

                fetch(form.action, {
                    method: "POST",
                    body: new FormData(form),
                    credentials: "same-origin",
                    redirect: "follow",
                    headers: { "X-Requested-With": "XMLHttpRequest" }
                }).then(function (response) {
                    if (!response.ok) {
                        throw new Error(String(response.status));
                    }

                    return response.text();
                }).then(function (html) {
                    // The section the form is about, when it is one of the
                    // list: its edit dialog, its buttons to move, duplicate
                    // or remove it.
                    var item = form.closest("[data-cms-section-item]");

                    swap(html, item ? item.getAttribute("data-section") : null);
                }).catch(function () {
                    form.submit();
                });
            });
        });

        // The form of a section by hand opens in its dialog. The button opens
        // it by itself; the script opens it in its place to mount the editor.
        root.querySelectorAll("[data-cms-edit-open]").forEach(function (button) {
            var dialog = root.querySelector("#" + CSS.escape(button.getAttribute("commandfor")));

            button.addEventListener("click", function (event) {
                event.preventDefault();

                if (dialog) {
                    openEditDialog(dialog);
                }
            });
        });

        // The rewrite of a section opens in its dialog too, its text field
        // focused.
        root.querySelectorAll("[data-cms-dialog-open]").forEach(function (button) {
            var dialog = root.querySelector("#" + CSS.escape(button.getAttribute("commandfor")));

            button.addEventListener("click", function (event) {
                event.preventDefault();

                if (dialog && !dialog.open) {
                    dialog.showModal();
                    focusFirstField(dialog, 0);
                }
            });
        });

        // The rich-text editor is mounted when a dialog opens, not twenty times
        // at once; a module that is not there leaves the textarea as it is.
        function openEditDialog(dialog) {
            if (!dialog.open) {
                dialog.showModal();
            }

            if (!dialog.cmsMounted) {
                dialog.cmsMounted = true;

                if (window.TiptapEditor && typeof window.TiptapEditor.mount === "function") {
                    dialog.querySelectorAll("textarea[data-cms-html]").forEach(function (textarea) {
                        window.TiptapEditor.mount(textarea);
                        nameTheEditor(textarea, 10);
                    });
                }
            }

            focusFirstField(dialog, 10);
            adoptOverlays(dialog);
        }

        // Escape closes a dialog of the screen even from inside the rich-text
        // editor, which keeps the key for itself: heard first, on the way
        // down. A window of the editor open in the dialog closes first.
        root.querySelectorAll("dialog.cms-content__edit, dialog.cms-content__add").forEach(function (dialog) {
            dialog.addEventListener("keydown", function (event) {
                if (event.key !== "Escape" || !dialog.open || dialog.querySelector(".modal.show")) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                dialog.close();
            }, true);
        });

        // The editor opens its special characters and its preview in windows
        // it adds to the body, which an open dialog leaves inert behind it:
        // they are moved into the dialog while it is open.
        function adoptOverlays(dialog) {
            function adopt(node) {
                if (node.nodeType === 1 && node.parentNode !== dialog && node.matches(".modal[id^='tiptap-'], .modal-backdrop")) {
                    dialog.appendChild(node);
                }
            }

            document.querySelectorAll(".modal[id^='tiptap-']").forEach(adopt);

            // A dialog drawn over by a save never closes: it is let go of then.
            var observer = new MutationObserver(function (mutations) {
                if (!dialog.isConnected || !dialog.open) {
                    observer.disconnect();

                    return;
                }

                mutations.forEach(function (mutation) {
                    mutation.addedNodes.forEach(adopt);
                });
            });
            observer.observe(document.body, { childList: true });
            dialog.addEventListener("close", function () { observer.disconnect(); }, { once: true });
        }

        // The editor replaces the textarea with an editable area the label of the
        // field no longer points at: it is named after that label once mounted.
        function nameTheEditor(textarea, tries) {
            var label = textarea.labels && textarea.labels[0] ? textarea.labels[0].textContent.trim() : "";
            var holder = textarea.closest(".mb-3") || textarea.parentElement;
            var area = holder ? holder.querySelector(".ProseMirror") : null;

            if (area) {
                if (label && !area.hasAttribute("aria-label")) {
                    area.setAttribute("aria-label", label);
                }

                return;
            }

            if (tries > 0) {
                setTimeout(function () { nameTheEditor(textarea, tries - 1); }, 300);
            }
        }

        // A colour of the appearance is typed #rrggbb, or picked: the picker
        // next to the field writes into it. The field stays what is sent, so
        // emptied it is the colour of the theme, which a picker cannot say.
        root.querySelectorAll("input[data-cms-color]").forEach(function (field) {
            var picker = document.createElement("input");
            picker.type = "color";
            picker.className = "form-control form-control-color";
            // The label holds its help and its "optional" too: the theme sets its name apart.
            var name = field.labels && field.labels[0] ? field.labels[0].querySelector(".bo-form-label__text") || field.labels[0] : null;
            picker.setAttribute("aria-label", name ? name.textContent.trim() : "");
            picker.setAttribute("data-testid", "cms-color-picker");

            var group = document.createElement("div");
            group.className = "input-group";
            field.replaceWith(group);
            group.append(field, picker);

            function fromField() {
                var set = /^#[0-9a-f]{6}$/i.test(field.value.trim());

                if (set) {
                    picker.value = field.value.trim().toLowerCase();
                }

                // No colour yet: the swatch is crossed, not black.
                picker.classList.toggle("is-empty", !set);
            }

            picker.addEventListener("input", function () {
                field.value = picker.value;
                picker.classList.remove("is-empty");
            });
            field.addEventListener("input", fromField);
            fromField();
        });

        // Rows of a list prop, from the prototype of the collection.
        root.querySelectorAll("[data-cms-items]").forEach(function (list) {
            var rows = list.querySelector("[data-cms-items-list]");
            var add = list.querySelector("[data-cms-items-add]");
            var prototype = list.getAttribute("data-prototype");
            var max = parseInt(list.getAttribute("data-cms-items-max"), 10) || 12;
            var min = parseInt(list.getAttribute("data-cms-items-min"), 10) || 0;
            var counter = rows.children.length;

            function refresh() {
                var count = rows.children.length;
                add.hidden = count >= max;
                rows.querySelectorAll(":scope > [data-cms-item] > [data-cms-item-remove]").forEach(function (button) {
                    button.hidden = count <= min;
                });
            }

            add.addEventListener("click", function () {
                var holder = document.createElement("template");
                holder.innerHTML = prototype.replace(/__name__/g, String(counter++)).trim();
                var row = holder.content.firstElementChild;
                rows.appendChild(row);
                refresh();

                // What the form binds on the rows it was loaded with: the picker of an image and, for a
                // list of pictures, the part of them that is kept.
                if (window.CmsMediaPicker) {
                    window.CmsMediaPicker.bind(row);
                }

                row.dispatchEvent(new CustomEvent("cms-item-added", { bubbles: true }));

                if (window.CmsImageCrop) {
                    window.CmsImageCrop.bind(row);
                }

                row.querySelector("input, textarea, select")?.focus();
            });

            rows.addEventListener("click", function (event) {
                var remove = event.target.closest("[data-cms-item-remove]");

                if (remove) {
                    remove.closest("[data-cms-item]").remove();
                    refresh();
                }
            });

            refresh();
        });

        bindImageDimensions(root);
        bindPreciseRoom(root);
        bindColumnsFraming(root);
        bindPictureFraming(root);

        // An image field is drawn by media-picker.js, which a new section
        // form asks for too.
        if (window.CmsMediaPicker) {
            window.CmsMediaPicker.bind(root);
        }

        // The button that frames the image, in the slot of the summary of the
        // kept part, once the picker has drawn the image it frames.
        if (window.CmsImageCrop) {
            window.CmsImageCrop.bind(root);
        }

        // A reference is picked from the list its source answers: a select for a
        // short list, a search box for a long one. The number field, which
        // the form sends, is hidden behind it; it shows again when the list
        // cannot be read.
        root.querySelectorAll("input[data-cms-picker]").forEach(function (input) {
            var source = input.getAttribute("data-cms-picker");
            var searchable = input.hasAttribute("data-cms-picker-searchable");
            var picker = document.createElement(searchable ? "input" : "select");
            picker.className = "form-select";
            picker.setAttribute("aria-label", input.labels && input.labels[0] ? input.labels[0].textContent.trim() : "");
            // The label of the field names the picker, which takes its place.
            picker.id = input.id + "_picker";
            Array.prototype.forEach.call(input.labels || [], function (label) { label.htmlFor = picker.id; });

            function fill(rows) {
                picker.replaceChildren();
                var none = document.createElement("option");
                none.value = "";
                none.textContent = "—";
                picker.appendChild(none);
                rows.forEach(function (row) {
                    var option = document.createElement("option");
                    option.value = String(row.id);
                    option.textContent = row.name;
                    option.selected = String(row.id) === input.value;
                    picker.appendChild(option);
                });
            }

            if (searchable) {
                var datalist = document.createElement("datalist");
                datalist.id = input.id + "_options";
                picker.setAttribute("list", datalist.id);
                picker.className = "form-control";
                picker.placeholder = labels.search || "Search…";
                var found = {};
                picker.addEventListener("input", function () {
                    var chosen = found[picker.value];

                    if (chosen) {
                        input.value = String(chosen);

                        return;
                    }

                    if (picker.value.length < 2) {
                        return;
                    }

                    fetch(source + "?q=" + encodeURIComponent(picker.value), { credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest" } })
                        .then(function (response) { return response.json(); })
                        .then(function (rows) {
                            datalist.replaceChildren();
                            found = {};
                            rows.forEach(function (row) {
                                found[row.name] = row.id;
                                var option = document.createElement("option");
                                option.value = row.name;
                                datalist.appendChild(option);
                            });
                        })
                        .catch(function () {});
                });
                input.insertAdjacentElement("beforebegin", picker);
                input.insertAdjacentElement("beforebegin", datalist);
                input.hidden = true;
                picker.addEventListener("change", function () {
                    if (picker.value.trim() === "") {
                        input.value = "";
                    }
                });

                if (input.value && input.value !== "0") {
                    // Until the name comes back, and if it never does, the
                    // number held is what the field shows.
                    picker.placeholder = "#" + input.value;
                    fetch(source + "?id=" + encodeURIComponent(input.value), { credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest" } })
                        .then(function (response) { return response.json(); })
                        .then(function (row) { if (row && row.name) { picker.value = row.name; found[row.name] = row.id; } })
                        .catch(function () {});
                }
            } else {
                picker.addEventListener("change", function () {
                    input.value = picker.value;
                });
                input.insertAdjacentElement("beforebegin", picker);
                input.hidden = true;
                fetch(source, { credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest" } })
                    .then(function (response) { return response.json(); })
                    .then(fill)
                    .catch(function () { picker.remove(); input.hidden = false; });
            }
        });

    }

    enhance(first);

    window.addEventListener("beforeunload", function (event) {
        if (running) {
            event.preventDefault();
            event.returnValue = labels.leaving;
        }
    });
})();
