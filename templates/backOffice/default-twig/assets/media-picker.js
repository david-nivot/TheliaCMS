/*
 * The image of a section, chosen in the media library of the CMS.
 *
 * The form holds the id of the image in a number field; this draws the image
 * it names instead (thumbnail, name, whether it is described) with "Choose in
 * the library" and "Remove". Choosing opens one dialog for the whole screen:
 * the library as a grid of thumbnails, searched, filtered by what is still to
 * describe or by a tag, by pages, and an image can be sent from there. The
 * dialog stacks over the dialog of the section.
 *
 * The other sources of a picture, a file or an https address, stay the
 * fields they are, folded under the image: what they hold is drawn in the
 * same place.
 *
 * The field of a video (`data-cms-video-picker`, `data-cms-media-kind="video"`)
 * is the same dialog asked for the videos of the library only: a tile and the
 * preview of the field play the first frame of the file in a <video>, and
 * there is no text to give nor picture to crop.
 *
 * The script binds the fields of the screen once loaded, and content-editor.js
 * calls `CmsMediaPicker.bind(root)` on every form it enhances, a section added
 * since the page loaded included.
 */
(function () {
    'use strict';

    var m = {};
    var picker = null;

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

    function backOfficeToken() {
        var meta = document.querySelector('meta[name="bo-token"]');

        return meta ? meta.getAttribute('content') || '' : '';
    }

    function withParameters(url, parameters) {
        var address = new URL(url, window.location.href);
        var language = new URL(window.location.href).searchParams.get('edit_language_id');

        if (language) {
            address.searchParams.set('edit_language_id', language);
        }

        Object.keys(parameters).forEach(function (key) {
            if (parameters[key] !== '' && parameters[key] !== null && parameters[key] !== undefined) {
                address.searchParams.set(key, parameters[key]);
            }
        });

        return address.toString();
    }

    function getJson(url) {
        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) { return response.ok ? response.json() : null; })
            .catch(function () { return null; });
    }

    function badge(item) {
        // A video has nothing to describe: no badge.
        if (item.video) {
            return null;
        }

        if (item.decorative) {
            return element('span', 'badge text-bg-light', m.decorative);
        }

        return item.described
            ? element('span', 'badge text-bg-success', m.described)
            : element('span', 'badge text-bg-warning', m.toDescribe);
    }

    // The dialog of the library, one for the screen.
    function thePicker() {
        if (picker) {
            return picker;
        }

        var dialog = element('dialog', 'border-0 rounded shadow p-0');
        dialog.setAttribute('data-cms-media-picker', '');
        dialog.setAttribute('aria-labelledby', 'cms-media-picker-title');
        dialog.style.width = 'min(1100px, calc(100vw - 32px))';

        var body = element('div', 'p-3 d-flex flex-column gap-3');
        var head = element('div', 'd-flex justify-content-between align-items-center');
        var title = element('h2', 'h5 mb-0', m.title);
        title.id = 'cms-media-picker-title';
        var close = element('button', 'btn-close');
        close.type = 'button';
        close.setAttribute('aria-label', m.cancel);
        head.append(title, close);

        var tools = element('div', 'row g-2 align-items-end');
        var filterColumn = null;
        var search = element('input', 'form-control');
        search.type = 'search';
        search.placeholder = m.search;
        search.setAttribute('aria-label', m.search);
        var filter = element('select', 'form-select');
        filter.setAttribute('aria-label', m.show);
        [['', m.every], ['to-describe', m.toDescribe], ['decorative', m.decorative]].forEach(function (pair) {
            var option = element('option', '', pair[1]);
            option.value = pair[0];
            filter.appendChild(option);
        });
        // What to list: both kinds, or one. It opens on what the field takes.
        var type = element('select', 'form-select');
        type.setAttribute('aria-label', m.type);
        type.setAttribute('data-cms-media-picker-kind', '');
        [['all', m.kindAll], ['image', m.kindImage], ['video', m.kindVideo]].forEach(function (pair) {
            var option = element('option', '', pair[1]);
            option.value = pair[0];
            type.appendChild(option);
        });
        var tag = element('select', 'form-select');
        tag.setAttribute('aria-label', m.tag);
        tag.setAttribute('data-cms-media-picker-tag', '');
        // The tag filter is always there: with no tag in the library it is disabled and says how to give one.
        var tagHint = element('div', 'form-text', m.noTags);
        tagHint.hidden = true;
        [[search, 'col-12 col-md'], [type, 'col-6 col-md-2'], [filter, 'col-6 col-md-2'], [tag, 'col-6 col-md-3']].forEach(function (pair) {
            var col = element('div', pair[1]);
            col.appendChild(pair[0]);
            tools.appendChild(col);

            if (pair[0] === filter) {
                filterColumn = col;
            }

            if (pair[0] === tag) {
                col.appendChild(tagHint);
            }
        });

        var status = element('p', 'small text-body-secondary mb-0');
        // A file sent from here and not added says so apart from the grey line
        // of the grid, which the next search rewrites.
        var problem = element('div', 'alert alert-warning small py-2 mb-0');
        problem.setAttribute('role', 'alert');
        problem.setAttribute('data-cms-media-picker-problem', '');
        problem.hidden = true;

        function warn(text) {
            problem.replaceChildren(element('i', 'bi bi-exclamation-triangle-fill me-2'), document.createTextNode(text));
            problem.hidden = false;
        }
        var grid = element('div', 'row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-6 g-2');
        grid.setAttribute('data-cms-media-picker-grid', '');
        grid.style.maxHeight = '60vh';
        grid.style.overflowY = 'auto';

        var foot = element('div', 'd-flex flex-wrap justify-content-between align-items-center gap-2');
        var pages = element('div', 'd-flex align-items-center gap-2');
        var previous = element('button', 'btn btn-sm btn-outline-secondary', m.previous);
        previous.type = 'button';
        var position = element('span', 'small text-body-secondary');
        var next = element('button', 'btn btn-sm btn-outline-secondary', m.next);
        next.type = 'button';
        pages.append(previous, position, next);

        var actions = element('div', 'd-flex align-items-center gap-2');
        var upload = element('label', 'btn btn-sm btn-outline-primary mb-0');
        upload.setAttribute('data-cms-media-picker-upload', '');
        var file = element('input', 'd-none');
        file.type = 'file';
        file.accept = 'image/jpeg,image/png,image/webp,image/svg+xml,.svg';
        var uploadText = document.createTextNode(m.upload);
        upload.append(element('i', 'bi bi-upload me-1'), uploadText, file);
        var cancel = element('button', 'btn btn-sm btn-outline-secondary', m.cancel);
        cancel.type = 'button';
        actions.append(upload, cancel);
        foot.append(pages, actions);

        body.append(head, tools, problem, status, grid, foot);
        dialog.appendChild(body);
        document.body.appendChild(dialog);

        // An SVG is prepared before it joins the library: its screen opens in
        // a frame over the picker, and the page that ends it hands back the
        // image added (or why there is none) by a message. Only a message of
        // this origin, from this frame, is read.
        var preparation = element('dialog', 'border-0 rounded shadow p-0');
        preparation.setAttribute('data-cms-media-picker-preparation', '');
        preparation.setAttribute('aria-label', m.prepare || '');
        preparation.style.width = 'calc(100vw - 32px)';
        preparation.style.height = 'calc(100vh - 32px)';
        preparation.style.maxWidth = 'none';
        preparation.style.maxHeight = 'none';
        var frame = element('iframe', 'w-100 h-100 border-0 d-block');
        frame.title = m.prepare || '';
        preparation.appendChild(frame);
        document.body.appendChild(preparation);

        function endPreparation() {
            preparation.close();
            frame.src = 'about:blank';
        }

        preparation.addEventListener('cancel', function () {
            frame.src = 'about:blank';
        });

        window.addEventListener('message', function (event) {
            var answer = event.data;

            if (event.origin !== window.location.origin || event.source !== frame.contentWindow || !answer || answer.type !== 'cms-svg-picked') {
                return;
            }

            endPreparation();

            if (!answer.image) {
                // Refused or expired, the frame says why; left out by the
                // editor, it is no error.
                if (answer.message) {
                    warn(answer.message);
                } else {
                    status.textContent = m.notAdded;
                }

                return;
            }

            getJson(withParameters(state.endpoint, { id: answer.image })).then(function (one) {
                if (one && one.items && one.items[0]) {
                    pick(one.items[0]);
                }
            });
        });

        var state = { page: 1, pageCount: 1, current: 0, choose: null, endpoint: '', uploadUrl: '', kind: 'image' };

        function isVideo() {
            return state.kind === 'video';
        }

        // What the list shows, the field's kind being only what can be chosen.
        function shown() {
            return type.value || state.kind;
        }

        function asked(parameters) {
            parameters.kind = shown();

            return parameters;
        }
        var typing = null;

        function load() {
            status.textContent = m.loading;
            getJson(withParameters(state.endpoint, asked({ search: search.value.trim(), filter: shown() === 'video' ? '' : filter.value, tag: tag.value, page: state.page })))
                .then(function (answer) {
                    if (!answer) {
                        status.textContent = m.failed;

                        return;
                    }

                    state.page = answer.page;
                    state.pageCount = answer.pageCount;
                    status.textContent = ((shown() === 'video' ? m.videoCount : (shown() === 'all' ? m.fileCount : m.count)) || '').replace('%count%', String(answer.total));
                    position.textContent = (m.page || '').replace('%page%', String(answer.page)).replace('%pages%', String(answer.pageCount));
                    previous.disabled = answer.page <= 1;
                    next.disabled = answer.page >= answer.pageCount;
                    fillTags(answer.tags || {});
                    fillGrid(answer.items || []);
                });
        }

        function fillTags(tags) {
            var chosen = tag.value;
            tag.replaceChildren();
            var every = element('option', '', m.everyTag);
            every.value = '';
            tag.appendChild(every);
            tag.disabled = Object.keys(tags).length === 0;
            tagHint.hidden = !tag.disabled;
            Object.keys(tags).forEach(function (name) {
                var option = element('option', '', name + ' (' + tags[name] + ')');
                option.value = name;
                option.selected = name === chosen;
                tag.appendChild(option);
            });
        }

        function fillGrid(items) {
            grid.replaceChildren();

            if (items.length === 0) {
                grid.appendChild(element('p', 'text-body-secondary small', shown() === 'video' ? m.videoNone : (shown() === 'all' ? m.noFile : m.none)));

                return;
            }

            items.forEach(function (item) {
                var col = element('div', 'col');
                var tile = element('button', 'btn p-0 w-100 text-start border rounded overflow-hidden bg-body');
                tile.type = 'button';
                tile.setAttribute('data-cms-media-picker-item', String(item.id));

                // A field takes one kind of file: the other is listed, to be found, and cannot be chosen.
                if (Boolean(item.video) !== isVideo()) {
                    tile.disabled = true;
                    tile.classList.add('opacity-50');
                    tile.title = isVideo() ? m.takesVideo : m.takesImage;
                }

                if (item.id === state.current) {
                    tile.classList.add('border-primary', 'border-2');
                    tile.setAttribute('aria-current', 'true');
                }

                var frame = element('div', 'ratio ratio-4x3 bg-body-secondary');

                if (item.video) {
                    // The file itself, its first frame: nothing is played, nothing but the head of it is loaded.
                    var clip = element('video');
                    clip.preload = 'metadata';
                    clip.muted = true;
                    clip.setAttribute('playsinline', '');
                    clip.src = item.url;
                    clip.style.objectFit = 'scale-down';
                    frame.appendChild(clip);
                } else {
                    var image = element('img');
                    image.src = item.thumbnail || item.url;
                    image.alt = '';
                    image.loading = 'lazy';
                    image.style.objectFit = 'scale-down';
                    frame.appendChild(image);
                }

                var label = element('div', 'p-1 small');
                var name = element('div', 'text-truncate', item.title);
                name.title = item.title;
                var described = badge(item);
                label.append(name);

                if (described) {
                    label.append(described);
                }

                tile.append(frame, label);
                tile.addEventListener('click', function () { pick(item); });
                col.appendChild(tile);
                grid.appendChild(col);
            });
        }

        function pick(item) {
            if (state.choose) {
                state.choose(item);
            }

            dialog.close();
        }

        search.addEventListener('input', function () {
            window.clearTimeout(typing);
            typing = window.setTimeout(function () { state.page = 1; load(); }, 250);
        });
        type.addEventListener('change', function () {
            // Images are what the "Show" filter speaks of: a list of videos has no use for it.
            filterColumn.hidden = shown() === 'video';
            if (shown() === 'video') { filter.value = ''; }
            tag.value = '';
            state.page = 1;
            load();
        });
        [filter, tag].forEach(function (select) {
            select.addEventListener('change', function () { state.page = 1; load(); });
        });
        previous.addEventListener('click', function () { state.page -= 1; load(); });
        next.addEventListener('click', function () { state.page += 1; load(); });
        close.addEventListener('click', function () { dialog.close(); });
        cancel.addEventListener('click', function () { dialog.close(); });

        // An image sent from here joins the library and is the one chosen.
        file.addEventListener('change', function () {
            var chosen = file.files && file.files[0];

            if (!chosen || !state.uploadUrl) {
                return;
            }

            status.textContent = m.sending;
            problem.hidden = true;
            var body = new FormData();
            body.append('_token', backOfficeToken());
            body.append('files[]', chosen);
            fetch(withParameters(state.uploadUrl, { picker: 1 }), { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: body })
                .then(function (response) { return response.json(); })
                .then(function (answer) {
                    file.value = '';

                    if (answer && answer.prepare) {
                        status.textContent = '';
                        frame.src = answer.prepare;
                        preparation.showModal();

                        return null;
                    }

                    if (answer && answer.stored && answer.stored[0]) {
                        return getJson(withParameters(state.endpoint, asked({ id: answer.stored[0].id }))).then(function (one) {
                            if (one && one.items && one.items[0]) {
                                pick(one.items[0]);
                            }
                        });
                    }

                    status.textContent = '';
                    warn(answer && answer.refused && answer.refused[0]
                        ? (m.refused || '').replace('%name%', answer.refused[0].name).replace('%reason%', answer.refused[0].reason)
                        : m.sendFailed);

                    return null;
                })
                .catch(function () {
                    file.value = '';
                    status.textContent = '';
                    warn(m.sendFailed);
                });
        });

        picker = {
            open: function (options) {
                var kind = options.kind === 'video' ? 'video' : 'image';

                // Another kind of file is another list: what was searched or filtered for the one does not carry over.
                if (kind !== state.kind) {
                    search.value = '';
                    filter.value = '';
                    tag.value = '';
                }

                // It opens on what the field takes, whatever the last list was narrowed to.
                type.value = kind;

                state.kind = kind;
                title.textContent = isVideo() ? (m.videoTitle || m.title) : m.title;
                uploadText.nodeValue = isVideo() ? (m.videoUpload || m.upload) : m.upload;
                file.accept = isVideo() ? 'video/mp4' : 'image/jpeg,image/png,image/webp,image/svg+xml,.svg';
                filterColumn.hidden = shown() === 'video';
                state.endpoint = options.endpoint;
                state.uploadUrl = options.uploadUrl;
                state.current = options.current;
                state.choose = options.choose;
                state.page = 1;
                problem.hidden = true;
                upload.hidden = !options.uploadUrl;
                dialog.showModal();
                load();
                search.focus();
            },
        };

        return picker;
    }

    function bindField(input) {
        input.setAttribute('data-cms-image-bound', '');
        var video = input.getAttribute('data-cms-media-kind') === 'video';
        var endpoint = input.getAttribute('data-cms-image-picker') || input.getAttribute('data-cms-video-picker');
        var uploadUrl = input.getAttribute('data-cms-image-upload') || '';
        var holder = input.closest('.mb-3') || input.parentElement;
        var panel = holder.parentElement;
        var address = panel.querySelector('input[name="' + input.name.replace(/\]$/, '__url]') + '"]');
        var file = panel.querySelector('input[name="' + input.name.replace(/\]$/, '__file]') + '"]');
        // The text of the image the section holds: for a picture kept
        // elsewhere. An image of the library is described in the library, and
        // its text is shown here instead of the field.
        var altField = video ? null : panel.querySelector('input[name="' + input.name.replace(/\]$/, '_alt]') + '"]')
            || panel.querySelector('input[name="' + input.name.replace(/\[[^\[\]]+\]$/, '[alt]') + '"]');
        var altRow = altField ? (altField.closest('.mb-3') || altField.parentElement) : null;
        var known = {};

        input.type = 'hidden';

        var block = element('div', 'd-flex flex-wrap align-items-center gap-3 border rounded p-2');
        // The framing and the crop look for `data-cms-image-field`: a video has none to give.
        block.setAttribute(video ? 'data-cms-video-field' : 'data-cms-image-field', '');
        var frame = element('div', 'ratio ratio-4x3 bg-body-secondary rounded overflow-hidden flex-shrink-0');
        frame.style.width = '120px';
        var thumbnail = element('img');
        thumbnail.alt = '';
        thumbnail.style.objectFit = 'scale-down';
        // A video is shown as its first frame, in a <video>: an <img> on its address shows nothing.
        var clip = element('video');
        clip.setAttribute('preload', 'metadata');
        clip.muted = true;
        clip.setAttribute('playsinline', '');
        clip.style.objectFit = 'scale-down';
        clip.hidden = true;
        var empty = element('div', 'align-items-center justify-content-center text-body-secondary');
        empty.appendChild(element('i', video ? 'bi bi-camera-video fs-2' : 'bi bi-image fs-2'));
        frame.append(video ? clip : thumbnail, empty);
        var info = element('div', 'flex-grow-1 small');
        info.style.minWidth = '10rem';
        var name = element('div', 'fw-medium text-break');
        var state = element('div');
        var alt = element('div', 'text-body-secondary mt-1');
        alt.setAttribute('data-cms-image-alt', '');
        info.append(name, state, alt);
        var buttons = element('div', 'd-flex flex-wrap gap-2');
        var choose = element('button', 'btn btn-sm btn-primary', m.choose);
        choose.type = 'button';
        choose.setAttribute('data-cms-image-choose', '');
        var remove = element('button', 'btn btn-sm btn-outline-secondary', m.remove);
        remove.type = 'button';
        remove.setAttribute('data-cms-image-remove', '');
        buttons.append(choose, remove);
        block.append(frame, info, buttons);
        input.insertAdjacentElement('afterend', block);

        // The library's own text, or a way to write it.
        function describe(item) {
            alt.replaceChildren();

            if (altRow) {
                altRow.hidden = !!item;
            }

            // A video has nothing to describe: no text, no link to write one.
            if (!item || item.decorative || item.video) {
                return;
            }

            if (item.alt) {
                alt.textContent = (m.altShown || '%alt%').replace('%alt%', item.alt);

                return;
            }

            var link = element('a', '', m.describeIt);
            link.href = endpoint.replace(/\/picker$/, '/' + item.id);
            link.target = '_blank';
            link.rel = 'noopener';
            alt.appendChild(link);

            if (altField && altField.value.trim() !== '') {
                alt.appendChild(document.createTextNode(' · ' + (m.meanwhile || '').replace('%alt%', altField.value.trim())));
            }
        }

        function show(url, title, label) {
            var shown = video ? clip : thumbnail;
            shown.hidden = !url;
            // Shown as a flex box only when there is no picture: `hidden`
            // loses to a display class, a style does not.
            empty.style.display = url ? 'none' : 'flex';

            if (url) {
                shown.src = url;
            } else if (video) {
                clip.removeAttribute('src');
            }

            name.textContent = title || (video ? m.noVideo : m.noImage);
            state.replaceChildren();

            if (label) {
                state.appendChild(label);
            }

            remove.hidden = !url;
        }

        // What the field shows, for the dialog that frames the image: where it
        // comes from, the whole file and its size when the library knows it.
        function announce(source, url, width, height) {
            if (video) {
                return;
            }

            block.setAttribute('data-cms-image-source', source);
            block.setAttribute('data-cms-image-url', url || '');
            block.setAttribute('data-cms-image-width', width ? String(width) : '');
            block.setAttribute('data-cms-image-height', height ? String(height) : '');
            block.dispatchEvent(new CustomEvent('cms-image-shown', { bubbles: true }));
        }

        function render() {
            if (address && address.value.trim() !== '') {
                show(address.value.trim(), address.value.trim(), element('span', 'badge text-bg-light', m.external));
                describe(null);
                announce('external', address.value.trim(), 0, 0);

                return;
            }

            if (file && file.files && file.files[0]) {
                var local = URL.createObjectURL(file.files[0]);
                show(local, file.files[0].name, element('span', 'badge text-bg-info', m.newFile));
                describe(null);
                announce('file', local, 0, 0);

                return;
            }

            var id = parseInt(input.value || '0', 10);

            if (!id) {
                show(null, '', null);
                describe(null);
                announce('none', '', 0, 0);

                return;
            }

            if (known[id]) {
                show(video ? known[id].url : (known[id].thumbnail || known[id].url), known[id].title, badge(known[id]));
                describe(known[id]);
                announce('library', known[id].url, known[id].width, known[id].height);

                return;
            }

            show(null, '…', null);
            announce('loading', '', 0, 0);
            getJson(withParameters(endpoint, video ? { id: id, kind: 'video' } : { id: id })).then(function (answer) {
                if (answer && answer.items && answer.items[0]) {
                    known[id] = answer.items[0];
                    render();
                } else {
                    show(null, video ? m.goneVideo : m.gone, null);
                    announce('gone', '', 0, 0);
                }
            });
        }

        choose.addEventListener('click', function () {
            thePicker().open({
                endpoint: endpoint,
                kind: video ? 'video' : 'image',
                uploadUrl: uploadUrl,
                current: parseInt(input.value || '0', 10),
                choose: function (item) {
                    known[item.id] = item;
                    input.value = String(item.id);

                    if (address) { address.value = ''; }
                    if (file) { file.value = ''; }

                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    render();
                },
            });
        });

        remove.addEventListener('click', function () {
            input.value = '';

            if (address) { address.value = ''; }
            if (file) { file.value = ''; }

            input.dispatchEvent(new Event('change', { bubbles: true }));
            render();
        });

        if (address) { address.addEventListener('input', render); }
        if (file) { file.addEventListener('change', render); }

        render();
    }

    function bind(root) {
        var source = document.querySelector('script[data-cms-media-picker-messages]');

        if (source && Object.keys(m).length === 0) {
            try {
                m = JSON.parse(source.textContent);
            } catch (error) {
                m = {};
            }
        }

        (root || document).querySelectorAll('input[data-cms-image-picker]:not([data-cms-image-bound]), input[data-cms-video-picker]:not([data-cms-image-bound])').forEach(bindField);
    }

    window.CmsMediaPicker = { bind: bind };

    // A screen without the content editor, the settings of a page: bound
    // once loaded. A field already bound is left alone.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { bind(document); });
    } else {
        bind(document);
    }
})();
