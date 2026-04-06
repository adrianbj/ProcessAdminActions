$(document).ready(function(){

    // remove scripts, because they've already been executed since we are manipulating the DOM below (WireTabs)
    // which would cause any scripts to get executed twice

    $t = $("#AdminActionsList");
    //$t.find("script").remove();

    $t.WireTabs({
        items: $(".Inputfields li.WireTab"),
        rememberTabs: true
    });

    // Background execution support for action runs
    var config = (typeof ProcessWire !== 'undefined' && ProcessWire.config && ProcessWire.config.adminActions) ? ProcessWire.config.adminActions : null;
    if(!config) return;

    var $form = $("form[name='options']");
    if(!$form.length || !$form.attr('action') || $form.attr('action').indexOf('run?action=') === -1) return;

    var maxExecTime = config.maxExecutionTime || 0;
    var pollUrl = config.pollUrl || '';
    var cleanupUrl = config.cleanupUrl || '';
    var xhrTimeout = maxExecTime > 0 ? Math.floor(maxExecTime * 0.8) * 1000 : 0;

    if(!pollUrl || !cleanupUrl) return;

    $form.on('submit', function(e) {
        e.preventDefault();

        var runId = Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        var formData = new FormData(this);
        formData.append('runId', runId);

        var $submitBtn = $form.find('button[type="submit"], input[type="submit"]');
        var bgStartTime = null;
        var bgTimer = null;
        var pollingActive = false;
        var pollDelivered = false;
        var stopPolling = false;
        var inlineDelivered = false;

        // Create status and result areas
        var $status = $('<div id="adminActionsRunStatus"><i class="fa fa-spinner fa-spin"></i> Running action...</div>');
        var $resultArea = $('<div id="adminActionsRunResult"></div>');
        $form.after($resultArea).after($status).hide();
        $submitBtn.prop('disabled', true);

        function showElapsed() {
            if(!bgStartTime) return;
            var elapsed = Math.round((Date.now() - bgStartTime) / 1000);
            var mins = Math.floor(elapsed / 60);
            var secs = elapsed % 60;
            var timeStr = mins > 0 ? mins + 'm ' + secs + 's' : secs + 's';
            $status.html('<i class="fa fa-spinner fa-spin"></i> Running in background... ' + timeStr);
        }

        function displayResult(html) {
            $status.remove();
            $resultArea.html(html);
            if(bgTimer) { clearInterval(bgTimer); bgTimer = null; }
        }

        function doCleanup(id, returnResult, callback) {
            $.ajax({
                url: cleanupUrl,
                method: 'POST',
                data: { runId: id, returnResult: returnResult ? 1 : 0 },
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                dataType: 'json',
                success: function(data) { if(callback) callback(data); },
                error: function() { if(callback) callback(null); }
            });
        }

        function pollForResults(id) {
            if(stopPolling) return;

            // Safety: stop after 1 hour
            if(bgStartTime && (Date.now() - bgStartTime > 3600000)) {
                if(bgTimer) { clearInterval(bgTimer); bgTimer = null; }
                $status.html('<i class="fa fa-times"></i> Process appears to have been terminated by the server');
                $submitBtn.prop('disabled', false);
                $form.show();
                doCleanup(id, false);
                return;
            }

            $.ajax({
                url: pollUrl + encodeURIComponent(id) + '.json?' + Date.now(),
                method: 'GET',
                dataType: 'json',
                timeout: 10000,
                success: function(data) {
                    if(inlineDelivered) return;
                    if(data.status === 'running') {
                        setTimeout(function() { pollForResults(id); }, 3000);
                        return;
                    }
                    pollDelivered = true;
                    if(bgTimer) { clearInterval(bgTimer); bgTimer = null; }
                    // Fetch full result from cleanup endpoint
                    doCleanup(id, true, function(result) {
                        if(result && result.output) {
                            displayResult(result.output);
                        } else {
                            $status.html('<i class="fa fa-times"></i> Error fetching results');
                            $submitBtn.prop('disabled', false);
                            $form.show();
                        }
                    });
                },
                error: function() {
                    if(!stopPolling) {
                        setTimeout(function() { pollForResults(id); }, 3000);
                    }
                }
            });
        }

        function startPolling() {
            if(pollingActive) return;
            pollingActive = true;
            bgStartTime = Date.now();
            bgTimer = setInterval(showElapsed, 1000);
            stopPolling = false;
            pollForResults(runId);
        }

        // Set up proactive poll timer based on max_execution_time
        var pollTimer = null;
        if(xhrTimeout > 0) {
            pollTimer = setTimeout(function() {
                startPolling();
            }, xhrTimeout);
        }

        // Submit via AJAX
        $.ajax({
            url: $form.attr('action'),
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            success: function(data) {
                if(pollTimer) clearTimeout(pollTimer);
                if(pollingActive && pollDelivered) return;
                inlineDelivered = true;
                if(pollingActive) { stopPolling = true; if(bgTimer) { clearInterval(bgTimer); bgTimer = null; } }

                if(typeof data === 'object' && data.output) {
                    displayResult(data.output);
                } else if(typeof data === 'string') {
                    displayResult(data);
                }
                doCleanup(runId, false);
            },
            error: function(xhr) {
                if(pollTimer) clearTimeout(pollTimer);
                if(pollingActive && pollDelivered) return;

                // Gateway timeout — switch to background polling
                if(xhr.status === 502 || xhr.status === 504) {
                    inlineDelivered = false;
                    startPolling();
                    return;
                }

                inlineDelivered = true;
                if(bgTimer) { clearInterval(bgTimer); bgTimer = null; }
                $status.html('<i class="fa fa-times-circle"></i> Error: ' + xhr.status + ' ' + xhr.statusText);
                $submitBtn.prop('disabled', false);
                $form.show();
            }
        });
    });

});
