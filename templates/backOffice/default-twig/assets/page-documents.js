/*
 * The Documents tab of the edit screen of a page.
 *
 * Without this script the tab works with plain forms: files are sent in one
 * request, every action redirects back to the tab. With it:
 * - files go one by one, a row per file shows its progress, then "attached" or
 *   why it was refused, so one refused file costs none of the others;
 * - the format and the size are checked before sending, the server checks again;
 * - the tab opens when the address ends with its anchor (where every action
 *   redirects to);
 * - the deletion dialog opens on browsers that do not know the `command`
 *   attribute of buttons.
 */
(function () {
    'use strict';

    var TAB = '#tab-documents';

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

    function bindUpload(form) {
        var input = form.querySelector('input[type="file"]');
        var list = form.querySelector('[data-cms-document-upload-files]');
        var done = form.querySelector('[data-cms-document-upload-done]');
        var token = form.querySelector('input[name="_token"]');
        var extensions = (form.getAttribute('data-extensions') || '').split(',');
        var maxSize = parseInt(form.getAttribute('data-max-size') || '0', 10);
        var messages = JSON.parse(form.getAttribute('data-messages') || '{}');
        var busy = false;

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            send(Array.prototype.slice.call(input.files));
            input.value = '';
        });

        function refusalOf(file) {
            var extension = (file.name.split('.').pop() || '').toLowerCase();

            if (file.name.indexOf('.') === -1 || extensions.indexOf(extension) === -1) {
                return messages.format;
            }

            if (maxSize > 0 && file.size > maxSize) {
                return messages.tooLarge;
            }

            return null;
        }

        function row(file) {
            var item = element('li', 'd-flex align-items-center gap-2 py-1');
            item.setAttribute('data-cms-document-upload-file', '');
            var name = element('span', 'text-truncate', file.name);
            name.style.maxWidth = '40%';
            var bar = element('div', 'progress flex-grow-1');
            bar.style.height = '6px';
            bar.setAttribute('role', 'progressbar');
            bar.setAttribute('aria-label', file.name);
            bar.setAttribute('aria-valuemin', '0');
            bar.setAttribute('aria-valuemax', '100');
            bar.setAttribute('aria-valuenow', '0');
            var fill = element('div', 'progress-bar');
            fill.style.width = '0%';
            bar.appendChild(fill);
            var status = element('span', 'text-nowrap text-body-secondary', messages.sending);
            item.append(name, bar, status);
            list.appendChild(item);

            return {
                progress: function (ratio) {
                    var percent = Math.round(ratio * 100);
                    fill.style.width = percent + '%';
                    bar.setAttribute('aria-valuenow', String(percent));
                },
                added: function () {
                    fill.style.width = '100%';
                    bar.setAttribute('aria-valuenow', '100');
                    fill.classList.add('bg-success');
                    status.className = 'text-nowrap text-success';
                    status.textContent = messages.added;
                },
                refused: function (reason) {
                    bar.remove();
                    status.className = 'text-danger';
                    status.textContent = reason;
                    item.setAttribute('data-cms-document-upload-refused', '');
                },
            };
        }

        function upload(file, view) {
            return new Promise(function (resolve) {
                var body = new FormData();
                body.append('_token', token ? token.value : '');
                body.append('files[]', file);

                var request = new XMLHttpRequest();
                request.open('POST', form.action);
                request.setRequestHeader('Accept', 'application/json');
                request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                request.upload.addEventListener('progress', function (event) {
                    if (event.lengthComputable) {
                        view.progress(event.loaded / event.total);
                    }
                });
                request.addEventListener('load', function () {
                    var answer = null;

                    try {
                        answer = JSON.parse(request.responseText);
                    } catch (error) {
                        answer = null;
                    }

                    if (request.status === 200 && answer && answer.stored && answer.stored.length > 0) {
                        view.added();
                        resolve(true);

                        return;
                    }

                    view.refused(answer && answer.refused && answer.refused[0] ? answer.refused[0].reason : messages.failed);
                    resolve(false);
                });
                request.addEventListener('error', function () {
                    view.refused(messages.failed);
                    resolve(false);
                });
                request.send(body);
            });
        }

        function reload() {
            window.location.hash = TAB.slice(1);
            window.location.reload();
        }

        function send(files) {
            if (busy || files.length === 0) {
                return;
            }

            busy = true;
            done.classList.add('d-none');
            done.replaceChildren();

            var stored = 0;
            var refused = 0;

            files.reduce(function (previous, file) {
                return previous.then(function () {
                    var view = row(file);
                    var refusal = refusalOf(file);

                    if (refusal) {
                        view.refused(refusal);
                        refused++;

                        return null;
                    }

                    return upload(file, view).then(function (ok) {
                        if (ok) {
                            stored++;
                        } else {
                            refused++;
                        }
                    });
                });
            }, Promise.resolve()).then(function () {
                busy = false;

                if (stored > 0 && refused === 0) {
                    reload();

                    return;
                }

                if (stored > 0) {
                    var show = element('button', 'btn btn-sm btn-primary', messages.show);
                    show.type = 'button';
                    show.setAttribute('data-cms-document-upload-show', '');
                    show.addEventListener('click', reload);
                    done.append(show);
                    done.classList.remove('d-none');
                }
            });
        }
    }

    function start() {
        document.querySelectorAll('form[data-cms-document-upload]').forEach(bindUpload);

        var button = document.querySelector('[data-bs-target="' + TAB + '"]');

        // Once every script is loaded: the click is Bootstrap's to answer.
        function openTab() {
            if (button && window.location.hash === TAB) {
                button.click();
            }
        }

        if (document.readyState === 'complete') {
            openTab();
        } else {
            window.addEventListener('load', openTab);
        }

        if (!('commandForElement' in HTMLButtonElement.prototype)) {
            document.querySelectorAll('[data-cms-document-dialog-open]').forEach(function (opener) {
                opener.addEventListener('click', function () {
                    var dialog = document.getElementById(opener.getAttribute('commandfor'));

                    if (dialog && typeof dialog.showModal === 'function') {
                        dialog.showModal();
                    }
                });
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
