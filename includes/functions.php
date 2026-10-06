<?php
/**
 * UniGo - shared helper functions
 */

function redirect(string $path): void
{
    header("Location: " . BASE_URL . $path);
    exit;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

function current_user(): ?array
{
    if (!is_logged_in()) return null;
    return [
        'id'   => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'],
        'role' => $_SESSION['user_role'],
    ];
}

/** Stop the page unless the logged-in user has one of the allowed roles. */
function require_role(array $roles): void
{
    if (!is_logged_in()) {
        redirect('/auth/login.php');
    }
    if (!in_array($_SESSION['user_role'], $roles, true)) {
        http_response_code(403);
        die('Access denied for role: ' . htmlspecialchars($_SESSION['user_role']));
    }
}

function clean(string $value): string
{
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

/**
 * Escape a value for safe use in HTML output. This is the ONLY correct use
 * of escaping: at the point of rendering, not at the point of input.
 *
 * Defect D-04 was that clean() was also being applied to values before they
 * were bound into a query. That was injection-safe but functionally wrong:
 * a user whose email contains an apostrophe could never authenticate,
 * because the escaped form would not match the stored value.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------
// CSRF protection (defect D-02)
//
// Every state-changing form must embed a token, and every POST handler
// must verify it. The token lives in the user's session and is compared
// with hash_equals() so the comparison is constant-time and cannot be
// defeated by timing.
// ---------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Emit the hidden field. Place inside every <form method="post">. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Raised when a state-changing request fails its CSRF check.
 * Extends RuntimeException so it is catchable in tests, while a single
 * exception handler (see config/bootstrap.php) converts it into a 403
 * for real requests. Throwing rather than calling die() is what makes the
 * control testable.
 */
class CsrfException extends RuntimeException
{
}

/**
 * Verify the submitted token or reject the request. Call at the top of
 * every state-changing handler, BEFORE any database work is performed.
 */
function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';

    if ($expected === '' || !is_string($submitted) || $submitted === ''
        || !hash_equals($expected, $submitted)) {
        throw new CsrfException(
            'Security check failed: invalid or missing request token.'
        );
    }
}

function flash(string $key, ?string $message = null)
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    if (!empty($_SESSION['flash'][$key])) {
        $msg = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
    return null;
}

/**
 * Fare estimate = base_fare + (distance_km * per_km_rate), then a
 * shared-ride discount is applied if booking_type = shared.
 * This mirrors Section 10 (transparent, formula-driven fares) of the
 * case study rather than "guesswork" pricing.
 */
function estimate_fare(float $baseFare, float $perKmRate, float $distanceKm, string $bookingType = 'solo'): float
{
    $fare = $baseFare + ($perKmRate * $distanceKm);
    if ($bookingType === 'shared') {
        $fare *= 0.65; // ~35% discount for sharing the ride
    }
    return round($fare, 2);
}

/**
 * Very simple rule-based "AI" congestion score used for the demo.
 * A real deployment would train a time-series model on gps_pings; for
 * a course-project prototype we derive a 0-100 score from how much
 * recent average speed has dropped versus a free-flow baseline.
 */
function estimate_congestion_score(float $avgRecentSpeedKmh, float $freeFlowSpeedKmh = 40.0): array
{
    if ($freeFlowSpeedKmh <= 0) $freeFlowSpeedKmh = 40.0;
    $ratio = max(0.0, min(1.0, $avgRecentSpeedKmh / $freeFlowSpeedKmh));
    $score = round((1 - $ratio) * 100, 1); // 0 = free-flowing, 100 = gridlock

    if ($score >= 70) {
        $summary = 'Heavy congestion likely - suggest alternative route';
    } elseif ($score >= 40) {
        $summary = 'Moderate congestion building';
    } else {
        $summary = 'Traffic flowing normally';
    }
    return ['score' => $score, 'summary' => $summary];
}

/**
 * Distance-based subscription calculation for an operator (Section 10).
 */
function calculate_subscription_due(
    float $baseFeeMonthly,
    float $perVehicleFee,
    int $vehicleCount,
    float $distanceFeePerKm,
    float $kmTracked,
    float $economyIndex = 1.00
): float {
    $total = $baseFeeMonthly + ($perVehicleFee * $vehicleCount) + ($distanceFeePerKm * $kmTracked);
    return round($total * $economyIndex, 2);
}

function generate_transaction_ref(string $prefix = 'TXN'): string
{
    return $prefix . '-' . strtoupper(bin2hex(random_bytes(5)));
}
