# Transparent File Downloads — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make AdminActions actions that emit file downloads (`header() + php://output + exit()`) work transparently inside the new AJAX/background-polling flow, without changing any action code.

**Architecture:** Server-side `ob_start` callback inspects `Content-Disposition: attachment` after the action runs, captures the binary to disk under a per-runId cache directory, and rewrites the response body to a JSON `{status:'download', url, filename, contentType}` envelope. A new `___executeDownload()` route serves the captured file with the original headers and unlinks it. JS handles the new envelope identically in both inline-AJAX and polling result paths.

**Tech Stack:** PHP (ProcessWire 3 module), jQuery (existing module JS), no test framework (manual verification per spec).

**Non-standard plan conventions:**
- **No automated tests.** The module has no test infrastructure; the spec calls for manual verification. Each task ends with a manual verification step instead of a unit test.
- **No automatic commits.** The user has asked not to commit changes automatically. Each task ends with a "Stage for review" step (`git add`) and a suggested commit message — the user runs `git commit` themselves when ready.

**Reference design:** `docs/superpowers/specs/2026-04-29-transparent-file-downloads-design.md`

**Path conventions in this plan:**
- `$DEV_SITE` refers to the root of your local ProcessWire site that has the AdminActions module installed (e.g. `/Users/adrianjones/Documents/GitHub/she`). Substitute your own path.
- All `git` commands run inside the `ProcessAdminActions` module repo unless noted otherwise.

---

## File Structure

All changes are in two existing files. No new files.

| File | Role | Lines added (approx) |
|---|---|---|
| `ProcessAdminActions.module.php` | Buffer install, capture callback, download route, GC extension, helpers | ~150 |
| `ProcessAdminActions.js` | Branch `download` envelope in inline + polling paths; `triggerDownload` helper | ~25 |

---

## Task 1: Add helper methods (`findHeader`, `parseFilenameFromDisposition`, `errorEnvelope`)

Pure functions used by the capture callback. Add them first so later tasks can reference them.

**Files:**
- Modify: `ProcessAdminActions.module.php` (add three private methods near the bottom of the class, just before the closing `}`)

- [ ] **Step 1.1: Locate the insertion point**

Find the last method in the class. The class closing brace is the last line of the file. Insert new methods immediately above it.

```bash
grep -n "^}" ProcessAdminActions.module.php | tail -1
```

Expected: prints the line number of the class-closing brace.

- [ ] **Step 1.2: Add `findHeader()`**

Insert immediately above the class closing brace:

```php
    /**
     * Find a header by name in the current outgoing headers list.
     * Returns the trimmed value (everything after the first colon) or null.
     */
    private function findHeader($name) {
        $prefix = strtolower($name) . ':';
        $prefixLen = strlen($prefix);
        foreach(headers_list() as $h) {
            if(strncasecmp($h, $prefix, $prefixLen) === 0) {
                return trim(substr($h, $prefixLen));
            }
        }
        return null;
    }
```

- [ ] **Step 1.3: Add `parseFilenameFromDisposition()`**

Insert directly below `findHeader()`:

```php
    /**
     * Extract the filename from a Content-Disposition header value.
     * Handles RFC 6266 quoted form (filename="...") and bare form (filename=...).
     * Returns the basename (no path components) or null if no match.
     */
    private function parseFilenameFromDisposition($disposition) {
        if(preg_match('/filename="([^"]+)"/i', $disposition, $m)) {
            return basename($m[1]);
        }
        if(preg_match('/filename=([^;\s]+)/i', $disposition, $m)) {
            return basename($m[1]);
        }
        return null;
    }
```

- [ ] **Step 1.4: Add `errorEnvelope()`**

Insert directly below `parseFilenameFromDisposition()`:

```php
    /**
     * Build a JSON error envelope for the AJAX/polling clients.
     * Also rewrites response headers to application/json for the inline path.
     */
    private function errorEnvelope($message) {
        header_remove('Content-Disposition');
        header_remove('Content-Type');
        header('Content-Type: application/json');
        return json_encode(array(
            'status' => 'error',
            'output' => '<div class="adminActionsError"><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></div>'
        ));
    }
```

