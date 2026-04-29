# Transparent file-download support in ProcessAdminActions

**Status:** Design approved, awaiting implementation plan
**Date:** 2026-04-29

## Problem

Recent changes to `ProcessAdminActions` (`7d4d112`, `a25f036`, `9e9cbed`) introduced an AJAX-with-runId background-execution flow: the action form is submitted via XHR, the response is wrapped in a `{status, output}` JSON envelope, and long-running actions fall back to a polling cycle that reads results from a run-cache file.

Actions that produce file downloads (e.g. `BeachGradesExcel.action.php`, `BeachRegionReportExcel.action.php` in the SHE site) use the standard PHP pattern:

```php
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="report.xlsx"');
$writer->save('php://output');
exit();
```

Under the new flow, the binary lands inside an XHR response body. The browser never receives the `Content-Disposition` as a navigation response, so no save dialog fires; instead, `ProcessAdminActions.js` injects the binary into the DOM as a string, displaying garbage characters in the result area.

## Goal

Make file downloads work end-to-end without changing any action code. Action authors continue to use `header() + php://output + exit()` and receive a real browser save dialog as before — across both the inline-AJAX response window and the background-polling fallback.

## Non-goals

- No new public API for action authors (explicitly: this design rejects an opt-in `sendFileDownload()` method in favor of full transparency).
- No streaming-to-disk during action output. The current memory budget (actions set `memory_limit=1024M`) is sufficient.
- No automated test suite. The module ships without one; manual verification is the project norm.

## Architecture

The detection and rewiring lives entirely inside `ProcessAdminActions.module.php` and `ProcessAdminActions.js`. Action files, the cleanup endpoint contract, and the run-status/run-cache file format are unchanged for non-download actions.

A new `download` envelope status extends the existing JSON contract:

```json
{ "status": "download", "url": "...", "filename": "...", "contentType": "..." }
```

This envelope is emitted by both response paths (inline-AJAX response at the end of `___executeRun`, and run-cache JSON consumed by the polling/cleanup endpoint), so the JS handles "download" identically regardless of which path delivered it.

A new module route `___executeDownload()` serves the captured file with the original `Content-Type` and `Content-Disposition` headers. Captured files live in `site/assets/cache/AdminActions/downloads/<runId>/<filename>`.

## Components

### 1. `___executeRun()` — install ob_start callback

After the existing background-execution setup (just before `$this->action->executeAction($options)` at the current line ~353), wrap the action call in `ob_start([$this, 'handleOutputBuffer'], 0)`. This ensures the callback runs once at final flush — including via PHP's normal shutdown after the action calls `exit()`.

The callback receives buffered output and the phase flags. Non-final phases pass through untouched.

### 2. `handleOutputBuffer($buffer, $phase)` — new private method

The single piece of new logic. Pseudocode:

```
if (!($phase & PHP_OUTPUT_HANDLER_FINAL)) return $buffer;

// If the action errored before writing complete output, drop the partial.
$lastError = error_get_last();
if ($lastError && in_array($lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
    return $buffer;  // existing error envelope wins
}

$disposition = findHeader('Content-Disposition');
if (!$disposition || stripos($disposition, 'attachment') === false) {
    return $buffer;  // not a download — pass through
}

$filename = parseFilenameFromDisposition($disposition) ?: ('download_' . $runId . '.bin');
$contentType = findHeader('Content-Type') ?: 'application/octet-stream';

$dir = $downloadsDir . $runId . '/';
if (!wireMkdir($dir, true)) {
    return errorEnvelope('Failed to create download directory');
}
$path = $dir . basename($filename);
if (file_put_contents($path, $buffer) === false) {
    return errorEnvelope('Failed to write download file');
}
file_put_contents($path . '.meta.json', json_encode(['contentType' => $contentType]));

$envelope = [
    'status'      => 'download',
    'url'         => $processPageUrl . 'download/?runId=' . $runId . '&filename=' . urlencode($filename),
    'filename'    => $filename,
    'contentType' => $contentType,
];

if ($runId) {
    file_put_contents($runCacheDir . $runId . '.json', json_encode($envelope));
    file_put_contents($runStatusDir . $runId . '.json', json_encode(['status' => 'complete']));
}

header_remove('Content-Disposition');
header_remove('Content-Type');
header('Content-Type: application/json');
return json_encode($envelope);
```

