/*
 * The part of an image a section keeps, set in a dialog of its own.
 *
 * The form of an Image section holds three numbers, `focus_x`, `focus_y` and
 * `zoom`, that content-editor.js reads as a sentence. This adds the button
 * that sets them: it opens one dialog for the screen, above the drawer of the
 * section, with the whole image, the zone kept, and what the box will show.
 *
 * Nothing is asked of the server while the zone moves: the image shown is the
 * file the picker already holds, and the preview is made of it in the
 * browser, with the same arithmetic as `Content/ImageFrame`. The dialog writes
 * the three numbers in the form when it is applied, and saves nothing itself.
 *
 * An image kept elsewhere is framed by CSS alone: only its centre can be set,
 * and the zoom stays at 100 %.
 *
 * content-editor.js calls `CmsImageCrop.bind(root)` on every form it enhances,
 * once the slot for the button (`[data-cms-kept-slot]`) is there.
 */
(function () {
    'use strict';

    var m = {};
    var crop = null;
    var identifier = 0;

    function element(tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text) {
            node.textContent = text;
        }

        return node;
    }

    function clamp(value, low, high) {
        return Math.max(low, Math.min(high, value));
    }

    function say(template, values) {
        return Object.keys(values).reduce(function (text, key) {
            return text.split('%' + key + '%').join(String(values[key]));
        }, template || '');
    }

    // The zone of the original that fills the box: the arithmetic of
    // `Content/ImageFrame::zone()`, without the shortcut for the whole picture.
    function servedZone(width, height, boxWidth, boxHeight, focusX, focusY, zoom) {
        var ratio = boxWidth / boxHeight;
        var largest = width / height >= ratio ? height * ratio : width;
        var w = Math.max(1, Math.min(width, Math.floor(largest * 100 / Math.max(100, zoom) + 1e-9)));
        var h = Math.max(1, Math.min(height, Math.round(w / ratio)));

        return {
            x: clamp(Math.round(width * clamp(focusX, 0, 100) / 100 - w / 2), 0, width - w),
            y: clamp(Math.round(height * clamp(focusY, 0, 100) / 100 - h / 2), 0, height - h),
            w: w,
            h: h,
        };
    }

    // What the browser does with `object-fit: cover` and `object-position`:
    // the largest zone of the box proportions, placed by the focal point
    // along the room the picture leaves.
    function coveredZone(width, height, boxWidth, boxHeight, focusX, focusY) {
        var ratio = boxWidth / boxHeight;
        var w = width / height >= ratio ? height * ratio : width;
        var h = width / height >= ratio ? height : width / ratio;

        return {
            x: (width - w) * clamp(focusX, 0, 100) / 100,
            y: (height - h) * clamp(focusY, 0, 100) / 100,
            w: w,
            h: h,
        };
    }

    // The dialog, one for the screen.
    function theCrop() {
        if (crop) {
            return crop;
        }

        identifier += 1;
        var prefix = 'cms-crop-' + identifier;

        var dialog = element('dialog', 'cms-crop border-0 rounded shadow p-0');
        dialog.setAttribute('data-cms-image-crop', '');
        dialog.setAttribute('aria-labelledby', prefix + '-title');

        var body = element('div', 'cms-crop__body p-3 d-flex flex-column gap-3');
        var head = element('div', 'd-flex justify-content-between align-items-center');
        var title = element('h2', 'h5 mb-0', m.title);
        title.id = prefix + '-title';
        var close = element('button', 'btn-close');
        close.type = 'button';
        close.setAttribute('aria-label', m.cancel);
        head.append(title, close);

        var layout = element('div', 'cms-crop__layout');

        // The whole image, the zone kept in it.
        var original = element('div', 'cms-crop__original');
        var originalTitle = element('p', 'cms-crop__caption', m.original);
        originalTitle.id = prefix + '-original';
        var stage = element('div', 'cms-crop__stage');
        stage.setAttribute('data-cms-crop-stage', '');
        var picture = element('img', 'cms-crop__picture');
        picture.alt = '';
        picture.draggable = false;
        var frame = element('div', 'cms-crop__frame');
        frame.tabIndex = 0;
        frame.setAttribute('role', 'group');
        frame.setAttribute('aria-label', m.frame);
        frame.setAttribute('data-cms-crop-frame', '');
        var mark = element('span', 'cms-crop__mark');
        mark.setAttribute('aria-hidden', 'true');
        stage.append(picture, frame, mark);
        var legend = element('p', 'cms-crop__legend', m.legend);
        original.append(originalTitle, stage, legend);

        // What the box shows.
        var result = element('div', 'cms-crop__result');
        var resultTitle = element('p', 'cms-crop__caption');
        var preview = element('div', 'cms-crop__preview');
        preview.setAttribute('data-cms-crop-preview', '');
        var shown = element('img', 'cms-crop__shown');
        shown.alt = '';
        shown.draggable = false;
        preview.appendChild(shown);
        var zoneText = element('p', 'cms-crop__zone mb-0');
        zoneText.setAttribute('data-cms-crop-zone', '');
        var sharpness = element('p', 'cms-crop__sharpness mb-0');
        sharpness.setAttribute('data-cms-crop-sharpness', '');
        var note = element('p', 'cms-crop__note mb-0');
        result.append(resultTitle, preview, zoneText, sharpness, note);
        layout.append(original, result);

        // The same three numbers, with no dragging needed.
        var sliders = element('div', 'cms-crop__sliders');

        function slider(name, label, low, high, step) {
            var row = element('div', 'cms-crop__slider');
            var text = element('label', 'form-label mb-0', label);
            text.htmlFor = prefix + '-' + name;
            var input = element('input', 'form-range');
            input.type = 'range';
            input.id = prefix + '-' + name;
            input.min = String(low);
            input.max = String(high);
            input.step = String(step);
            var value = element('output', 'cms-crop__value');
            value.htmlFor = input.id;
            row.append(text, input, value);
            sliders.appendChild(row);

            return { input: input, value: value, row: row };
        }

        var horizontal = slider('x', m.horizontal, 0, 100, 1);
        var vertical = slider('y', m.vertical, 0, 100, 1);
        var zooming = slider('zoom', m.zoom, 100, 400, 10);

        var live = element('p', 'visually-hidden');
        live.setAttribute('role', 'status');
        live.setAttribute('aria-live', 'polite');

        var foot = element('div', 'd-flex flex-wrap justify-content-between align-items-center gap-2');
        var tools = element('div', 'd-flex gap-2');
        var centre = element('button', 'btn btn-sm btn-outline-secondary', m.centre);
        centre.type = 'button';
        var reset = element('button', 'btn btn-sm btn-outline-secondary', m.reset);
        reset.type = 'button';
        tools.append(centre, reset);
        var actions = element('div', 'd-flex gap-2');
        var cancel = element('button', 'btn btn-sm btn-outline-secondary', m.cancel);
        cancel.type = 'button';
        var apply = element('button', 'btn btn-sm btn-primary', m.apply);
        apply.type = 'button';
        apply.setAttribute('data-cms-crop-apply', '');
        actions.append(cancel, apply);
        foot.append(tools, actions);

        body.append(head, layout, sliders, live, foot);
        dialog.appendChild(body);
        document.body.appendChild(dialog);

        // What the dialog works on, set on every opening.
        var state = { x: 50, y: 50, zoom: 100, boxWidth: 0, boxHeight: 0, width: 0, height: 0, served: true, apply: null, opener: null };
        var drag = null;

        function zone() {
            return state.served
                ? servedZone(state.width, state.height, state.boxWidth, state.boxHeight, state.x, state.y, state.zoom)
                : coveredZone(state.width, state.height, state.boxWidth, state.boxHeight, state.x, state.y);
        }

        // The focus a zone placed at `left` and `top` has: the point it is
        // centred on, or the share of the room the picture leaves.
        function focusOf(left, top, area) {
            if (state.served) {
                return [
                    Math.round((left + area.w / 2) / state.width * 100),
                    Math.round((top + area.h / 2) / state.height * 100),
                ];
            }

            return [
                state.width - area.w > 0.5 ? Math.round(left / (state.width - area.w) * 100) : state.x,
                state.height - area.h > 0.5 ? Math.round(top / (state.height - area.h) * 100) : state.y,
            ];
        }

        // The focus the zone shows now: what an arrow key moves from, so that
        // a zone held against an edge by the picture does not swallow a press.
        function shownFocus() {
            return focusOf(zone().x, zone().y, zone());
        }

        function announce() {
            var area = zone();
            live.textContent = say(m.live, { w: Math.round(area.w), h: Math.round(area.h), x: state.x, y: state.y, zoom: state.zoom });
        }

        function paint() {
            var area = zone();

            frame.style.left = (area.x / state.width * 100) + '%';
            frame.style.top = (area.y / state.height * 100) + '%';
            frame.style.width = (area.w / state.width * 100) + '%';
            frame.style.height = (area.h / state.height * 100) + '%';
            mark.style.left = (state.served ? state.x : (area.x + area.w / 2) / state.width * 100) + '%';
            mark.style.top = (state.served ? state.y : (area.y + area.h / 2) / state.height * 100) + '%';

            shown.style.width = (state.width / area.w * 100) + '%';
            shown.style.left = (-area.x / area.w * 100) + '%';
            shown.style.top = (-area.y / area.h * 100) + '%';

            horizontal.input.value = String(state.x);
            vertical.input.value = String(state.y);
            zooming.input.value = String(state.zoom);
            [[horizontal, state.x], [vertical, state.y], [zooming, state.zoom]].forEach(function (pair) {
                var text = pair[1] + ' %';
                pair[0].value.textContent = text;
                pair[0].input.setAttribute('aria-valuetext', text);
            });

            if (state.served) {
                zoneText.textContent = say(m.zone, { w: Math.round(area.w), h: Math.round(area.h) });
                // The zone against the width it is shown at, and against twice
                // that on a screen of high density.
                var level = area.w >= state.boxWidth * 2 ? 'sharp' : (area.w >= state.boxWidth ? 'fair' : 'soft');
                sharpness.textContent = say(m[level], { w: Math.round(area.w), box: state.boxWidth });
                sharpness.setAttribute('data-level', level);
                sharpness.hidden = false;
            } else {
                zoneText.textContent = '';
                sharpness.hidden = true;
            }
        }

        function set(x, y, zoom) {
            state.x = clamp(Math.round(x), 0, 100);
            state.y = clamp(Math.round(y), 0, 100);
            state.zoom = state.served ? clamp(Math.round(zoom / 10) * 10, 100, 400) : state.zoom;
            paint();
        }

        // Pointer: a click puts the zone there, a drag moves it. The zone
        // keeps its proportions and stays in the picture.
        function pointerAt(event) {
            var rect = stage.getBoundingClientRect();

            return [(event.clientX - rect.left) / rect.width * state.width, (event.clientY - rect.top) / rect.height * state.height];
        }

        function moveTo(event) {
            var area = zone();
            var at = pointerAt(event);
            var left = clamp(at[0] - drag.grabX, 0, state.width - area.w);
            var top = clamp(at[1] - drag.grabY, 0, state.height - area.h);
            var focus = focusOf(left, top, area);

            set(focus[0], focus[1], state.zoom);
        }

        stage.addEventListener('pointerdown', function (event) {
            if (event.pointerType === 'mouse' && event.button !== 0) {
                return;
            }

            event.preventDefault();
            var area = zone();
            var at = pointerAt(event);
            var inside = at[0] >= area.x && at[0] <= area.x + area.w && at[1] >= area.y && at[1] <= area.y + area.h;

            drag = { grabX: inside ? at[0] - area.x : area.w / 2, grabY: inside ? at[1] - area.y : area.h / 2 };
            stage.setPointerCapture(event.pointerId);
            frame.focus({ preventScroll: true });
            moveTo(event);
        });
        stage.addEventListener('pointermove', function (event) {
            if (drag) {
                moveTo(event);
            }
        });
        ['pointerup', 'pointercancel'].forEach(function (name) {
            stage.addEventListener(name, function (event) {
                if (!drag) {
                    return;
                }

                drag = null;

                if (stage.hasPointerCapture(event.pointerId)) {
                    stage.releasePointerCapture(event.pointerId);
                }

                announce();
            });
        });

        // Keyboard: arrows move by 1 %, with Shift by 10 %; plus and minus,
        // Page Up and Page Down zoom by 10 %; Home puts the centre back.
        frame.addEventListener('keydown', function (event) {
            if (event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }

            var step = event.shiftKey ? 10 : 1;
            var focus = shownFocus();
            var moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
            var zooms = { '+': 10, '=': 10, Add: 10, PageUp: 10, '-': -10, Subtract: -10, PageDown: -10 };

            if (moves[event.key]) {
                set(focus[0] + moves[event.key][0], focus[1] + moves[event.key][1], state.zoom);
            } else if (zooms[event.key] !== undefined) {
                if (state.served) {
                    set(state.x, state.y, state.zoom + zooms[event.key]);
                }
            } else if (event.key === 'Home') {
                set(50, 50, state.zoom);
            } else {
                return;
            }

            event.preventDefault();
            announce();
        });

        [horizontal, vertical, zooming].forEach(function (one) {
            one.input.addEventListener('input', function () {
                set(parseInt(horizontal.input.value, 10), parseInt(vertical.input.value, 10), parseInt(zooming.input.value, 10));
            });
            one.input.addEventListener('change', announce);
        });

        centre.addEventListener('click', function () { set(50, 50, state.zoom); announce(); });
        reset.addEventListener('click', function () { set(50, 50, 100); announce(); });
        close.addEventListener('click', function () { dialog.close(); });
        cancel.addEventListener('click', function () { dialog.close(); });
        apply.addEventListener('click', function () {
            if (state.apply) {
                state.apply(state.x, state.y, state.served ? state.zoom : null);
            }

            dialog.close();
        });
        dialog.addEventListener('close', function () {
            drag = null;

            if (state.opener) {
                state.opener.focus();
            }
        });

        crop = {
            open: function (options) {
                state.x = options.x;
                state.y = options.y;
                state.zoom = options.zoom;
                state.boxWidth = options.boxWidth;
                state.boxHeight = options.boxHeight;
                state.width = options.width;
                state.height = options.height;
                state.served = options.served;
                state.apply = options.apply;
                state.opener = options.opener;

                picture.src = options.url;
                shown.src = options.url;
                resultTitle.textContent = say(m.result, { w: options.boxWidth, h: options.boxHeight });
                // The stage takes the proportions of the picture before it loads.
                stage.style.aspectRatio = options.width + ' / ' + options.height;
                stage.style.setProperty('--cms-crop-ratio', String(options.width / options.height));
                preview.style.aspectRatio = options.boxWidth + ' / ' + options.boxHeight;
                preview.style.maxWidth = options.boxWidth + 'px';
                zooming.row.hidden = !options.served;
                note.textContent = options.served ? '' : m.external;
                note.hidden = options.served;
                state.zoom = options.served ? clamp(Math.round(options.zoom / 10) * 10, 100, 400) : 100;
                paint();
                dialog.showModal();
                announce();
                frame.focus({ preventScroll: true });
            },
        };

        return crop;
    }

    // The button, in the slot content-editor.js leaves in the summary.
    function bindForm(form) {
        var slot = form.querySelector('[data-cms-kept-slot]');
        var field = form.querySelector('[data-cms-image-field]');

        function named(name) {
            return form.querySelector('[name$="[' + name + ']"]');
        }

        var focusX = named('focus_x');
        var focusY = named('focus_y');
        var zoom = named('zoom');
        var widthPixels = named('width_px');
        var heightPixels = named('height_px');

        if (!slot || !field || !focusX || !focusY || !zoom || !widthPixels || !heightPixels) {
            return;
        }

        form.setAttribute('data-cms-crop-bound', '');

        var button = element('button', 'btn btn-sm btn-outline-primary');
        button.type = 'button';
        button.setAttribute('data-cms-crop-open', '');
        button.append(element('i', 'bi bi-crop me-1'), document.createTextNode(m.open));
        var reason = element('span', 'cms-content__kept-reason');
        reason.id = 'cms-crop-reason-' + (identifier + 1) + '-' + Math.floor(Math.random() * 1e6);
        reason.setAttribute('data-cms-crop-reason', '');
        slot.append(button, reason);

        // Size of an image the library does not describe: read from the file.
        var measured = {};

        function image() {
            var source = field.getAttribute('data-cms-image-source') || 'none';
            var url = field.getAttribute('data-cms-image-url') || '';
            var width = parseInt(field.getAttribute('data-cms-image-width') || '0', 10);
            var height = parseInt(field.getAttribute('data-cms-image-height') || '0', 10);

            if (source === 'none' || url === '') {
                return { source: 'none' };
            }

            if (!(width > 0 && height > 0) && measured[url]) {
                width = measured[url].width;
                height = measured[url].height;
            }

            if (!(width > 0 && height > 0) && !measured[url]) {
                var probe = new Image();
                measured[url] = { pending: true };
                probe.onload = function () {
                    measured[url] = probe.naturalWidth > 0 ? { width: probe.naturalWidth, height: probe.naturalHeight } : { failed: true };
                    sync();
                };
                probe.onerror = function () {
                    measured[url] = { failed: true };
                    sync();
                };
                probe.src = url;
            }

            return { source: source, url: url, width: width, height: height, pending: !!(measured[url] && measured[url].pending), failed: !!(measured[url] && measured[url].failed) };
        }

        function sync() {
            var one = image();
            var why = '';

            if (one.source === 'none') {
                why = m.noImage;
            } else if (!(one.width > 0 && one.height > 0)) {
                why = one.failed ? m.unknownSize : m.measuring;
            }

            button.disabled = why !== '';
            reason.textContent = why;

            if (why !== '') {
                button.setAttribute('aria-describedby', reason.id);
            } else {
                button.removeAttribute('aria-describedby');
            }
        }

        function number(input, fallback) {
            var value = parseInt(input.value, 10);

            return isNaN(value) ? fallback : value;
        }

        function write(input, value) {
            input.value = String(value);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        button.addEventListener('click', function () {
            var one = image();
            var boxWidth = number(widthPixels, 0);
            var boxHeight = number(heightPixels, 0);

            if (button.disabled || !(boxWidth > 0 && boxHeight > 0)) {
                return;
            }

            theCrop().open({
                url: one.url,
                width: one.width,
                height: one.height,
                served: one.source === 'library',
                boxWidth: boxWidth,
                boxHeight: boxHeight,
                x: number(focusX, 50),
                y: number(focusY, 50),
                zoom: number(zoom, 100),
                opener: button,
                apply: function (x, y, zoomed) {
                    write(focusX, x);
                    write(focusY, y);

                    if (zoomed !== null) {
                        write(zoom, zoomed);
                    }
                },
            });
        });

        form.addEventListener('cms-image-shown', sync);
        form.addEventListener('input', sync);
        form.addEventListener('change', sync);
        sync();
    }

    function bind(root) {
        var source = document.querySelector('script[data-cms-image-crop-messages]');

        if (source && Object.keys(m).length === 0) {
            try {
                m = JSON.parse(source.textContent);
            } catch (error) {
                m = {};
            }
        }

        if (Object.keys(m).length === 0) {
            return;
        }

        (root || document).querySelectorAll('form[data-cms-section-form]:not([data-cms-crop-bound])').forEach(bindForm);
    }

    window.CmsImageCrop = { bind: bind };
})();
