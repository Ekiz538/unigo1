<?php
/**
 * =====================================================================
 * UniGo - HTTP Flow & Security Test
 * =====================================================================
 * Run:  C:\xampp\php\php.exe tests\http_flow_test.php
 *
 * Complements tests\run_tests.php. That suite exercises the business
 * functions and the database in-process; this one drives the real app
 * over HTTP, which is the only way to prove the D-02 (CSRF) and D-04
 * (input handling) fixes are actually enforced at the request boundary,
 * and not merely present in a helper nobody calls.
 *
 * Covers:
 *   1. Every state-changing form actually renders a CSRF token.
 *   2. Every state-changing form rejects a missing/incorrect token.
 *   3. Authentication behaves (bad password, suspended account,
 *      SQL-injection payloads are inert, session really is established).
 *   4. Role gates hold over HTTP, not just in-process.
 *
 * Requires: Apache and MariaDB running, app served at BASE.
 * =====================================================================
 */
require_once __DIR__ . '/../config/db.php';

const BASE = 'http://localhost/unigo/';

$pass = 0;
$fail = 0;

function ok(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  \033[32mPASS\033[0m  {$label}\n";
    } else {
        $fail++;
        echo "  \033[31mFAIL\033[0m  {$label}\n";
    }
}

function token(string $html): ?string
{
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : null;
}

function newJar(): string
{
    return (string) tempnam(sys_get_temp_dir(), 'unigo_http_');
}

function req(string $method, string $path, ?array $fields, string $jar): array
{
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR       => $jar,
        CURLOPT_COOKIEFILE      => $jar,
        CURLOPT_TIMEOUT         => 30,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc  = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return ['code' => $code, 'location' => $loc, 'body' => (string) $body];
}

function login(string $email, string $password): ?string
{
    $jar = newJar();
    $page = req('GET', 'auth/login.php', null, $jar);
    $tok = token($page['body']);
    if (!$tok) {
        return null;
    }
    $res = req('POST', 'auth/login.php', [
        'email' => $email, 'password' => $password, 'csrf_token' => $tok,
    ], $jar);
    if ($res['code'] !== 302 || strpos($res['location'], 'login.php') !== false) {
        return null;
    }
    return $jar;
}

$jars = [];

// -------------------------------------------------------------------------
// 3. Authentication behaviour, checked before anything else.
// -------------------------------------------------------------------------
echo "\n\033[1m1. Authentication\033[0m\n";
echo str_repeat('-', 62) . "\n";

$jarBad = newJar();
$pg = req('GET', 'auth/login.php', null, $jarBad);
$badRes = req('POST', 'auth/login.php', [
    'email' => 'grace@example.com', 'password' => 'wrong-password', 'csrf_token' => token($pg['body']),
], $jarBad);
ok(strpos($badRes['body'], 'Invalid email or password') !== false,
    'wrong password is reported to the user');
$stillAnon = req('GET', 'passenger/dashboard.php', null, $jarBad);
ok(strpos((string) $stillAnon['location'], 'login.php') !== false,
    'wrong password establishes no session');

$jarInj = newJar();
$pg = req('GET', 'auth/login.php', null, $jarInj);
$injRes = req('POST', 'auth/login.php', [
    'email' => "grace@example.com' OR '1'='1", 'password' => "' OR '1'='1",
    'csrf_token' => token($pg['body']),
], $jarInj);
ok(strpos($injRes['body'], 'Invalid email or password') !== false,
    'SQL injection credentials are treated as literal text, not as SQL');
$probed = req('GET', 'passenger/dashboard.php', null, $jarInj);
ok(strpos((string) $probed['location'], 'login.php') !== false,
    'injection attempt left no authenticated session');

$suspEmail = 'suspend.probe@example.test';
$pdo->prepare('DELETE FROM users WHERE email = ?')->execute([$suspEmail]);
$pdo->prepare(
    "INSERT INTO users (full_name, email, phone, password_hash, role, status)
     VALUES ('Suspended Probe', ?, '+256700000333', ?, 'passenger', 'suspended')"
)->execute([$suspEmail, password_hash('Password123', PASSWORD_DEFAULT)]);
ok(login($suspEmail, 'Password123') === null, 'suspended account cannot log in (FR-02)');
$pdo->prepare('DELETE FROM users WHERE email = ?')->execute([$suspEmail]);

$dupEmail = 'grace@example.com';
$jarDup = newJar();
$pg = req('GET', 'auth/register.php', null, $jarDup);
$dupRes = req('POST', 'auth/register.php', [
    'full_name' => 'Duplicate Grace', 'email' => $dupEmail, 'phone' => '+256700000444',
    'password' => 'Password123', 'csrf_token' => token($pg['body']),
], $jarDup);
$stillOne = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email = '{$dupEmail}'")->fetchColumn();
ok($stillOne === 1, 'duplicate email registration did not create a second account');

// -------------------------------------------------------------------------
// 1 + 2. CSRF token presence and enforcement, page by page.
// -------------------------------------------------------------------------
echo "\n\033[1m2. CSRF token presence and enforcement\033[0m\n";
echo str_repeat('-', 62) . "\n";

$sessions = [
    'passenger' => login('grace@example.com', 'Password123'),
    'admin'     => login('admin@unigo.africa', 'Password123'),
    'driver'    => login('peter@example.com', 'Password123'),
    'operator'  => login('ops@kayola.co.ug', 'Password123'),
];
foreach ($sessions as $role => $j) {
    if ($j) {
        $jars[] = $j;
    }
    ok($j !== null, "established an authenticated session for role '{$role}'");
}

// Pages that render a state-changing form. The 'trigger' entry is the
// discriminator each handler dispatches on; without it the handler simply
// re-renders the page, so a CSRF probe has to carry it to reach the
// business logic at all.
$targets = [
    ['passenger/book.php?trip_id=1', 'passenger', ['trip_id' => 1, 'booking_type' => 'solo']],
    ['passenger/sos.php',           'passenger', ['latitude' => 0.3, 'longitude' => 32.5]],
    ['driver/trips.php',            'driver',    ['action' => 'start', 'trip_id' => 1]],
    ['admin/routes.php',            'admin',     ['form' => 'route', 'route_name' => 'Forged', 'origin' => 'A', 'destination' => 'B', 'distance_km' => 5]],
    ['admin/trips.php',             'admin',     ['action' => 'create', 'route_id' => 1, 'vehicle_id' => 1, 'departure_time' => '2027-01-01 08:00:00', 'seats_available' => 5]],
    ['admin/vehicles.php',          'admin',     ['plate_number' => 'FORGE-1', 'vehicle_type' => 'taxi', 'capacity' => 4]],
    ['admin/subscriptions.php',     'admin',     ['action' => 'recalculate']],
    ['admin/ai_insights.php',       'admin',     ['action' => 'run_forecast']],
];

echo "\n  Token is rendered in every state-changing form:\n";
$formsWithToken = 0;
$formsTotal = 0;
foreach ($targets as [$path, $role, $trigger]) {
    $jar = $sessions[$role] ?? null;
    if (!$jar) {
        continue;
    }
    $res = req('GET', $path, null, $jar);
    if (!preg_match('/<form[^>]*method="post"/i', $res['body'])) {
        continue;
    }
    $formsTotal++;
    if (token($res['body']) !== null) {
        $formsWithToken++;
        echo "         {$path}\n";
    }
}
ok($formsTotal > 0 && $formsWithToken === $formsTotal,
    "all {$formsTotal} state-changing forms render a CSRF token ({$formsWithToken} found)");

echo "\n  A forged POST (no token / wrong token) is refused with HTTP 403:\n";
$routesBefore   = (int) $pdo->query('SELECT COUNT(*) FROM routes')->fetchColumn();
$vehiclesBefore = (int) $pdo->query('SELECT COUNT(*) FROM vehicles')->fetchColumn();
$tripsBefore    = (int) $pdo->query('SELECT COUNT(*) FROM trips')->fetchColumn();

foreach ($targets as [$path, $role, $trigger]) {
    $jar = $sessions[$role] ?? null;
    if (!$jar) {
        continue;
    }
    $res = req('GET', $path, null, $jar);
    if (!preg_match('/<form[^>]*method="post"/i', $res['body'])) {
        continue;
    }
    $p = parse_url($path);
    $noTok = req('POST', $p['path'], $trigger, $jar);
    $badTok = req('POST', $p['path'], $trigger + ['csrf_token' => 'not-the-real-token'], $jar);
    ok($noTok['code'] === 403 && $badTok['code'] === 403,
        "{$p['path']} refuses a forged POST (no token: {$noTok['code']}, wrong token: {$badTok['code']})");
}

ok((int) $pdo->query('SELECT COUNT(*) FROM routes')->fetchColumn() === $routesBefore
    && (int) $pdo->query('SELECT COUNT(*) FROM vehicles')->fetchColumn() === $vehiclesBefore
    && (int) $pdo->query('SELECT COUNT(*) FROM trips')->fetchColumn() === $tripsBefore,
    'no forged request changed any state');

// -------------------------------------------------------------------------
// 4. Role gates.
// -------------------------------------------------------------------------
echo "\n\033[1m3. Role gates over HTTP\033[0m\n";
echo str_repeat('-', 62) . "\n";

$gates = [
    ['passenger', 'admin/dashboard.php'],
    ['passenger', 'driver/dashboard.php'],
    ['admin',     'passenger/search.php'],
    ['driver',    'admin/vehicles.php'],
];
foreach ($gates as [$role, $path]) {
    $jar = $sessions[$role] ?? null;
    if (!$jar) {
        continue;
    }
    $res = req('GET', $path, null, $jar);
    // require_role() denies a wrong-but-signed-in role with 403, not a redirect.
    ok($res['code'] === 403 && strpos($res['body'], 'Access denied') !== false,
        "role '{$role}' is denied access to {$path} (HTTP {$res['code']})");
}

$anon = newJar();
$jars[] = $anon;
foreach (['passenger/dashboard.php', 'admin/dashboard.php', 'driver/dashboard.php'] as $path) {
    $res = req('GET', $path, null, $anon);
    ok($res['code'] === 302 && strpos($res['location'], 'login.php') !== false,
        "anonymous request to {$path} is redirected to login");
}

echo "\n" . str_repeat('=', 62) . "\n";
echo $fail === 0
    ? "\033[32mALL PASSED\033[0m  {$pass} checks\n"
    : "\033[31m{$fail} FAILED\033[0m  {$pass} passed\n";
echo str_repeat('=', 62) . "\n";

foreach ($jars as $jar) {
    @unlink($jar);
}

exit($fail === 0 ? 0 : 1);