- [ ] **Step 1.5: Verify the file still parses**

Run:

```bash
php -l ProcessAdminActions.module.php
```

Expected: `No syntax errors detected in ProcessAdminActions.module.php`

- [ ] **Step 1.6: Stage for review**

```bash
git add ProcessAdminActions.module.php
git status
```

Suggested commit message (run `git commit` yourself when ready):
> Add private helpers for outgoing-header inspection and download capture

---

## Task 2: Add `handleOutputBuffer()` capture method

The single piece of new logic. Inspects headers when buffering flushes; if a download is in flight, captures the buffer to disk and rewrites the response to a JSON envelope.

**Files:**
- Modify: `ProcessAdminActions.module.php` (add a new public method just below the helpers added in Task 1)

- [ ] **Step 2.1: Add the method**

Insert directly above the class closing brace, below the helpers from Task 1:

```php
    /**
     * ob_start callback that detects file-download responses (Content-Disposition: attachment),
     * captures the binary to disk under cache/AdminActions/downloads/<runId>/<filename>, and
     * rewrites the buffer to a JSON {status:'download',...} envelope. Pass-through otherwise.
     *
     * Public so the closure in ___executeRun can invoke it via $this->handleOutputBuffer(...).
     */
    public function handleOutputBuffer($buffer, $phase, $runId, $runStatusDir, $runCacheDir) {
        // Only act on the final flush
        if(!($phase & PHP_OUTPUT_HANDLER_FINAL)) return $buffer;

        // Out of scope: non-AJAX/non-runId requests use the legacy direct-output path
        if(!$runId) return $buffer;

        $disposition = $this->findHeader('Content-Disposition');
        if(!$disposition || stripos($disposition, 'attachment') === false) {
            return $buffer; // not a download — pass through
        }

        // If a fatal error occurred, drop the partial output and let the existing
        // shutdown error envelope reach the client.
        $lastError = error_get_last();
        if($lastError && in_array($lastError['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR))) {
            return $buffer;
        }

        $filename = $this->parseFilenameFromDisposition($disposition);
        if(!$filename) $filename = 'download_' . $runId . '.bin';
        $filename = basename($filename); // defense-in-depth
        $contentType = $this->findHeader('Content-Type');
        if(!$contentType) $contentType = 'application/octet-stream';

        $downloadsDir = $this->wire('config')->paths->cache . 'AdminActions/downloads/';
        $dir = $downloadsDir . $runId . '/';
        if(!is_dir($dir) && !wireMkdir($dir, true)) {
            return $this->errorEnvelope('Failed to create download directory');
        }
        $path = $dir . $filename;
        if(file_put_contents($path, $buffer) === false) {
            return $this->errorEnvelope('Failed to write download file');
        }
        // Sidecar carrying the original Content-Type so the download endpoint
        // can serve it back without trusting query parameters.
        file_put_contents($path . '.meta.json', json_encode(array('contentType' => $contentType)));

        $envelope = array(
            'status'      => 'download',
            'url'         => $this->processPageUrl . 'download/?runId=' . urlencode($runId) . '&filename=' . urlencode($filename),
            'filename'    => $filename,
            'contentType' => $contentType,
        );

        // Update run-cache so the polling/cleanup path returns the same envelope.
        // Order matters: the file must be on disk before the run-cache JSON appears.
        file_put_contents($runCacheDir . $runId . '.json', json_encode($envelope));
        file_put_contents($runStatusDir . $runId . '.json', json_encode(array('status' => 'complete')));

        // Rewrite the inline-AJAX response body and headers.
        header_remove('Content-Disposition');
        header_remove('Content-Type');
        header('Content-Type: application/json');
        return json_encode($envelope);
    }
```

- [ ] **Step 2.2: Verify the file still parses**

