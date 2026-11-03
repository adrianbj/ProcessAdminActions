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
    var aliveUrl = config.aliveUrl || '';
    var xhrTimeout = maxExecTime > 0 ? Math.floor(maxExecTime * 0.8) * 1000 : 0;

    if(!pollUrl || !cleanupUrl) return;

    $form.on('submit', function(e) {
        e.preventDefault();

        var runId = Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        var formEl = this;

        var $submitBtn = $form.find('button[type="submit"], input[type="submit"]');
        var startTime = Date.now();
        var bgTimer = null;
        var finished = false;
        var batch = 1;
        var progress = '';
        var pollingActive = false;

        // Create status and result areas
        var $status = $('<div id="adminActionsRunStatus"><i class="fa fa-spinner fa-spin"></i> Running action...</div>');
        var $resultArea = $('<div id="adminActionsRunResult"></div>');
        $form.after($resultArea).after($status).hide();
        $submitBtn.prop('disabled', true);

        // Always show elapsed time from the start (across all batches)
        bgTimer = setInterval(function() {
            if(finished) return;
            var elapsed = Math.round((Date.now() - startTime) / 1000);
            var mins = Math.floor(elapsed / 60);
            var secs = elapsed % 60;
            var timeStr = mins > 0 ? mins + 'm ' + secs + 's' : secs + 's';
            var label = pollingActive ? 'Running in background... ' : 'Running action... ';
            if(batch > 1) label += 'batch ' + batch + ' · ';
            if(progress) label += $('<div>').text(progress).html() + ' · ';
            $status.html('<i class="fa fa-spinner fa-spin"></i> ' + label + timeStr);
        }, 1000);

        function stopTimer() {
            finished = true;
            if(bgTimer) { clearInterval(bgTimer); bgTimer = null; }
        }

        function displayResult(html) {
            stopTimer();
            $status.remove();
            $resultArea.html(html);
            // lazy Tracy dumps (eg. bd() output from a CLI run) need initialising once in the DOM
            if(window.Tracy && Tracy.Dumper) Tracy.Dumper.init($resultArea[0]);
        }

        function triggerDownload(data) {
            var $a = $('<a>').attr({ href: data.url, download: data.filename }).hide();
            $('body').append($a);
            $a[0].click();
            setTimeout(function() { $a.remove(); }, 100);
            // jQuery .text() round-trip escapes filename for safe HTML insertion
            var safeName = $('<div>').text(data.filename).html();
            displayResult('<div class="adminActionsSuccess"><p>Your file download has started: <strong>' + safeName + '</strong></p></div>');
        }

        function showError(message) {
            stopTimer();
            $status.addClass('adminActionsRunError').html('<i class="fa fa-times-circle"></i> ' + message);
            $submitBtn.prop('disabled', false);
            $form.show();
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

        // Handle a finished batch's result. Cleanup has already run, so a following
        // batch can't have its fresh status file deleted by this batch's cleanup.
        function handleResult(data) {
            if(data && data.status === 'continue') {
                batch = (parseInt(data.batch, 10) || batch) + 1;
                progress = data.progress || '';
                runBatch();
            } else if(data && data.status === 'download') {
                triggerDownload(data);
            } else if(data && typeof data === 'object' && data.output) {
                displayResult(data.output);
            } else if(typeof data === 'string') {
                displayResult(data);
            } else {
                showError('Error fetching results');
            }
        }

        function runBatch() {
            var batchStart = Date.now();
            var pollDelivered = false;
            var stopPolling = false;
            var inlineDelivered = false;
            var pollCount = 0;
            pollingActive = false;

            var formData = new FormData(formEl);
            formData.append('runId', runId);
            formData.append('batch', batch);

            function pollForResults(id) {
                if(stopPolling) return;

                // Safety: stop after 1 hour for a single batch
                if(Date.now() - batchStart > 3600000) {
                    showError('Process appears to have been terminated by the server');
                    doCleanup(id, false);
                    return;
                }

                $.ajax({
                    url: pollUrl + encodeURIComponent(id) + '.' + Date.now() + '.json',
                    method: 'GET',
                    dataType: 'json',
                    timeout: 10000,
                    success: function(data) {
                        if(inlineDelivered || stopPolling) return;
                        if(data.status === 'running') {
                            // every ~30s, have the server check a CLI run's process is still alive, so
                            // one killed by the server is reported rather than polled for an hour
                            pollCount++;
                            if(aliveUrl && pollCount % 10 === 0) {
                                $.ajax({ url: aliveUrl, method: 'POST', data: { runId: id }, headers: { 'X-Requested-With': 'XMLHttpRequest' }, dataType: 'json' });
                            }
                            setTimeout(function() { pollForResults(id); }, 3000);
                            return;
                        }
                        pollDelivered = true;
                        // Fetch full result from cleanup endpoint
                        doCleanup(id, true, handleResult);
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
                    // CLI execution mode: the action was started in its own process — poll for its result
                    if(data && data.status === 'background') {
                        startPolling();
                        return;
                    }
                    inlineDelivered = true;
                    if(pollingActive) { stopPolling = true; }
                    doCleanup(runId, false, function() { handleResult(data); });
                },
                error: function(xhr) {
                    if(pollTimer) clearTimeout(pollTimer);
                    if(pollingActive && pollDelivered) return;

                    // Connection lost/aborted — PHP may still be running, start polling
                    if(xhr.status === 0) {
                        startPolling();
                        return;
                    }

                    // Gateway/proxy timeout (incl. LiteSpeed 503 and Cloudflare 524) — PHP may still be running
                    if(xhr.status === 502 || xhr.status === 503 || xhr.status === 504 || xhr.status === 524) {
                        inlineDelivered = false;
                        startPolling();
                        return;
                    }

                    inlineDelivered = true;
                    stopPolling = true;

                    // Got a response, but not the expected JSON (eg. a PHP error or stray output) — show it
                    // rather than an unhelpful "Error: 200 OK"
                    if(xhr.responseText) {
                        displayResult('<div class="adminActionsError"><p>The action returned an unexpected response (HTTP ' + xhr.status + '):</p></div>' + xhr.responseText);
                        $submitBtn.prop('disabled', false);
                        $form.show();
                        return;
                    }
                    showError('Error: ' + xhr.status + ' ' + xhr.statusText);
                }
            });
        }

        runBatch();
    });

});
