/*
 * The screens of the components the site makes, in the Thelia CMS back
 * office: a request posted in the background, its progress listed under the
 * form as the generator writes, then the screen of the candidate.
 *
 * Progressive enhancement: without this script, or without streams, the form
 * is posted as is and the page waits for the answer.
 */
(function () {
    "use strict";

    /**
     * Reads a stream of server-sent events and calls back for each one, the
     * way the content screen does: EventSource only sends GET requests.
     */
    function readEvents(response, onEvent) {
        var reader = response.body.getReader();
        var decoder = new TextDecoder();
        var buffer = "";

        function handle(chunk) {
            var name = "message";
            var data = "";

            chunk.split("\n").forEach(function (line) {
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

    document.querySelectorAll("form[data-cms-component-generate]").forEach(function (form) {
        var steps = form.querySelector("[data-cms-component-steps]");
        var button = form.querySelector("button[type=submit]");
        var busy = form.querySelector("[data-busy]");
        var labels = JSON.parse(form.getAttribute("data-cms-component-generate") || "{}");

        function addStep(message, kind) {
            var item = document.createElement("li");
            item.textContent = message;

            if (kind) {
                item.className = kind;
            }

            steps.appendChild(item);

            return item;
        }

        function stop(message) {
            button.disabled = false;
            busy.hidden = true;
            addStep(message, "text-danger");
        }

        form.addEventListener("submit", function (event) {
            if (!window.fetch || !window.ReadableStream) {
                return;
            }

            event.preventDefault();
            button.disabled = true;
            busy.hidden = false;
            steps.hidden = false;
            steps.replaceChildren();

            var finished = false;
            var delta = null;

            fetch(form.action, {
                method: "POST",
                body: new FormData(form),
                credentials: "same-origin",
                headers: { "Accept": "text/event-stream", "X-Requested-With": "XMLHttpRequest" }
            }).then(function (response) {
                if (!response.ok || (response.headers.get("Content-Type") || "").indexOf("text/event-stream") === -1) {
                    throw new Error((labels.failed || "%status%").replace("%status%", response.status));
                }

                return readEvents(response, function (name, data) {
                    if (name === "start" || name === "turn") {
                        delta = null;
                        addStep(data.message);
                    } else if (name === "delta") {
                        // One line that counts up rather than a list that scrolls.
                        delta = delta || addStep("");
                        delta.textContent = data.message;
                    } else if (name === "correction" || name === "cut") {
                        delta = null;
                        addStep(data.message, "text-warning-emphasis");
                    } else if (name === "done" || (name === "error" && data.redirect)) {
                        finished = true;
                        window.location.assign(data.redirect);
                    } else if (name === "error") {
                        finished = true;
                        stop(data.message);
                    }
                });
            }).then(function () {
                if (!finished) {
                    stop(labels.network || "");
                }
            }).catch(function (error) {
                stop(error.message || labels.network || "");
            });
        });
    });
})();
