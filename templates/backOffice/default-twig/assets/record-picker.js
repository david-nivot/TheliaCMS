/*
 * The pickers of a record of the Thelia CMS back office: a page, a product, a
 * category, a content or a folder, chosen among those that exist.
 *
 * The form holds the id in a number field (`input[data-cms-picker="<source>"]`),
 * which is what it sends and what is typed when the script does not run. A
 * short list (no `data-cms-picker-searchable`) becomes a select. A long one
 * becomes a combobox: opened, it lists the first records of the source; typed
 * in, it lists those whose title holds the text; when there are more than it
 * shows, it says so and that typing narrows them.
 *
 * Three parts, so another component can replace the one drawn here (one the
 * back-office theme would offer) without the forms or the server knowing:
 *
 * - the source (`createSource`): what the address answers, `?q=` with the total
 *   in `X-Total-Count`, `?id=` for the record already held;
 * - the component (`createCombobox`): the field, the list, the keyboard, and
 *   nothing about menus or pages;
 * - the binding (`bind`): finds the fields and mounts the component on each,
 *   the one given to `CmsRecordPicker.use(factory)` if any.
 *
 * The script binds the fields of the screen once loaded, and content-editor.js
 * calls `CmsRecordPicker.bind(root)` on every form it enhances, a section added
 * since the page loaded included.
 *
 * Plain script rather than a Stimulus controller: the back-office application is
 * built by the theme and a module cannot register a controller with it.
 */