The run-cache JSON is written **after** the download file is fully written, so the poller never sees a `download` envelope pointing at a half-written file.

### 3. `___executeDownload()` — new module route

- Permission gate: `admin-actions` (mirrors `executeRun`).
- Validates `runId` with the existing regex `/[^a-zA-Z0-9_.]/`.
- Validates `filename` to its `basename()` only — no path traversal.
- Locates `cache/AdminActions/downloads/<runId>/<filename>`. Missing → `Wire404Exception`.
- Reads `Content-Type` from the sidecar `<filename>.meta.json` written at capture time. Default if missing/unreadable: `application/octet-stream`.
- Sends `Content-Type` and `Content-Disposition: attachment; filename="<basename>"`, streams via `readfile()`, then `unlink()` the file + sidecar and `rmdir()` the per-runId directory.

The sidecar approach (rather than a `contentType` query parameter) keeps the URL simpler and avoids accepting user-controllable Content-Type strings on a public-ish endpoint.

### 4. Garbage collection extension

The existing GC block (current lines ~337–347) iterates `$runStatusDir` and `$runCacheDir` deleting files older than 3600s. Extend to also iterate `$downloadsDir`, but recursively: for each per-runId subdirectory older than 3600s, delete contained files (including the sidecar) and `rmdir()` the subdirectory.

### 5. `ProcessAdminActions.js` — handle the `download` envelope

Today the JS treats `data` as `{status, output}` in two places: the inline AJAX `success` callback (~line 148) and the polling result handler (~line 102). Add a branch in both:

```js
function triggerDownload(data) {
    var $a = $('<a>').attr({ href: data.url, download: data.filename }).hide();
    $('body').append($a);
    $a[0].click();
    $a.remove();
    displayResult('<div class="adminActionsSuccess"><p>Your file download has started: <strong>' +
        $('<div>').text(data.filename).html() + '</strong></p></div>');
}
```

Filename interpolation goes through a text-escape pass (jQuery `.text()` round-trip) since `data.filename` ultimately comes from the action's `Content-Disposition` header.

The existing race protection (`pollDelivered`, `inlineDelivered`, `stopPolling`) is unchanged — `download` envelopes flow through the same branches.

## Data flow

### Path A — Fast download (action completes within XHR window)

1. Browser submits form via XHR with `runId`.
2. `___executeRun()` installs `ob_start(handleOutputBuffer)`.
3. Action calls `header(Content-Type)`, `header(Content-Disposition: attachment)`, writes binary to `php://output`, calls `exit()`.
4. PHP shutdown flushes the buffer; `handleOutputBuffer` runs with `$phase` including `PHP_OUTPUT_HANDLER_FINAL`.
5. Callback finds `Content-Disposition: attachment`, saves binary + sidecar to `cache/AdminActions/downloads/<runId>/`, writes run-cache JSON, rewrites response headers, returns JSON envelope.
6. XHR resolves with `{status: 'download', url, filename, contentType}`.
7. JS triggers click on hidden `<a download>`; browser navigates.
8. `___executeDownload()` streams file, sets headers, unlinks file + sidecar + dir.
9. JS shows "Download started" message; calls cleanup endpoint to remove run-status/run-cache.

### Path B — Slow download (XHR timed out, polling took over)

1. XHR timer expires before action finishes; JS calls `startPolling()`.
2. Action eventually completes; ob callback writes the same `download` envelope to run-cache JSON; status file flips to `complete`.
3. Polling sees `complete`, calls cleanup with `returnResult=1`.
4. Cleanup endpoint reads run-cache JSON and returns it verbatim.
5. JS receives `{status: 'download', ...}` and triggers download identically to Path A.