```bash
php -l ProcessAdminActions.module.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 2.3: Stage for review**

```bash
git add ProcessAdminActions.module.php
```

Suggested commit message:
> Add handleOutputBuffer: capture file-download responses to disk and rewrite to JSON envelope

---

## Task 3: Add `___executeDownload()` route

Serves a captured file with its original Content-Type/Disposition and unlinks it. Lives at `<processPageUrl>download/`.

**Files:**
- Modify: `ProcessAdminActions.module.php` (add public method near the existing `executeCleanup` method, around current line ~427)

- [ ] **Step 3.1: Locate the insertion point**

```bash
grep -n "function executeCleanup\|function ___execute" ProcessAdminActions.module.php
```

Expected: lists the existing route methods. Find the line after `executeCleanup`'s closing brace — that's where the new method goes.

- [ ] **Step 3.2: Add the route method**

Insert immediately below the `executeCleanup` method (before the next method, e.g. `buildSelectForm`):

```php
    /**
     * Executed when ./download/ url for module is accessed.
     * Streams a file captured by handleOutputBuffer back to the browser with
     * the original Content-Type and Content-Disposition: attachment headers.
     */
    public function ___executeDownload() {
        if(!$this->wire('user')->hasPermission('admin-actions')) {
            throw new WirePermissionException('No permission');
        }

        $runId = preg_replace('/[^a-zA-Z0-9_.]/', '', $this->wire('input')->get->runId);
        if(!$runId) throw new Wire404Exception();

        $rawFilename = $this->wire('input')->get->filename;
        if(!$rawFilename) throw new Wire404Exception();
        $filename = basename($rawFilename);
        if(!$filename || $filename === '.' || $filename === '..') throw new Wire404Exception();

        $dir = $this->wire('config')->paths->cache . 'AdminActions/downloads/' . $runId . '/';
        $path = $dir . $filename;
        if(!file_exists($path)) throw new Wire404Exception();

        $contentType = 'application/octet-stream';
        $metaPath = $path . '.meta.json';
        if(file_exists($metaPath)) {
            $meta = json_decode(file_get_contents($metaPath), true);
            if(is_array($meta) && isset($meta['contentType']) && is_string($meta['contentType'])) {
                $contentType = $meta['contentType'];
            }
        }

        // Disable PHP output buffering so readfile() streams directly.
        while(ob_get_level()) ob_end_clean();

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        readfile($path);

        @unlink($path);
        @unlink($metaPath);
        @rmdir($dir);
        exit;
    }
```

- [ ] **Step 3.3: Verify the file still parses**

```bash
php -l ProcessAdminActions.module.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3.4: Manual verification — endpoint serves and cleans up**

Place a small known file under the cache dir manually (in your dev environment):

```bash
mkdir -p $DEV_SITE/site/assets/cache/AdminActions/downloads/test_run_1
echo "hello world" > $DEV_SITE/site/assets/cache/AdminActions/downloads/test_run_1/test.txt
echo '{"contentType":"text/plain"}' > $DEV_SITE/site/assets/cache/AdminActions/downloads/test_run_1/test.txt.meta.json
```

Then in a browser (logged in as a user with `admin-actions` permission), visit:

```
<your admin>/setup/admin-actions/download/?runId=test_run_1&filename=test.txt
```

Expected: browser downloads `test.txt` containing `hello world`. After the download completes, verify the file and dir are gone:

```bash
ls $DEV_SITE/site/assets/cache/AdminActions/downloads/test_run_1
```

Expected: `No such file or directory`.

- [ ] **Step 3.5: Manual verification — 404 on missing file**

Visit the same URL again (file is now gone). Expected: ProcessWire 404 page (not blank, not stack trace).

- [ ] **Step 3.6: Manual verification — path traversal blocked**

Visit:

```
<your admin>/setup/admin-actions/download/?runId=test_run_1&filename=../../../etc/passwd
```

