<?php
/**
 * =====================================================================
 * UniGo - Concurrency Test (defect D-01, final-seat race)
 * =====================================================================
 * Run:  C:\xampp\php\php.exe tests\concurrency_test.php
 *
 * This is the honest version of the seat-allocation test. It does not
 * simulate the race in PHP and it does not test a copy of the booking
 * logic: it drives the real HTTP endpoint (passenger/book.php) with two
 * genuinely simultaneous requests over two independent sessions, then
 * asserts against the committed database state.
 *
 * Why HTTP and not two PDO connections in one process: PHP's curl_multi
 * dispatches both requests in the same event-loop iteration, so the two
 * transactions really do contend for the same InnoDB row lock. That is
 * the condition defect D-01 existed in.
 *
 * The old bug: book.php ran the guarded UPDATE but discarded its
 * affected-row count, so both requests believed they had a seat and two
 * passengers were handed the same seat on a one-seat trip.
 *
 * Requires: Apache and MariaDB running, app served at BASE.
 * =====================================================================
 */
require_once __DIR__ . '/../config/db.php';

const BASE = 'http://localhost/unigo/';
const ROUNDS = 5;

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

/** Single request used for the sequential setup steps. */
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

/** Fire every job in the same tick, so the requests genuinely overlap. */
function race(array $jobs): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($jobs as $i => $j) {
        $ch = curl_init(BASE . $j['path']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR       => $j['jar'],
            CURLOPT_COOKIEFILE      => $j['jar'],
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => http_build_query($j['fields']),
            CURLOPT_TIMEOUT         => 30,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.05);
    } while ($running > 0);

    $out = [];
    foreach ($handles as $i => $ch) {
        $out[$i] = [
            'code'     => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'location' => (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL),
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

/** Log a passenger in over HTTP and return a session jar path. */
function login(string $email, string $password, string $jar): bool
{
    $page = req('GET', 'auth/login.php', null, $jar);
    $tok  = token($page['body']);
    if (!$tok) {
        return false;
    }
    $res = req('POST', 'auth/login.php', [
        'email' => $email, 'password' => $password, 'csrf_token' => $tok,
    ], $jar);
    return $res['code'] === 302 && strpos($res['location'], 'login.php') === false;
}

echo "\n\033[1mUniGo - Concurrency test (D-01 final-seat race)\033[0m\n";
echo str_repeat('-', 62) . "\n";

// -------------------------------------------------------------------------
// Fixtures: a second passenger (the schema ships only one) and a one-seat
// trip per round. Both are removed again in the cleanup block.
// -------------------------------------------------------------------------
$secondEmail = 'race.tester2@example.test';
$pdo->prepare('DELETE FROM users WHERE email = ?')->execute([$secondEmail]);
$pdo->prepare(
    "INSERT INTO users (full_name, email, phone, password_hash, role, status)
     VALUES ('Race Tester', ?, '+256700000222', ?, 'passenger', 'active')"
)->execute([$secondEmail, password_hash('Password123', PASSWORD_DEFAULT)]);
$secondId = (int) $pdo->lastInsertId();

$jars = [];
foreach ([['grace@example.com', 'a'], [$secondEmail, 'b']] as $idx => $cred) {
    $jar = tempnam(sys_get_temp_dir(), 'unigo_race_');
    $jars[] = $jar;
    ok(login($cred[0], 'Password123', $jar), "session {$cred[1]} logged in as {$cred[0]}");
}

$overallOk = true;

for ($round = 1; $round <= ROUNDS; $round++) {
    echo "\n  -- Round {$round} --\n";

    $pdo->prepare(
        "INSERT INTO trips (vehicle_id, route_id, departure_time, status, seats_available, seats_taken)
         VALUES (1, 1, DATE_ADD(NOW(), INTERVAL 1 DAY), 'scheduled', 1, 0)"
    )->execute();
    $tripId = (int) $pdo->lastInsertId();

    // Both passengers read the booking page first, exactly as a user would,
    // so each holds a genuine session token before the race begins.
    $jobs = [];
    foreach ($jars as $k => $jar) {
        $page = req('GET', 'passenger/book.php?trip_id=' . $tripId, null, $jar);
        $tok  = token($page['body']);
        ok((bool) $tok, 'session ' . ($k === 0 ? 'a' : 'b') . ' holds a CSRF token');
        $jobs[] = [
            'path'   => 'passenger/book.php',
            'jar'    => $jar,
            'fields' => ['trip_id' => $tripId, 'booking_type' => 'solo', 'csrf_token' => $tok],
        ];
    }

    $res = race($jobs);

    $winners = 0;
    foreach ($res as $i => $r) {
        $won = $r['code'] === 302 && strpos($r['location'], '/pay.php?booking_id=') !== false;
        if ($won) {
            $winners++;
        }
        echo "         session " . ($i === 0 ? 'a' : 'b') . " -> HTTP {$r['code']} "
            . ($won ? 'WON (redirected to payment)' : "rejected ({$r['location']})") . "\n";
    }

    $bookings = $pdo->query(
        "SELECT seat_number, passenger_id FROM bookings WHERE trip_id = {$tripId}"
    )->fetchAll();
    $tripRow = $pdo->query(
        "SELECT seats_available, seats_taken FROM trips WHERE id = {$tripId}"
    )->fetch();

    ok($winners === 1, "exactly one passenger won the final seat (winners={$winners})");
    ok(count($bookings) === 1, "exactly one booking row committed (rows=" . count($bookings) . ")");
    ok((int) $tripRow['seats_available'] === 0, 'seats_available reached 0');
    ok((int) $tripRow['seats_taken'] === 1, 'seats_taken reached 1');

    if (count($bookings) === 1) {
        ok($bookings[0]['seat_number'] === 'S1', "seat assigned deterministically as S1 (got {$bookings[0]['seat_number']})");
    }
    if ($winners !== 1) {
        $overallOk = false;
    }

    $pdo->prepare('DELETE FROM bookings WHERE trip_id = ?')->execute([$tripId]);
    $pdo->prepare('DELETE FROM trips WHERE id = ?')->execute([$tripId]);
}

echo "\n" . str_repeat('=', 62) . "\n";
echo $fail === 0
    ? "\033[32mALL PASSED\033[0m  {$pass}/" . ($pass) . "\n"
    : "\033[31m{$fail} FAILED\033[0m  {$pass} passed\n";
echo str_repeat('=', 62) . "\n";

// -------------------------------------------------------------------------
// Cleanup - the suite must leave no residue.
// -------------------------------------------------------------------------
foreach ($jars as $jar) {
    @unlink($jar);
}
$pdo->prepare('DELETE FROM bookings WHERE trip_id NOT IN (SELECT id FROM trips)')->execute();
$pdo->prepare('DELETE FROM users WHERE email = ?')->execute([$secondEmail]);

exit($fail === 0 ? 0 : 1);