(function () {
    "use strict";

    var element = document.querySelector("script[data-cms-record-picker-messages]");
    var messages = element ? JSON.parse(element.textContent || "{}") : {};
    var counter = 0;

    function text(key, fallback, values) {
        return (messages[key] || fallback).replace(/%(\w+)%/g, function (match, name) {
            return values && name in values ? String(values[name]) : match;
        });
    }

    // A source may carry its own query already (`?offline=1`): the search
    // is added to it.
    function address(source, name, value) {
        return source + (source.indexOf("?") === -1 ? "?" : "&") + name + "=" + encodeURIComponent(value);
    }

    /* ---- Source ---------------------------------------------------- */

    // What a searched source answers. A new search cancels the one still
    // running: an answer that comes back late never replaces a newer one.
    function createSource(url) {
        var running = null;

        function read(response) {
            if (!response.ok) {
                throw new Error("HTTP " + response.status);
            }

            return response.json();
        }

        return {
            search: function (query) {
                if (running) {
                    running.abort();
                }

                running = new AbortController();

                return fetch(address(url, "q", query), { credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" }, signal: running.signal })
                    .then(function (response) {
                        var total = parseInt(response.headers.get("X-Total-Count") || "", 10);

                        return read(response).then(function (rows) {
                            rows = Array.isArray(rows) ? rows : [];

                            return { items: rows, total: isNaN(total) ? rows.length : total };
                        });
                    });
            },
            // The record held, or null when it is gone.
            find: function (id) {
                return fetch(address(url, "id", id), { credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" } })
                    .then(read)
                    .then(function (rows) {
                        var row = Array.isArray(rows) ? rows[0] : rows;

                        return row && row.name ? row : null;
                    });
            },
        };
    }

    /* ---- Combobox -------------------------------------------------- */

    // The field the editor types in and the list of records floating under
    // it, over the rest of the form. The list is placed from the field on the
    // screen (fixed), in the top layer of the browser where it can be: a card
    // or a dialog of the back office clips what overflows it, and the list
    // must not push the fields below. `input` is the hidden number field.
    function createCombobox(input, source) {
        var listId = "cms-combobox-" + ++counter;
        var wrapper = document.createElement("div");
        wrapper.className = "cms-combobox";

        var field = document.createElement("input");
        field.type = "text";
        field.className = "form-control cms-combobox__field";
        // The id a row of a list reads its title from, and the label points at.
        field.id = input.id + "_picker";
        field.autocomplete = "off";
        field.placeholder = text("search", "Search…");
        field.setAttribute("role", "combobox");
        field.setAttribute("aria-autocomplete", "list");
        field.setAttribute("aria-expanded", "false");
        field.setAttribute("aria-controls", listId);

        if (input.required) {
            field.setAttribute("aria-required", "true");
        }

        if (input.hasAttribute("aria-describedby")) {
            field.setAttribute("aria-describedby", input.getAttribute("aria-describedby"));
        }

        if (input.classList.contains("is-invalid")) {
            field.classList.add("is-invalid");
            field.setAttribute("aria-invalid", "true");
        }

        var panel = document.createElement("div");
        panel.className = "cms-combobox__panel";
        // Above the dialogs and the cards, which clip a list placed in them.
        var layered = typeof panel.showPopover === "function";

        if (layered) {
            panel.setAttribute("popover", "manual");
        } else {
            panel.hidden = true;
        }

        var list = document.createElement("ul");
        list.className = "cms-combobox__list";
        list.id = listId;
        list.setAttribute("role", "listbox");

        var status = document.createElement("p");
        status.className = "cms-combobox__status";
        status.setAttribute("aria-live", "polite");
        status.hidden = true;

        panel.append(list, status);
        wrapper.append(field, panel);
        input.insertAdjacentElement("beforebegin", wrapper);
        input.hidden = true;

        var label = input.labels && input.labels[0] ? input.labels[0].textContent.trim() : "";
        Array.prototype.forEach.call(input.labels || [], function (node) { node.htmlFor = field.id; });
        list.setAttribute("aria-label", label);

        var chosen = "";
        var items = [];
        var active = -1;
        var timer = null;
        var notifying = false;
        var shown = false;
        var rendered = null;
        // The search the list waits for; closing it forgets any still on its
        // way, so an answer that comes back after a choice never reopens it.
        var ticket = 0;
        var justFocused = false;
        // The editor typed in the field since the last choice: only then does
        // an empty field mean "no record" rather than "name not read yet".
        var dirty = false;
        // The record held as the source answered it, its title and path
        // included, for the head of the list.
        var heldRow = null;

        // Under the field, or above it when the screen has more room there;
        // the list scrolls inside what is left.
        function place() {
            var box = field.getBoundingClientRect();
            var gap = 4;
            var margin = 8;
            var below = window.innerHeight - box.bottom - gap - margin;
            var above = box.top - gap - margin;
            var wanted = Math.min(list.scrollHeight + status.offsetHeight + 2, 18 * 16);
            var downward = below >= wanted || below >= above;
            var room = Math.max(96, Math.min(wanted, downward ? below : above));

            panel.style.left = box.left + "px";
            panel.style.width = box.width + "px";
            panel.style.top = downward ? box.bottom + gap + "px" : "auto";
            panel.style.bottom = downward ? "auto" : window.innerHeight - box.top + gap + "px";
            list.style.maxHeight = Math.max(48, room - status.offsetHeight - 2) + "px";
        }

        function follow() {
            if (shown) {
                place();
            }
        }

        function open() {
            if (!shown) {
                shown = true;

                if (layered) {
                    panel.showPopover();
                } else {
                    panel.hidden = false;
                }

                window.addEventListener("resize", follow);
                document.addEventListener("scroll", follow, true);
            }

            field.setAttribute("aria-expanded", "true");
            place();
        }

        function close() {
            window.clearTimeout(timer);
            ticket++;

            if (shown) {
                shown = false;

                if (layered) {
                    panel.hidePopover();
                } else {
                    panel.hidden = true;
                }

                window.removeEventListener("resize", follow);
                document.removeEventListener("scroll", follow, true);
            }

            field.setAttribute("aria-expanded", "false");
            // No row stays lit for the next opening.
            highlight(-1);
        }

        // Tells the form the shown name changed: a row of a list takes its
        // title from it.
        function notify() {
            notifying = true;
            field.dispatchEvent(new Event("input", { bubbles: true }));
            notifying = false;
        }

        function highlight(index) {
            active = index;
            Array.prototype.forEach.call(list.children, function (option, position) {
                option.classList.toggle("is-active", position === index);
            });

            if (index < 0) {
                field.removeAttribute("aria-activedescendant");

                return;
            }

            field.setAttribute("aria-activedescendant", listId + "-" + index);
            list.children[index].scrollIntoView({ block: "nearest" });
        }

        // The record held is the one in bold: marked again on every opening,
        // so a list drawn before the last choice never shows the previous one.
        function mark() {
            Array.prototype.forEach.call(list.children, function (option, index) {
                option.setAttribute("aria-selected", items[index] && String(items[index].id) === input.value ? "true" : "false");
            });
        }

        // Opened without a text, the list starts with the record held, even
        // when it is not among the first ones the source answers: the list
        // keeps its length, the last one giving way.
        function heldFirst(rows) {
            if (input.value === "" || chosen === "") {
                return rows;
            }

            var known = heldRow && String(heldRow.id) === input.value ? heldRow : { id: input.value, name: chosen };
            var held = rows.find(function (row) { return String(row.id) === input.value; }) || known;
            var others = rows.filter(function (row) { return row !== held; });

            return [held].concat(others).slice(0, Math.max(rows.length, 1));
        }

        function render(result, query) {
            items = query === "" ? heldFirst(result.items) : result.items;
            rendered = query;
            list.replaceChildren();

            items.forEach(function (item, index) {
                var option = document.createElement("li");
                option.className = "cms-combobox__option";
                option.id = listId + "-" + index;
                option.setAttribute("role", "option");
                option.setAttribute("aria-selected", String(item.id) === input.value ? "true" : "false");
                // The title, and under it where the record sits: two records
                // of the same title stay apart. A source without them gives
                // the name alone.
                var title = document.createElement("span");
                title.className = "cms-combobox__title";
                title.textContent = item.label || item.name;
                option.appendChild(title);

                if (item.path) {
                    var path = document.createElement("span");
                    path.className = "cms-combobox__path";
                    path.textContent = item.path;
                    option.appendChild(path);
                }

                // mousedown rather than click: the field would lose the focus
                // first, and close the list before the choice lands.
                option.addEventListener("mousedown", function (event) {
                    event.preventDefault();
                    pick(item);
                });
                option.addEventListener("mousemove", function () {
                    if (active !== index) {
                        highlight(index);
                    }
                });
                list.appendChild(option);
            });

            if (items.length === 0) {
                status.textContent = text("none", "No result.");
            } else if (result.total > items.length) {
                status.textContent = text("partial", "%shown% of %total% results: refine the search.", { shown: items.length, total: result.total });
            } else {
                status.textContent = "";
            }

            status.hidden = status.textContent === "";
            list.hidden = items.length === 0;

            // Lit before the list is placed: placing it computes the style of
            // the new rows, and a row lit after that fades in from nothing.
            var held = items.findIndex(function (item) { return String(item.id) === input.value; });
            highlight(query === "" ? held : (items.length > 0 ? 0 : -1));
            open();
        }

        function load(query) {
            // Opening on another search than the list drawn last: that list is
            // not shown while the new one comes. Typing keeps the list shown
            // until the answer replaces it, so it does not blink at each letter.
            if (!shown && query !== rendered) {
                items = [];
                rendered = null;
                list.replaceChildren();
                list.hidden = true;
            }

            mark();

            // A list shown again lights at once the row the answer will light:
            // the record held, on opening without a text.
            if (items.length > 0 && query === "") {
                highlight(items.findIndex(function (item) { return String(item.id) === input.value; }));
            }

            if (items.length === 0) {
                status.textContent = text("loading", "Searching…");
                status.hidden = false;
            }

            open();

            var asked = ++ticket;

            source.search(query)
                .then(function (result) {
                    if (asked === ticket && document.activeElement === field) {
                        render(result, query);
                    }
                })
                .catch(function (error) {
                    if ((error && error.name === "AbortError") || asked !== ticket) {
                        return;
                    }

                    items = [];
                    list.replaceChildren();
                    list.hidden = true;
                    status.textContent = text("failed", "The list could not be read. Type the number instead.");
                    status.hidden = false;
                    // The number field takes over, as without the script.
                    input.hidden = false;
                });
        }

        // What the field searches when it opens: the whole list while it
        // shows the record held, what was typed otherwise.
        function query() {
            return field.value === chosen ? "" : field.value.trim();
        }

        function pick(item) {
            input.value = String(item.id);
            chosen = item.name;
            heldRow = item;
            dirty = false;
            field.value = item.name;
            close();
            input.dispatchEvent(new Event("change", { bubbles: true }));
            notify();
        }

        // Leaving the field keeps a record, never a half-typed text: emptied,
        // it holds none; typed in without a choice, it shows the record held.
        function settle() {
            close();

            // Left without typing: the record held stays, its name shown or
            // still on its way, never dropped.
            if (!dirty) {
                field.value = chosen;

                return;
            }

            dirty = false;

            if (field.value.trim() === "") {
                if (input.value !== "") {
                    input.value = "";
                    chosen = "";
                    input.dispatchEvent(new Event("change", { bubbles: true }));
                }

                field.value = "";
                notify();

                return;
            }

            if (field.value !== chosen) {
                field.value = chosen;
                notify();
            }
        }

        // The name held is selected on entering the field: typing replaces it
        // with a search instead of adding to it. The release of the click
        // that focused the field would undo the selection: it is skipped once.
        field.addEventListener("focus", function () {
            justFocused = true;
            field.select();

            if (!shown) {
                load(query());
            }
        });

        field.addEventListener("mouseup", function (event) {
            if (justFocused) {
                event.preventDefault();
                justFocused = false;
            }
        });

        field.addEventListener("blur", function () { justFocused = false; });

        field.addEventListener("click", function () {
            if (!shown) {
                load(query());
            }
        });

        field.addEventListener("input", function () {
            if (notifying) {
                return;
            }

            window.clearTimeout(timer);
            dirty = true;
            var typed = field.value.trim();
            timer = window.setTimeout(function () { load(typed); }, typed === "" ? 0 : 200);
        });

        field.addEventListener("keydown", function (event) {
            if (event.key === "ArrowDown" || event.key === "ArrowUp") {
                event.preventDefault();

                if (!shown) {
                    load(query());

                    return;
                }

                if (items.length > 0) {
                    var step = event.key === "ArrowDown" ? 1 : -1;
                    highlight(active < 0 ? (step > 0 ? 0 : items.length - 1) : (active + step + items.length) % items.length);
                }

                return;
            }

            if (event.key === "Enter" && shown) {
                // Enter picks; it never sends the form with a half-typed text.
                event.preventDefault();

                if (active >= 0) {
                    pick(items[active]);
                }

                return;
            }

            if (event.key === "Escape" && shown) {
                event.preventDefault();
                close();
                dirty = false;

                if (field.value !== chosen) {
                    field.value = chosen;
                    notify();
                }
            }
        });

        field.addEventListener("blur", settle);
        // A press on the list or its scroll bar keeps the focus in the field.
        panel.addEventListener("mousedown", function (event) { event.preventDefault(); });

        if (input.value && input.value !== "0") {
            // Until the name comes back, and if it never does, the number
            // held is what the field shows.
            field.placeholder = "#" + input.value;
            var asked = input.value;
            source.find(asked)
                .then(function (row) {
                    // Too late if the editor typed or chose meanwhile.
                    if (row && input.value === asked && !dirty && field.value === "") {
                        chosen = row.name;
                        heldRow = row;
                        field.value = row.name;
                        notify();
                    }
                })
                .catch(function () {});
        }
    }

    /* ---- Select, for a short list ---------------------------------- */

    function createSelect(input, url) {
        var picker = document.createElement("select");
        picker.className = "form-select";
        picker.setAttribute("aria-label", input.labels && input.labels[0] ? input.labels[0].textContent.trim() : "");
        // The label of the field names the picker, which takes its place.
        picker.id = input.id + "_picker";
        Array.prototype.forEach.call(input.labels || [], function (label) { label.htmlFor = picker.id; });

        picker.addEventListener("change", function () {
            input.value = picker.value;
        });
        input.insertAdjacentElement("beforebegin", picker);
        input.hidden = true;

        fetch(url, { credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" } })
            .then(function (response) { return response.json(); })
            .then(function (rows) {
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
                // The title of a row named by this record.
                picker.dispatchEvent(new Event("input", { bubbles: true }));
            })
            .catch(function () { picker.remove(); input.hidden = false; });
    }

    /* ---- Binding --------------------------------------------------- */

    var factory = createCombobox;

    function bind(root) {
        (root || document).querySelectorAll("input[data-cms-picker]").forEach(function (input) {
            if (input.dataset.cmsPickerBound) {
                return;
            }

            input.dataset.cmsPickerBound = "1";
            var url = input.getAttribute("data-cms-picker");
            // Records named in the language the screen edits, when the screen
            // says it and the address does not already.
            var screen = input.closest("[data-cms-picker-locale]");

            if (screen && !/[?&]locale=/.test(url)) {
                url = address(url, "locale", screen.getAttribute("data-cms-picker-locale"));
            }

            if (input.hasAttribute("data-cms-picker-searchable")) {
                factory(input, createSource(url));
            } else {
                createSelect(input, url);
            }
        });
    }

    window.CmsRecordPicker = {
        bind: bind,
        createSource: createSource,
        // Another component for the searched fields, given `(input, source)`:
        // the fields bound from then on use it.
        use: function (component) { factory = component; },
    };

    // A screen without the content editor, the menus or the settings: bound
    // once loaded. A field already bound is left alone.
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function () { bind(document); });
    } else {
        bind(document);
    }
})();
