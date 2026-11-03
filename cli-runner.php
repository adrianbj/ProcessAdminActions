<?php namespace ProcessWire;

/**
 * Runs an Admin Actions action in its own PHP CLI process, so it isn't subject to the web server's request
 * time limit. Started in the background by ProcessAdminActions when the Execution mode setting is "CLI":
 *
 *   php cli-runner.php <runId> <ProcessWire root path>
 *
 * The browser polls for the result in the same way as for long-running actions in Browser mode.
 */

if(PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$runId = isset($argv[1]) ? preg_replace('/[^a-zA-Z0-9_.]/', '', $argv[1]) : '';
$root = isset($argv[2]) ? rtrim($argv[2], '/') . '/' : '';
if($runId === '' || strpos($runId, '..') !== false || !is_file($root . 'index.php')) {
    fwrite(STDERR, "Usage: php cli-runner.php <runId> <ProcessWire root path>\n");
    exit(1);
}

// Registered before ProcessWire loads so it runs before any other shutdown handler, which may raise
// its own warnings and replace the fatal error that error_get_last() would otherwise report
register_shutdown_function(function() {
    $GLOBALS['adminActionsCliLastError'] = error_get_last();
});

chdir($root);
include $root . 'index.php';

/** @var ProcessWire $wire */
$wire->modules->get('ProcessAdminActions')->runCliJob($runId);
