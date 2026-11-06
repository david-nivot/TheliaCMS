/*
 * The sheet of an image of the media library of the CMS.
 *
 * - The tags are shown as chips. A tag is added with Enter or a comma and
 *   suggested from the tags the library already has; the field the form
 *   sends stays the comma-separated list it always was.
 * - A file chosen to replace the image is shown before the form is saved,
 *   with its size, next to the current one.
 * - Deleting asks in a dialog of the screen, not with the browser's confirm().
 *
 * Without the script, the fields work as plain fields and the delete button
 * posts at once.
 */
(function () {
    'use strict';

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

    function splitTags(value) {
        return value.split(',').map(function (tag) { return tag.trim(); }).filter(function (tag) { return tag !== ''; });
    }

    function bindTags(field) {
        var known = [];

        try {
            known = JSON.parse(field.getAttribute('data-cms-media-tags') || '[]');
        } catch (error) {
            known = [];
        }

        var tags = splitTags(field.value);
        var box = element('div', 'form-control d-flex flex-wrap gap-1 align-items-center');
        box.setAttribute('data-cms-media-tag-box', '');
        var input = element('input', 'border-0 flex-grow-1');
        input.style.outline = 'none';
        input.style.minWidth = '8rem';
        input.setAttribute('aria-label', field.labels && field.labels[0] ? field.labels[0].textContent.trim() : '');
        var list = element('datalist');
        list.id = field.id + '-suggestions';
        input.setAttribute('list', list.id);
        known.forEach(function (tag) {
            var option = element('option');
            option.value = tag;
            list.appendChild(option);
        });

        function sync() {
            field.value = tags.join(', ');
            box.querySelectorAll('[data-cms-media-tag]').forEach(function (chip) { chip.remove(); });
            tags.forEach(function (tag, index) {
                var chip = element('span', 'badge text-bg-light d-inline-flex align-items-center gap-1', tag);
                chip.setAttribute('data-cms-media-tag', tag);
                var remove = element('button', 'btn-close', '');
                remove.type = 'button';
                remove.style.fontSize = '0.5rem';
                remove.setAttribute('aria-label', (field.getAttribute('data-remove-label') || '×') + ' ' + tag);
                remove.addEventListener('click', function () {
                    tags.splice(index, 1);
                    sync();
                    input.focus();
                });
                chip.appendChild(remove);
                box.insertBefore(chip, input);
            });
        }

        function add(value) {
            splitTags(value).forEach(function (tag) {
                var exists = tags.some(function (kept) { return kept.toLowerCase() === tag.toLowerCase(); });

                if (!exists) {
                    // Written as the library already has it, when it has it.
                    var match = known.filter(function (candidate) { return candidate.toLowerCase() === tag.toLowerCase(); })[0];
                    tags.push(match || tag);
                }
            });
            input.value = '';
            sync();
        }

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ',') {
                event.preventDefault();
                add(input.value);
            } else if (event.key === 'Backspace' && input.value === '' && tags.length > 0) {
                tags.pop();
                sync();
            }
        });

        // A suggestion picked from the list is a tag at once.
        input.addEventListener('change', function () {
            if (known.indexOf(input.value) !== -1) {
                add(input.value);
            }
        });

        // What is still typed when the form is saved is a tag too.
        field.form.addEventListener('submit', function () {
            if (input.value.trim() !== '') {
                add(input.value);
            }
        });

        box.appendChild(input);
        box.appendChild(list);
        field.type = 'hidden';
        field.insertAdjacentElement('afterend', box);
        sync();
    }

    function bindReplace(field) {
        var preview = document.querySelector('[data-cms-media-replace-preview]');

        if (!preview) {
            return;
        }

        var image = preview.querySelector('img');
        var video = preview.querySelector('video');
        var size = preview.querySelector('[data-cms-media-replace-size]');
        var url = null;

        field.addEventListener('change', function () {
            if (url) {
                URL.revokeObjectURL(url);
                url = null;
            }

            var file = field.files && field.files[0];

            if (!file) {
                preview.classList.add('d-none');

                return;
            }

            url = URL.createObjectURL(file);

            // A video is shown by its own element; its size is all that is said.
            if (video && file.type.indexOf('video/') === 0) {
                image.classList.add('d-none');
                video.classList.remove('d-none');
                video.src = url;
                size.textContent = Math.max(1, Math.round(file.size / 1024)) + ' kB';
                preview.classList.remove('d-none');

                return;
            }

            if (video) {
                video.classList.add('d-none');
                image.classList.remove('d-none');
            }

            image.onload = function () {
                size.textContent = image.naturalWidth + ' × ' + image.naturalHeight + ' px · ' + Math.max(1, Math.round(file.size / 1024)) + ' kB';
            };
            image.src = url;
            preview.classList.remove('d-none');
        });
    }

    function bindDelete(form) {
        var dialog = document.querySelector('[data-cms-media-delete-dialog]');

        if (!dialog || typeof dialog.showModal !== 'function') {
            return;
        }

        var confirmed = false;

        form.addEventListener('submit', function (event) {
            if (confirmed) {
                return;
            }

            event.preventDefault();
            dialog.showModal();
        });

        dialog.querySelector('[data-cms-media-delete-cancel]').addEventListener('click', function () {
            dialog.close();
        });

        dialog.querySelector('[data-cms-media-delete-confirm]').addEventListener('click', function () {
            confirmed = true;
            dialog.close();
            form.requestSubmit();
        });
    }

    function start() {
        document.querySelectorAll('input[data-cms-media-tags]').forEach(bindTags);
        document.querySelectorAll('input[data-cms-media-replace]').forEach(bindReplace);
        document.querySelectorAll('form[data-cms-media-delete]').forEach(bindDelete);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
