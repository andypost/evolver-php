(function () {
    const streamTarget = document.querySelector("[data-job-stream]");
    if (!streamTarget) {
        return;
    }

    const url = streamTarget.getAttribute("data-job-stream");
    if (!url) {
        return;
    }

    const statusNode = document.querySelector("[data-run-status]");
    const progressNode = document.querySelector("[data-job-progress]");
    const progressTextNode = document.querySelector("[data-job-progress-text]");
    const labelNode = document.querySelector("[data-job-label]");
    const logList = document.querySelector("[data-job-logs]");

    const source = new EventSource(url);

    source.addEventListener("job", function (event) {
        const payload = JSON.parse(event.data);
        if (statusNode) {
            statusNode.textContent = payload.status;
        }
        if (progressNode) {
            progressNode.max = Math.max(1, payload.progress_total || 1);
            progressNode.value = payload.progress_current || 0;
        }
        if (progressTextNode) {
            progressTextNode.textContent = (payload.progress_current || 0) + "/" + (payload.progress_total || 0);
        }
        if (labelNode) {
            labelNode.textContent = payload.progress_label || payload.status;
        }
    });

    source.addEventListener("log", function (event) {
        if (!logList) {
            return;
        }

        const payload = JSON.parse(event.data);
        const item = document.createElement("li");
        item.innerHTML = '<span class="pill">' + payload.level + '</span><span>' + payload.message + "</span>";
        logList.prepend(item);
    });

    source.addEventListener("run_complete", function () {
        window.setTimeout(function () {
            window.location.reload();
        }, 1200);
    });
})();