Expected: 404. (`basename()` reduces the filename to `passwd`, which doesn't exist in the runId dir.)

- [ ] **Step 3.7: Stage for review**

```bash
git add ProcessAdminActions.module.php
```

Suggested commit message:
> Add ___executeDownload route to serve captured files and clean up

---

## Task 4: Wire `ob_start` into `___executeRun()`

Install the buffer callback inside the existing AJAX/runId block so it only intercepts requests in the new background-execution flow.

**Files:**
- Modify: `ProcessAdminActions.module.php` lines 337-348 (inside the `if($runId) { ... }` block, just before the closing brace)

- [ ] **Step 4.1: Locate the GC block**

```bash
grep -n "Garbage collect old run files" ProcessAdminActions.module.php
```

Expected: ~line 337. Read the surrounding code to confirm the structure: the GC block is the last statement before `if($runId) {` closes at ~line 348.

- [ ] **Step 4.2: Insert the ob_start call after the GC block**

Locate the existing block:

```php
                // Garbage collect old run files (older than 1 hour) — throttled to ~10% of requests
                if(mt_rand(1, 10) === 1) {
                    foreach(array($runStatusDir, $runCacheDir) as $runDir) {
                        if(is_dir($runDir)) {
                            foreach(new \DirectoryIterator($runDir) as $file) {
                                if($file->isDot() || $file->getFilename() === '.htaccess') continue;
                                if(time() - $file->getMTime() > 3600) @unlink($file->getPathname());
                            }
                        }
                    }
                }
            }
        }
```

Add the ob_start call immediately after the GC block's closing brace, **inside** the `if($runId)` block:

```php
                // Garbage collect old run files (older than 1 hour) — throttled to ~10% of requests
                if(mt_rand(1, 10) === 1) {
                    foreach(array($runStatusDir, $runCacheDir) as $runDir) {
                        if(is_dir($runDir)) {
                            foreach(new \DirectoryIterator($runDir) as $file) {
                                if($file->isDot() || $file->getFilename() === '.htaccess') continue;
                                if(time() - $file->getMTime() > 3600) @unlink($file->getPathname());
                            }
                        }
                    }
                }

                // Install output buffer to capture file-download responses transparently.
                // The callback runs at final flush (including via PHP shutdown after exit()).
                ob_start(function($buffer, $phase) use ($runId, $runStatusDir, $runCacheDir) {
                    return $this->handleOutputBuffer($buffer, $phase, $runId, $runStatusDir, $runCacheDir);
                });
            }
        }
```

- [ ] **Step 4.3: Verify the file still parses**

```bash
php -l ProcessAdminActions.module.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 4.4: Manual verification — non-download action still works**

In the admin UI, run any non-download action (e.g. `Page Manipulator` or any built-in action that returns a success message). Expected: the success message renders in the result area exactly as before. No JSON envelope leaks. No download dialog.

- [ ] **Step 4.5: Manual verification — download action returns JSON envelope**

Open browser DevTools → Network tab. Run `BeachGradesExcel` (or any file-download action) with quick parameters that complete in <30s.

In the Network tab, find the POST to `.../admin-actions/run/?action=...`. Inspect the response.

Expected: response Content-Type is `application/json`; response body is a JSON object with `status: "download"`, `url`, `filename`, `contentType`. Also verify the file was captured:

```bash
ls $DEV_SITE/site/assets/cache/AdminActions/downloads/
```

Expected: a `<runId>` subdirectory containing `<filename>` and `<filename>.meta.json` (until JS triggers the download in Task 5, which then unlinks them).

The browser will likely show the `download` envelope as raw JSON in the result area at this point — that's expected; JS handling comes in Task 5.

- [ ] **Step 4.6: Stage for review**

```bash
git add ProcessAdminActions.module.php
```

Suggested commit message:
> Wire ob_start into ___executeRun to detect and capture file downloads

---

## Task 5: Handle `download` envelope in JS (inline + polling paths)

Add a `triggerDownload()` helper and branch both response-handling sites in the JS.

**Files:**
- Modify: `ProcessAdminActions.js` (success callback at line ~148; doCleanup result handler at line ~102; new helper near the existing `displayResult`)

- [ ] **Step 5.1: Add `triggerDownload()` helper**

Locate the existing `displayResult` function (around line 60). Insert the new helper directly below it:

```javascript
        function displayResult(html) {
            $status.remove();
            $resultArea.html(html);
            if(bgTimer) { clearInterval(bgTimer); bgTimer = null; }
        }

        function triggerDownload(data) {
            var $a = $('<a>').attr({ href: data.url, download: data.filename }).hide();
            $('body').append($a);
            $a[0].click();
            $a.remove();
            // jQuery .text() round-trip escapes filename for safe HTML insertion
            var safeName = $('<div>').text(data.filename).html();
            displayResult('<div class="adminActionsSuccess"><p>Your file download has started: <strong>' + safeName + '</strong></p></div>');
        }
```

- [ ] **Step 5.2: Branch the inline AJAX success callback**

Locate the `success: function(data)` block in the main `$.ajax` call (around line 148):

```javascript
            success: function(data) {
                if(pollTimer) clearTimeout(pollTimer);
                if(pollingActive && pollDelivered) return;
                inlineDelivered = true;
                if(pollingActive) { stopPolling = true; }

                if(typeof data === 'object' && data.output) {
                    displayResult(data.output);
                } else if(typeof data === 'string') {
                    displayResult(data);
                }
                doCleanup(runId, false);
            },
```

Replace the if/else if chain with:

```javascript
            success: function(data) {
                if(pollTimer) clearTimeout(pollTimer);
                if(pollingActive && pollDelivered) return;
                inlineDelivered = true;
                if(pollingActive) { stopPolling = true; }

                if(typeof data === 'object' && data.status === 'download') {
                    triggerDownload(data);
                } else if(typeof data === 'object' && data.output) {
                    displayResult(data.output);
                } else if(typeof data === 'string') {
                    displayResult(data);
                }
                doCleanup(runId, false);
            },
```

- [ ] **Step 5.3: Branch the polling result handler**

Locate the `doCleanup(id, true, function(result) { ... })` call inside `pollForResults` (around line 102):

```javascript
                    pollDelivered = true;
                    if(bgTimer) { clearInterval(bgTimer); bgTimer = null; }
                    // Fetch full result from cleanup endpoint
                    doCleanup(id, true, function(result) {
                        if(result && result.output) {
                            displayResult(result.output);
                        } else {
                            showError('Error fetching results');
                        }
                    });
```

Replace the inner if/else with:

```javascript
                    pollDelivered = true;
                    if(bgTimer) { clearInterval(bgTimer); bgTimer = null; }
                    // Fetch full result from cleanup endpoint
                    doCleanup(id, true, function(result) {
                        if(result && result.status === 'download') {
                            triggerDownload(result);
                        } else if(result && result.output) {
                            displayResult(result.output);
                        } else {
                            showError('Error fetching results');
                        }
                    });
```

- [ ] **Step 5.4: Manual verification — fast download (Path A)**

Hard-reload the admin UI to bust JS cache. Run `BeachGradesExcel` with quick parameters (<30s). 

Expected: browser save dialog appears with the correct `.xlsx` filename. Result area shows "Your file download has started: <filename>". The file opens cleanly in Excel and contents match a pre-AJAX baseline.

Verify cleanup:

```bash
ls $DEV_SITE/site/assets/cache/AdminActions/downloads/
```

Expected: empty (or only stale dirs from prior tests).

- [ ] **Step 5.5: Manual verification — slow download (Path B)**

Temporarily set `max_execution_time = 30` in php.ini (or the per-vhost equivalent) and restart PHP-FPM/Apache. Run `BeachRegionReportExcel` with a year-set known to take >30s.

Expected: status indicator switches from "Running action..." to "Running in background..." after ~24s (80% of `max_execution_time`). Once the action completes, save dialog appears, file opens cleanly. Restore `max_execution_time` to its prior value when done.

- [ ] **Step 5.6: Manual verification — non-download action regression**

Run a non-download action (e.g. `GenerateAnnualAvgPrecip`). Expected: success message renders normally; no save dialog; no JSON visible in the UI.

- [ ] **Step 5.7: Stage for review**

```bash
git add ProcessAdminActions.js
```

Suggested commit message:
> Handle download envelope in inline-AJAX and polling paths

---

## Task 6: Extend GC to clean up stale download directories

The existing GC iterates flat directories. Downloads use per-runId subdirectories with a file + sidecar — needs recursive cleanup.

**Files:**
- Modify: `ProcessAdminActions.module.php` lines 337-347 (the existing GC block in `___executeRun`)

- [ ] **Step 6.1: Replace the GC block**

Locate the existing block:

```php
                // Garbage collect old run files (older than 1 hour) — throttled to ~10% of requests
                if(mt_rand(1, 10) === 1) {
                    foreach(array($runStatusDir, $runCacheDir) as $runDir) {
                        if(is_dir($runDir)) {
                            foreach(new \DirectoryIterator($runDir) as $file) {
                                if($file->isDot() || $file->getFilename() === '.htaccess') continue;
                                if(time() - $file->getMTime() > 3600) @unlink($file->getPathname());
                            }
                        }
                    }
                }
```

Replace with:

```php
                // Garbage collect old run files (older than 1 hour) — throttled to ~10% of requests
                if(mt_rand(1, 10) === 1) {
                    // Flat dirs: status/cache files keyed by runId
                    foreach(array($runStatusDir, $runCacheDir) as $runDir) {
                        if(is_dir($runDir)) {
                            foreach(new \DirectoryIterator($runDir) as $file) {
                                if($file->isDot() || $file->getFilename() === '.htaccess') continue;
                                if(time() - $file->getMTime() > 3600) @unlink($file->getPathname());
                            }
                        }
                    }
                    // Nested dir: per-runId subdirectories holding download file + sidecar
                    $downloadsDir = $this->wire('config')->paths->cache . 'AdminActions/downloads/';
                    if(is_dir($downloadsDir)) {
                        foreach(new \DirectoryIterator($downloadsDir) as $subdir) {
                            if($subdir->isDot() || !$subdir->isDir()) continue;
                            if(time() - $subdir->getMTime() > 3600) {
                                $subdirPath = $subdir->getPathname();
                                foreach(new \DirectoryIterator($subdirPath) as $f) {
                                    if($f->isDot()) continue;
                                    @unlink($f->getPathname());
                                }
                                @rmdir($subdirPath);
                            }
                        }
                    }
                }
```

- [ ] **Step 6.2: Verify the file still parses**

```bash
php -l ProcessAdminActions.module.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 6.3: Manual verification — stale dir gets removed**

Manually create a stale download dir (use a date >1h ago):

```bash
mkdir -p $DEV_SITE/site/assets/cache/AdminActions/downloads/stale_run_test
echo "old" > $DEV_SITE/site/assets/cache/AdminActions/downloads/stale_run_test/old.bin
echo '{"contentType":"application/octet-stream"}' > $DEV_SITE/site/assets/cache/AdminActions/downloads/stale_run_test/old.bin.meta.json
touch -t 202001010000 $DEV_SITE/site/assets/cache/AdminActions/downloads/stale_run_test
```

Temporarily change `mt_rand(1, 10) === 1` to `mt_rand(1, 1) === 1` in your local module copy so the GC runs on every request. Then run any action once.

Expected:

```bash
ls $DEV_SITE/site/assets/cache/AdminActions/downloads/stale_run_test 2>&1
```

returns "No such file or directory" (or similar).

Restore `mt_rand(1, 10)` afterward.

- [ ] **Step 6.4: Stage for review**

```bash
git add ProcessAdminActions.module.php
```

Suggested commit message:
> Extend GC to recursively clean stale download directories

---

## Task 7: End-to-end verification (full test plan from spec)

Run the full manual test plan from the design doc. Treat this as a checklist — every box must pass before declaring the work done.

**Files:** none modified. Pure verification.

- [ ] **Step 7.1: Restart PHP-FPM / web server**

Make sure all changes are loaded (clear ProcessWire's module cache if needed via `Modules > Refresh`).

- [ ] **Step 7.2: Test 1 — Fast file download (Path A)**

Run `BeachGradesExcel` with a year that completes in <60s.

Pass criteria:
- Save dialog appears.
- File opens in Excel without corruption warning.
- Sheet contents match a pre-AJAX-flow baseline (compare to a known-good file you generated before this work, or sanity-check by row count + spot-check a few rows).
- `cache/AdminActions/downloads/<runId>/` is empty (deleted) after the download completes.

- [ ] **Step 7.3: Test 2 — Slow file download (Path B)**

Set `max_execution_time = 30` in php.ini or the active vhost. Run `BeachRegionReportExcel` with the full multi-year set known to take >5 min.

Pass criteria:
- Status indicator transitions: "Running action..." → "Running in background..." (around the 24s mark).
- Once the action eventually completes, save dialog appears.
- File opens cleanly.
- Restore `max_execution_time` afterward.

- [ ] **Step 7.4: Test 3 — Non-download action still works**

Run `GenerateAnnualAvgPrecip` or any action that returns a success message.

Pass criteria:
- Success message renders in the result area as before.
- No JSON-envelope artifacts visible.
- No save dialog.

- [ ] **Step 7.5: Test 4 — Action errors mid-download**

Temporarily edit `BeachRegionReportExcel.action.php` (in the SHE site repo) to insert `throw new \Exception('test');` between `header(...)` and `$writer->save('php://output')`. Run it.

Pass criteria:
- Error message renders in the result area.
- No file left in `cache/AdminActions/downloads/`.
- Revert the edit afterward.

- [ ] **Step 7.6: Test 5 — Stale download link returns 404**

After completing Test 1 successfully, copy the download URL from DevTools network tab. Open it in a new browser tab.

Pass criteria: ProcessWire 404 page (not blank, not stack trace).

- [ ] **Step 7.7: Test 6 — GC cleanup**

Already covered in Task 6.3. Confirm again.

- [ ] **Step 7.8: Test 7 — Inline AJAX result render regression**

Re-run a non-download action (different from Test 3) — e.g. one of the built-in module actions. Confirm the result area displays its HTML output unchanged from pre-implementation behavior.

- [ ] **Step 7.9: Test 8 — Polling fallback for non-download**

Force background polling on a non-download long-running action. The simplest way: lower `max_execution_time` to 30s and run `GenerateBeachAnnualScores` (which takes longer than 30s on the SHE dataset).

Pass criteria: result HTML still renders correctly through the polling path.

- [ ] **Step 7.10: Test 9 — Permission gate on download endpoint**

Log out (or use a private window). Visit any `<admin>/setup/admin-actions/download/?runId=...&filename=...` URL.

Pass criteria: blocked (login redirect or permission error). Not a 404 leaking the file existence.

- [ ] **Step 7.11: Final stage**

If all tests pass and you have any cumulative uncommitted edits not staged in earlier tasks:

```bash
git status
git diff --staged
```

Suggested final commit message (if combining all tasks into one commit):
> Add transparent file-download support to AdminActions

The user will create the commit(s) themselves.

---

## Self-Review Notes

Spec coverage check:
- ✅ Architecture (envelope shape, dir layout, new route) — Tasks 2, 3
- ✅ Components 1-5 — Tasks 4 (ob_start), 2 (handleOutputBuffer), 3 (executeDownload), 6 (GC), 5 (JS)
- ✅ Data flow Paths A-D — Tested in Task 7
- ✅ Error handling matrix — Test 4 (mid-download error), Test 5 (stale link), Test 9 (permission gate); Task 2 implements `error_get_last()` check, filename fallback, write-failure envelope; Task 3 implements `basename()` traversal protection and `Wire404Exception` on missing.
- ✅ Atomicity (file written before run-cache JSON) — Task 2 ordering
- ✅ Concurrency (per-runId dirs) — Task 2 dir layout, Task 3 dir-scoped reads
- ✅ Out-of-scope items — none implemented (no opt-in API, no stream wrapper, no PHPUnit)

No placeholders. All code shown in full. Method signatures consistent across tasks (`handleOutputBuffer($buffer, $phase, $runId, $runStatusDir, $runCacheDir)`).