### Path C — Action returns normally without exit()

Same as Path A. The `ob_start` callback runs at end of `___executeRun()` rather than at `exit()`. Header inspection is identical.

### Path D — Action errors / fatal / uncaught exception

The existing error/exception/shutdown handlers (lines ~314–335) write `{status: 'error', output: html}` to run-cache. The ob callback runs but its `error_get_last()` check (or absent `Content-Disposition`) causes it to pass the buffer through. The error envelope wins.

## Error handling

| Failure | Behavior |
|---|---|
| Action sets `Content-Disposition` but errors before flushing complete output | `error_get_last()` check in callback drops the buffer; existing error envelope reaches client; no partial file saved |
| Filename in `Content-Disposition` malformed/absent | Parser supports RFC 6266 quoted form and bare form; falls back to `download_<runId>.bin` |
| Filesystem write fails (disk full, permissions) | Callback returns error envelope; JS shows it in the result area |
| User opens download URL after file already downloaded or after TTL cleanup | `executeDownload` returns 404 via `Wire404Exception` |
| Permission/CSRF on download URL | `admin-actions` permission required; `runId` regex-validated; `filename` reduced to `basename()`; per-runId directory scoping prevents cross-user access |

**Concurrency:** `runId` is unique per action invocation, so concurrent downloads land in separate directories — no collisions.

**Atomicity:** the run-cache JSON is updated only after `file_put_contents()` for both the download file and the sidecar return successfully, so polling never sees a `download` envelope before the file exists. Strict atomic durability isn't required here — if the server crashes mid-write, the run-status file is also unwritten and the JS poll just times out, which is the existing failure mode for any crashed action.

## Testing (manual)

The module has no automated test infrastructure, so verification is manual.

**Happy-path:**

1. **Fast file download (Path A).** Run `BeachGradesExcel` with a year that completes in <60s. Verify: save dialog appears, file opens in Excel, contents match a pre-AJAX baseline, `cache/AdminActions/downloads/<runId>/` is empty after download.
2. **Slow file download (Path B).** Set `max_execution_time=30` temporarily, or pick a year-set known to take 5+ min. Run `BeachRegionReportExcel`. Verify: status switches from "Running action..." to "Running in background...", save dialog still appears once polling completes, file opens cleanly.
3. **Non-download action still works.** Run `GenerateAnnualAvgPrecip`. Verify: success message renders in the result area as before; no JSON-envelope artifacts; no download triggered.

**Failure modes:**

4. **Action errors mid-download.** Temporarily edit `BeachRegionReportExcel` to `throw new \Exception()` between setting headers and `$writer->save(...)`. Verify: error renders in result area; no file left in `cache/AdminActions/downloads/`.
5. **Stale download link.** After a successful download, manually re-open the same download URL from DevTools. Verify: 404, no stack trace.
6. **GC cleanup.** Manually create `cache/AdminActions/downloads/fakerun_old/test.xlsx` with an old mtime. Trigger GC (run actions repeatedly until the 10% chance fires, or temporarily lower the threshold). Verify: stale dir removed.

**Regression:**

7. **Inline AJAX result render.** Confirm any non-download action still shows its success/failure HTML output unchanged.
8. **Polling fallback for non-download.** Force background polling on a non-download long-running action (e.g. `GenerateBeachAnnualScores`). Verify: result HTML still renders correctly via the polling path.
9. **Permission gate.** Hit `executeDownload` URL while logged out. Verify: blocked.

## Out of scope / explicitly rejected

- An opt-in API like `$this->action->sendFileDownload(...)` — rejected in favor of full transparency.
- A custom stream wrapper for `php://output` — too brittle (libraries may bypass wrappers).
- A shutdown-only detection without `ob_start` — by the time shutdown runs, output has typically already been flushed; can't rewrite headers or body.
- PHPUnit/automated browser tests — disproportionate to scope and module has no existing infrastructure.
