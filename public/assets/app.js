(function () {
    // --- Job Streaming ---
    const streamTarget = document.querySelector("[data-job-stream]");
    if (streamTarget) {
        const url = streamTarget.getAttribute("data-job-stream");
        if (url) {
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
                if (!logList) return;
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
        }
    }

    // --- UI Utilities (Project Detail) ---
    window.selectBranchForScan = function(branchName, targetVersion, fromVersion) {
        const form = document.getElementById('scan-form');
        if (!form) return;

        form.querySelector('select[name=branch_name]').value = branchName;
        
        if (targetVersion) document.getElementById('target_core_version').value = targetVersion;
        if (fromVersion) document.getElementById('from_core_version').value = fromVersion;
        else if (fromVersion === '') document.getElementById('from_core_version').value = '';

        const panel = document.getElementById('scan-form-panel');
        if (panel) {
            panel.scrollIntoView({ behavior: 'smooth' });
            panel.style.transition = 'background-color 0.3s ease';
            const originalBg = panel.style.backgroundColor;
            panel.style.backgroundColor = 'rgba(11, 110, 79, 0.1)';
            setTimeout(() => {
                panel.style.backgroundColor = originalBg;
            }, 1000);
        }
    };

    
})();