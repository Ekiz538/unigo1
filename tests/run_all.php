<?php
/**
 * =====================================================================
 * UniGo - Full test run
 * =====================================================================
 * Run:  C:\xampp\php\php.exe tests\run_all.php
 *
 * One command for the whole verification story. Runs, in order:
 *
 *   1. run_tests.php          64 checks - business logic, schema,
 *                             integrity constraints, injection, RBAC.
 *   2. http_flow_test.php     27 checks - the app over real HTTP: CSRF
 *                             at the request boundary, authentication,
 *                             role gates.
 *   3. concurrency_test.php   37 checks - two simultaneous HTTP requests
 *                             contending for the final seat (defect D-01).
 *
 * Requires Apache and MariaDB to be running. Exit code is non-zero if any
 * suite fails, so it can be wired into CI or a demo script.
 * =====================================================================
 */
$suites = ['run_tests.php', 'http_flow_test.php', 'concurrency_test.php'];
$php    = PHP_BINARY;

$failed = [];
$titles = [
    'run_tests.php'        => 'Unit & integration',
    'http_flow_test.php'   => 'HTTP security & flows',
    'concurrency_test.php' => 'Concurrency (D-01)',
];

foreach ($suites as $suite) {
    $path = __DIR__ . DIRECTORY_SEPARATOR . $suite;
    echo "\n";
    echo "\033[1m### {$titles[$suite]} - {$suite}\033[0m\n";
    echo str_repeat('=', 70) . "\n";

    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($path);
    passthru($cmd, $code);

    if ($code !== 0) {
        $failed[] = $suite;
    }
}

echo "\n";
echo str_repeat('=', 70) . "\n";
if ($failed === []) {
    echo "\033[32mALL SUITES PASSED\033[0m  (" . count($suites) . " suites)\n";
} else {
    echo "\033[31mFAILED: " . implode(', ', $failed) . "\033[0m\n";
}
echo str_repeat('=', 70) . "\n";

exit($failed === [] ? 0 : 1);
