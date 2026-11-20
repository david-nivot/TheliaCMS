/*
 * Behaviour of the form of a section of the Thelia CMS back office.
 *
 * What makes the fields of a section form usable, wherever the form is
 * shown: the rows of a list prop (add, remove, move, drag and drop, titles),
 * the size and the framing of the pictures, the colour fields, and the
 * pickers of an image, of a record and of an icon (the first one is
 * media-picker.js, the framing button image-crop.js, the third
 * record-picker.js). Everything is progressive enhancement: without this
 * script every field of the form is there and the form works.
 *
 * `CmsSectionForm.bind(root, { labels })` binds the forms found in `root`;
 * it can be called again on a copy of the markup, and on a row added to a
 * list, which this script does itself. `labels` are the texts the screen
 * gives its scripts (see `data-cms-content-labels`); each has a default.
 *
 * A plain script rather than a Stimulus controller: a module cannot register
 * a controller in the application of the theme.
 */
(function () {
    "use strict";

    // The texts of the screen the script is bound in, given to `bind()`.
    var labels = {};


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

        // A picture without a zoom only has a centre to say.
        function refresh() {
            text.textContent = (fields.zoom ? labels.kept || "Centre %x% % / %y% %, zoom %zoom% %" : labels.keptCentre || "Centre %x% % / %y% %")
                .replace("%x%", String(valueOf(fields.x, 50)))
                .replace("%y%", String(valueOf(fields.y, 50)))
                .replace("%zoom%", fields.zoom ? String(valueOf(fields.zoom, 100)) : "");
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

    // The pictures of the blocks of Mises en avant: each fills its block with
    // `object-fit: cover`, placed by its two numbers, without a zoom. The two
    // numbers the template folds give way to a sentence and the button that
    // sets them on the picture. The block a picture fills is the one
    // `blocks.css` draws at 1920 px: the lone block 1920 by 648, the large one
    // 1280 by 648, the small one beside it 640 by 648, each of two 640 by 324.
    function bindHighlightsFraming(root) {
        var SHOWN = 1440;

        function boxAt(position, count) {
            if (count < 2) {
                return { boxWidth: 1920, boxHeight: 648, shown: SHOWN, ratio: null };
            }

            if (position === 0) {
                return { boxWidth: 1280, boxHeight: 648, shown: Math.round(SHOWN * 2 / 3), ratio: null };
            }

            return { boxWidth: 640, boxHeight: count === 2 ? 648 : 324, shown: Math.round(SHOWN / 3), ratio: null };
        }

        root.querySelectorAll('form[data-cms-component="cms:highlights"]').forEach(function (form) {
            var items = form.querySelector("[data-cms-items]");
            var rows = items ? items.querySelector("[data-cms-items-list]") : null;

            if (form.hasAttribute("data-cms-highlights-framing") || !rows) {
                return;
            }

            form.setAttribute("data-cms-highlights-framing", "");

            function blocks() {
                return Array.prototype.slice.call(rows.querySelectorAll(":scope > [data-cms-item]"));
            }

            function holderOf(name, row) {
                var picker = row.querySelector('input[data-cms-image-picker][name$="[' + name + ']"]');

                return picker ? picker.closest(".cms-content__field") || picker.closest(".mb-3") || picker.parentElement : null;
            }

            function prepare(row) {
                var fx = row.querySelector('[name$="[focus_x]"]');
                var fy = row.querySelector('[name$="[focus_y]"]');
                var holder = holderOf("image", row);

                if (row.hasAttribute("data-cms-framing-row") || !fx || !fy || !holder) {
                    return;
                }

                row.setAttribute("data-cms-framing-row", "");
                // The fold that holds the two numbers without the script.
                (fx.closest("details") || fx.closest(".cms-content__field") || fx.parentElement).hidden = true;

                // Under the picture, and under the other ways of giving one.
                var anchor = holder;

                if (anchor.nextElementSibling && anchor.nextElementSibling.matches("details")) {
                    anchor = anchor.nextElementSibling;
                }

                var summary = keptSummary(row, anchor, { x: fx, y: fy, zoom: null }, function () {
                    var all = blocks();

                    return boxAt(all.indexOf(row), all.length);
                });
                summary.classList.add("cms-content__kept--row");

                var slot = summary.querySelector("[data-cms-kept-slot]");
                slot.cmsCropCentreOnly = true;
                slot.cmsCropFields = { x: fx, y: fy, zoom: null };
                // The picture for a wide screen, or the one for a narrow screen when it is the only one.
                slot.cmsCropField = function () {
                    var wide = holder.querySelector("[data-cms-image-field]");
                    var narrow = holderOf("image_mobile", row);
                    var other = narrow ? narrow.querySelector("[data-cms-image-field]") : null;

                    return wide && wide.getAttribute("data-cms-image-source") === "none" && other && other.getAttribute("data-cms-image-source") !== "none" ? other : wide;
                };
            }

            blocks().forEach(prepare);
            rows.addEventListener("cms-item-added", function (event) {
                prepare(event.target);
            });
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

            // In a row, where each picture repeats them, the proportions and the
            // kept part are set rarely: folded under one line.
            if (scope.hasAttribute("data-cms-item")) {
                var fold = document.createElement("details");
                fold.className = "cms-content__more";
                fold.setAttribute("data-cms-framing-fold", "");
                var title = document.createElement("summary");
                title.textContent = labels.framing || "Kept part of the picture";
                fold.appendChild(title);
                (shape || summary).before(fold);

                if (shape) {
                    fold.appendChild(shape);
                }

                fold.appendChild(summary);
            }

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

    // A colour of the appearance is typed #rrggbb, or picked: the picker
    // next to the field writes into it. The field stays what is sent, so
    // emptied it is the colour of the theme, which a picker cannot say.
    function bindColorFields(root) {
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
    }

    // Rows of a list prop, from the prototype of the collection.
    function bindItemLists(root) {
    root.querySelectorAll("[data-cms-items]").forEach(function (list) {
        var rows = list.querySelector("[data-cms-items-list]");
        var add = list.querySelector("[data-cms-items-add]");
        var prototype = list.getAttribute("data-prototype");
        var max = parseInt(list.getAttribute("data-cms-items-max"), 10) || 12;
        var min = parseInt(list.getAttribute("data-cms-items-min"), 10) || 0;
        var counter = rows.children.length;
        var name = list.getAttribute("data-cms-items-name") || "";
        var count = list.querySelector("[data-cms-items-count]");
        var status = list.querySelector("[data-cms-items-status]");
        var head = new RegExp("^" + name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + "\\[[^\\]]*\\]");

        // A form sent back with an error keeps the indexes of the rows that were removed: a new row
        // takes an index above every one in place.
        rows.querySelectorAll("[name]").forEach(function (field) {
            var found = name && field.name.indexOf(name + "[") === 0 ? parseInt(field.name.slice(name.length + 1), 10) : NaN;

            if (found >= counter) {
                counter = found + 1;
            }
        });

        // The form keeps the rows in the order of their indexes, whatever the order they are sent in:
        // the fields are renamed to the order the rows are in. The ids stay, the editor, the picker
        // and the framing hold on to them.
        function renumber() {
            if (!name) {
                return;
            }

            Array.prototype.forEach.call(rows.children, function (row, index) {
                row.querySelectorAll("[name]").forEach(function (field) {
                    field.name = field.name.replace(head, name + "[" + index + "]");
                });
            });
        }

        // The words a row is folded under come from one of its fields.
        function retitle(row) {
            var label = row.querySelector("[data-cms-item-title]");

            if (!label) {
                return;
            }

            var id = label.getAttribute("data-cms-item-source");
            var source = id ? row.querySelector('[id="' + id + '"]') : null;
            // A text of paragraphs is HTML: its words without the tags.
            // A record picked from a select reads by the name of its option.
            var text = source && source.tagName === "SELECT"
                ? (source.value ? source.options[source.selectedIndex].textContent : "")
                : (source ? source.value : "");
            var words = text.replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim();

            label.textContent = words ? words.slice(0, 80) : label.getAttribute("data-cms-item-empty");
        }

        function refresh() {
            var items = rows.querySelectorAll(":scope > [data-cms-item]");
            var total = items.length;
            add.hidden = total >= max;

            if (count) {
                count.textContent = total + " / " + max;
            }

            items.forEach(function (row, index) {
                var up = row.querySelector(":scope > .cms-content__item-tools > [data-cms-item-up]");
                var down = row.querySelector(":scope > .cms-content__item-tools > [data-cms-item-down]");
                var remove = row.querySelector(":scope > .cms-content__item-tools > [data-cms-item-remove]");

                if (up && down) {
                    up.hidden = down.hidden = total < 2;
                    up.disabled = index === 0;
                    down.disabled = index === total - 1;
                }

                if (remove) {
                    remove.hidden = total <= min;
                }

                var grip = row.querySelector(":scope > [data-cms-item-grip]");

                if (grip) {
                    grip.hidden = total < 2;
                }
            });
        }

        function moved(row) {
            if (status && list.getAttribute("data-cms-items-moved")) {
                status.textContent = list.getAttribute("data-cms-items-moved")
                    .replace("%position%", String(Array.prototype.indexOf.call(rows.children, row) + 1))
                    .replace("%total%", String(rows.children.length));
            }
        }

        // A row is dragged by its handle only, inside its own list: the whole row draggable would steal
        // the selection of the text of its fields. It moves while it is dragged; let go outside the
        // list, it goes back where it was.
        var dragged = null;
        var draggedFrom = null;
        var dropped = false;

        rows.addEventListener("pointerdown", function (event) {
            var grip = event.target.closest("[data-cms-item-grip]");

            if (grip && grip.parentNode.parentNode === rows) {
                grip.parentNode.draggable = true;
            }
        });

        rows.addEventListener("pointerup", function (event) {
            var grip = event.target.closest("[data-cms-item-grip]");

            if (grip) {
                grip.parentNode.draggable = false;
            }
        });

        rows.addEventListener("dragstart", function (event) {
            var row = event.target;

            if (!row.hasAttribute || !row.hasAttribute("data-cms-item") || row.parentNode !== rows || !row.draggable) {
                return;
            }

            // The list of sections around the dialog would take it for a section dragged without its handle.
            event.stopPropagation();
            dragged = row;
            draggedFrom = row.nextElementSibling;
            dropped = false;
            event.dataTransfer.effectAllowed = "move";
            event.dataTransfer.setData("text/plain", "");
            row.classList.add("cms-content__item--dragged");
        });

        rows.addEventListener("dragover", function (event) {
            if (!dragged) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            var over = event.target.closest && event.target.closest("[data-cms-item]");

            while (over && over.parentNode !== rows) {
                over = over.parentNode.closest("[data-cms-item]");
            }

            if (!over || over === dragged) {
                return;
            }

            var box = over.getBoundingClientRect();
            rows.insertBefore(dragged, event.clientY < box.top + box.height / 2 ? over : over.nextElementSibling);
        });

        rows.addEventListener("drop", function (event) {
            if (dragged) {
                event.preventDefault();
                event.stopPropagation();
                dropped = true;
            }
        });

        rows.addEventListener("dragend", function (event) {
            if (!dragged || event.target !== dragged) {
                return;
            }

            var row = dragged;
            dragged = null;
            row.draggable = false;
            row.classList.remove("cms-content__item--dragged");

            if (!dropped) {
                rows.insertBefore(row, draggedFrom);
            }

            if (row.nextElementSibling === draggedFrom) {
                return;
            }

            renumber();
            refresh();
            moved(row);
        });

        add.addEventListener("click", function () {
            var holder = document.createElement("template");
            holder.innerHTML = prototype.replace(/__name__/g, String(counter++)).trim();
            var row = holder.content.firstElementChild;
            rows.appendChild(row);
            retitle(row);
            refresh();

            // What the form binds on the rows it was loaded with: the picker of an image and, for a
            // list of pictures, the part of them that is kept.
            if (window.CmsMediaPicker) {
                window.CmsMediaPicker.bind(row);
            }

            bindRecordPickers(row);
            bindIconPickers(row);
            row.dispatchEvent(new CustomEvent("cms-item-added", { bubbles: true }));

            if (window.CmsImageCrop) {
                window.CmsImageCrop.bind(row);
            }

            row.querySelector("input, textarea, select")?.focus();
        });

        rows.addEventListener("click", function (event) {
            var remove = event.target.closest("[data-cms-item-remove]");
            var move = event.target.closest("[data-cms-item-up], [data-cms-item-down]");

            if (remove) {
                remove.closest("[data-cms-item]").remove();
                refresh();
            }

            if (!move || move.disabled) {
                return;
            }

            var row = move.closest("[data-cms-item]");
            var up = move.hasAttribute("data-cms-item-up");
            var other = up ? row.previousElementSibling : row.nextElementSibling;

            if (!other) {
                return;
            }

            rows.insertBefore(row, up ? other : other.nextElementSibling);
            renumber();
            refresh();

            // The focus stays on the button of the row that moved; at the end of the list that button is
            // off, and the one of the other direction takes it.
            var kept = move.disabled ? row.querySelector(":scope > .cms-content__item-tools > " + (up ? "[data-cms-item-down]" : "[data-cms-item-up]")) : move;
            kept.focus();
            moved(row);
        });

        rows.addEventListener("input", function (event) {
            var row = event.target.closest("[data-cms-item]");

            if (row) {
                retitle(row);
            }
        });

        refresh();
    });
    }

    // A reference is picked by record-picker.js, which a new section form asks
    // for too.
    function bindRecordPickers(root) {
        if (window.CmsRecordPicker) {
            window.CmsRecordPicker.bind(root);
        }
    }

    /* ---- Icon picker ----------------------------------------------- */

    // The icons of a source, read once: every field of the screen asks the
    // same address.
    var iconLists = {};

    function iconList(source) {
        if (!iconLists[source]) {
            iconLists[source] = fetch(source, { credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest" } })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error("icons");
                    }

                    return response.json();
                })
                .catch(function (error) {
                    delete iconLists[source];
                    throw error;
                });
        }

        return iconLists[source];
    }

    // A grid of previews with a search box, beside the text field that holds
    // the name: the field stays what the form sends and what is typed in when
    // the list cannot be read. The preview is the `data:` address of the SVG
    // shown in an <img>, which runs nothing; no markup of the list is injected.
    function bindIconPickers(root) {
        root.querySelectorAll("input[data-cms-icon-picker]").forEach(function (input) {
            if (input.dataset.cmsIconBound) {
                return;
            }

            input.dataset.cmsIconBound = "1";

            var source = input.getAttribute("data-cms-icon-picker");
            var holder = document.createElement("div");
            holder.className = "cms-icons";
            input.insertAdjacentElement("afterend", holder);

            var toggle = document.createElement("button");
            toggle.type = "button";
            toggle.className = "btn btn-outline-secondary btn-sm cms-icons__toggle";
            toggle.textContent = labels.iconChoose || "Choose an icon";
            toggle.setAttribute("aria-expanded", "false");
            holder.appendChild(toggle);

            var panel = document.createElement("div");
            panel.className = "cms-icons__panel";
            panel.hidden = true;
            holder.appendChild(panel);

            var search = document.createElement("input");
            search.type = "search";
            search.className = "form-control form-control-sm";
            search.setAttribute("aria-label", labels.iconSearch || "Search an icon");
            search.placeholder = labels.iconSearch || "Search an icon";
            panel.appendChild(search);

            var results = document.createElement("div");
            results.className = "cms-icons__results";
            panel.appendChild(results);

            var rows = [];
            var groups = [["theme", labels.iconTheme || "Theme"], ["cms", labels.iconCms || "CMS"]];

            function pick(name) {
                input.value = name;
                input.dispatchEvent(new Event("input", { bubbles: true }));
                input.dispatchEvent(new Event("change", { bubbles: true }));
                close();
                input.focus();
            }

            function previewImage(preview) {
                var image = document.createElement("img");
                image.alt = "";
                image.src = preview;
                image.className = "cms-icons__image";

                return image;
            }

            function tile(name, label, preview) {
                var button = document.createElement("button");
                button.type = "button";
                button.className = "cms-icons__tile";
                button.title = name;

                if (name === input.value) {
                    button.setAttribute("aria-pressed", "true");
                }

                var picture = document.createElement("span");
                picture.className = "cms-icons__picture";

                if (preview.indexOf("data:image/svg+xml;base64,") === 0) {
                    picture.appendChild(previewImage(preview));
                }

                var caption = document.createElement("span");
                caption.className = "cms-icons__name";
                caption.textContent = label;
                button.appendChild(picture);
                button.appendChild(caption);
                button.addEventListener("click", function () { pick(name); });

                return button;
            }

            function draw() {
                var needle = search.value.trim().toLowerCase();
                results.replaceChildren();

                if (!input.required) {
                    results.appendChild(tile("", labels.iconNone || "No icon", ""));
                }

                var shown = 0;

                // The icons of the theme come by sub-directory (`set`), those at
                // its root first, as in the icons story of the toolkit.
                var sections = [];
                groups.forEach(function (group) {
                    var sets = [];
                    rows.forEach(function (row) {
                        var set = row.set || "";

                        if (row.group === group[0] && sets.indexOf(set) === -1) {
                            sets.push(set);
                        }
                    });
                    sets.sort();
                    sets.forEach(function (set) {
                        sections.push([group[0], set, set === "" ? group[1] : group[1] + " · " + set]);
                    });
                });

                sections.forEach(function (section) {
                    var found = rows.filter(function (row) {
                        return row.group === section[0] && (row.set || "") === section[1]
                            && (needle === "" || row.name.toLowerCase().indexOf(needle) !== -1 || String(row.label).toLowerCase().indexOf(needle) !== -1);
                    });

                    if (found.length === 0) {
                        return;
                    }

                    var title = document.createElement("p");
                    title.className = "cms-icons__group";
                    title.textContent = section[2];
                    results.appendChild(title);
                    var grid = document.createElement("div");
                    grid.className = "cms-icons__grid";
                    found.forEach(function (row) { grid.appendChild(tile(row.name, row.label, row.preview || "")); });
                    results.appendChild(grid);
                    shown += found.length;
                });

                if (shown === 0) {
                    var empty = document.createElement("p");
                    empty.className = "cms-icons__empty text-muted";
                    empty.textContent = labels.iconEmpty || "No icon matches.";
                    results.appendChild(empty);
                }
            }

            function close() {
                panel.hidden = true;
                toggle.setAttribute("aria-expanded", "false");
            }

            toggle.addEventListener("click", function () {
                if (!panel.hidden) {
                    close();

                    return;
                }

                iconList(source).then(function (list) {
                    rows = Array.isArray(list) ? list : [];
                    search.value = "";
                    draw();
                    panel.hidden = false;
                    toggle.setAttribute("aria-expanded", "true");
                    search.focus();
                }).catch(function () {
                    // The list cannot be read: the field stays a text field.
                    holder.remove();
                });
            });

            search.addEventListener("input", draw);
            holder.addEventListener("keydown", function (event) {
                if (event.key === "Escape" && !panel.hidden) {
                    event.stopPropagation();
                    close();
                    toggle.focus();
                }
            });
        });
    }

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

    window.CmsSectionForm = {
        bind: function (root, options) {
            labels = (options && options.labels) || labels;

            bindColorFields(root);
            bindItemLists(root);
            bindImageDimensions(root);
            bindPreciseRoom(root);
            bindColumnsFraming(root);
            bindHighlightsFraming(root);
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

            bindRecordPickers(root);
            bindIconPickers(root);
        }
    };
})();
