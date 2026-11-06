/*
 * Sending images to the media library of the CMS.
 *
 * The form of the list posts its files at once without JavaScript. With it,
 * the form is also a place to drop files on, and each file goes on its own:
 * a row per file shows its progress, then "added" or why it was refused, and
 * one refused file costs none of the others. The format and the size are
 * checked here first, so a file the server would refuse is not sent; the
 * server checks again.
 *
 * An SVG is not stored by its sending: the server keeps it apart under a
 * token, and the row says it is to prepare. Once every file is answered, the
 * preparation screen opens on the first of them, the others and the images
 * already added behind it in the address; without SVG, the describe queue
 * opens on the images just added. When a file was refused, the screen stays,
 * so the editor reads why, and offers the way on.
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

    function bind(form) {
        var input = form.querySelector('input[type="file"]');
        var list = form.querySelector('[data-cms-media-upload-files]');
        var done = form.querySelector('[data-cms-media-upload-done]');
        var token = form.querySelector('input[name="_token"]');
        var accepted = (form.getAttribute('data-accepted') || '').split(',');
        var videoAccepted = (form.getAttribute('data-video-accepted') || '').split(',');
        // Two ceilings, each already bounded by the limit of PHP: an image
        // and a video do not weigh the same.
        var maxSize = parseInt(form.getAttribute('data-max-size') || '0', 10);
        var maxVideoSize = parseInt(form.getAttribute('data-max-video-size') || '0', 10);
        var describeUrl = form.getAttribute('data-describe-url');
        var prepareUrl = form.getAttribute('data-prepare-url');
        var placeholder = form.getAttribute('data-token-placeholder');
        var m = {};

        // Words the page could not hand over leave the screen working, wordless.
        try {
            m = JSON.parse(form.getAttribute('data-messages') || '{}');
        } catch (error) {
            m = {};
        }

        var busy = false;

        // The form is sent by the script now, not on change.
        input.removeAttribute('onchange');
        input.addEventListener('change', function () {
            send(Array.prototype.slice.call(input.files));
            input.value = '';
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            send(Array.prototype.slice.call(input.files));
            input.value = '';
        });

        ['dragenter', 'dragover'].forEach(function (type) {
            form.addEventListener(type, function (event) {
                event.preventDefault();
                form.classList.add('border-primary', 'bg-body-secondary');
            });
        });

        ['dragleave', 'drop'].forEach(function (type) {
            form.addEventListener(type, function (event) {
                event.preventDefault();
                form.classList.remove('border-primary', 'bg-body-secondary');
            });
        });

        form.addEventListener('drop', function (event) {
            send(Array.prototype.slice.call(event.dataTransfer ? event.dataTransfer.files : []));
        });

        // Some systems give an SVG file no type: its name then decides.
        function isAccepted(file) {
            return accepted.indexOf(file.type) !== -1
                || (accepted.indexOf('.svg') !== -1 && /\.svg$/i.test(file.name));
        }

        function refusalOf(file) {
            if (!isAccepted(file)) {
                return m.format;
            }

            var video = videoAccepted.indexOf(file.type) !== -1;

            if (video && maxVideoSize > 0 && file.size > maxVideoSize) {
                return m.tooLargeVideo;
            }

            if (!video && maxSize > 0 && file.size > maxSize) {
                return m.tooLarge;
            }

            return null;
        }

        function row(file) {
            var item = element('li', 'd-flex align-items-center gap-2 py-1');
            item.setAttribute('data-cms-media-upload-file', '');
            var name = element('span', 'text-truncate', file.name);
            name.style.maxWidth = '40%';
            var bar = element('div', 'progress flex-grow-1');
            bar.style.height = '6px';
            var fill = element('div', 'progress-bar');
            fill.style.width = '0%';
            bar.appendChild(fill);
            var status = element('span', 'text-nowrap text-body-secondary', m.sending);
            item.append(name, bar, status);
            list.appendChild(item);

            return {
                progress: function (ratio) { fill.style.width = Math.round(ratio * 100) + '%'; },
                prepared: function () {
                    fill.style.width = '100%';
                    fill.classList.add('bg-warning');
                    status.className = 'text-nowrap text-warning-emphasis';
                    status.textContent = m.prepared;
                },
                added: function () {
                    fill.style.width = '100%';
                    fill.classList.add('bg-success');
                    status.className = 'text-nowrap text-success';
                    status.textContent = m.added;
                },
                refused: function (reason) {
                    bar.remove();
                    status.className = 'text-danger';
                    status.textContent = reason;
                    item.setAttribute('data-cms-media-upload-refused', '');
                },
            };
        }

        // One request per file, one after the other: the rows fill in the
        // order the files were given, and a large sending never opens tens
        // of connections at once.
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
                        resolve({ id: answer.stored[0].id, video: answer.stored[0].video === true });

                        return;
                    }

                    if (request.status === 200 && answer && answer.prepared && answer.prepared.length > 0) {
                        view.prepared();
                        resolve({ token: answer.prepared[0].token });

                        return;
                    }

                    view.refused(answer && answer.refused && answer.refused[0] ? answer.refused[0].reason : m.failed);
                    resolve(null);
                });
                request.addEventListener('error', function () {
                    view.refused(m.failed);
                    resolve(null);
                });
                request.send(body);
            });
        }

        function send(files) {
            if (busy || files.length === 0) {
                return;
            }

            busy = true;
            list.classList.remove('d-none');
            done.classList.add('d-none');
            done.replaceChildren();

            var stored = [];
            var prepared = [];
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

                    return upload(file, view).then(function (answer) {
                        if (answer && answer.token) {
                            prepared.push(answer.token);
                        } else if (answer) {
                            stored.push(answer);
                        } else {
                            refused++;
                        }
                    });
                });
            }, Promise.resolve()).then(function () {
                finish(stored, prepared, refused);
            }).finally(function () {
                // Whatever happened, the next drop is taken.
                busy = false;
            });
        }

        // The address of the preparation screen of the first SVG: the others
        // and the images added so far follow it, to be taken up at its end.
        function prepareQueue(stored, prepared) {
            var url = prepareUrl.replace(placeholder, prepared[0]);

            if (prepared.length > 1) {
                url += '&queue=' + prepared.slice(1).join(',') + '&of=' + prepared.length;
            }

            if (stored.length > 0) {
                url += '&images=' + stored.join(',');
            }

            return url;
        }

        // A message in the two forms of the translation files, "one|many",
        // with %count% filled: the first form for one item, the second for more.
        function counted(message, count) {
            var forms = (message || '').split('|');

            return (count === 1 || forms.length === 1 ? forms[0] : forms[1]).replace('%count%', String(count));
        }

        function finish(files, prepared, refused) {
            if (files.length === 0 && prepared.length === 0) {
                return;
            }

            // A video has nothing to describe: only the images join the queues.
            var stored = files.filter(function (file) { return !file.video; }).map(function (file) { return file.id; });

            if (prepared.length > 0) {
                var prepare = prepareQueue(stored, prepared);

                if (refused === 0) {
                    window.location.assign(prepare);

                    return;
                }

                // The images of a mixed sending are stored already: the queue
                // comes to them after the SVG files, and leaving drops only
                // the SVG files, which wait under their token.
                var go = element('a', 'btn btn-sm btn-primary', counted(m.prepare, prepared.length) + (stored.length > 0 ? counted(m.thenDescribe, stored.length) : ''));
                go.href = prepare;
                go.setAttribute('data-cms-media-upload-prepare', '');
                var remain = element('a', 'btn btn-sm btn-outline-secondary', counted(m.dropSvg, prepared.length));
                remain.href = window.location.href;
                done.append(go, remain);
                done.classList.remove('d-none');

                return;
            }

            // Only videos: nothing to describe, the list shows them.
            if (stored.length === 0) {
                if (refused === 0) {
                    window.location.reload();

                    return;
                }

                var back = element('a', 'btn btn-sm btn-outline-secondary', m.stay);
                back.href = window.location.href;
                done.append(back);
                done.classList.remove('d-none');

                return;
            }

            var queue = describeUrl + (describeUrl.indexOf('?') === -1 ? '?' : '&') + 'images=' + stored.join(',');

            if (refused === 0) {
                window.location.assign(queue);

                return;
            }

            var describe = element('a', 'btn btn-sm btn-primary', counted(m.describe, stored.length));
            describe.href = queue;
            describe.setAttribute('data-cms-media-upload-describe', '');
            // The images stay in the list, to describe.
            var stay = element('a', 'btn btn-sm btn-outline-secondary', m.describeLater);
            stay.href = window.location.href;
            done.append(describe, stay);
            done.classList.remove('d-none');
        }
    }

    function start() {
        document.querySelectorAll('form[data-cms-media-upload]').forEach(bind);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
