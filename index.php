<?php
require_once __DIR__ . '/config/config.php';
$pageTitle = 'Home';
require __DIR__ . '/includes/header.php';
?>
<section class="hero">
    <h1>One platform. Every mode of transport. City to continent.</h1>
    <p>UniGo connects buses, taxis, bodas, electric buses, and goods delivery into a single
       real-time, bookable, payable network &mdash; built to scale from one city street to the whole world.</p>
    <?php if (!is_logged_in()): ?>
        <a class="cta" href="<?= BASE_URL ?>/auth/register.php">Get started</a>
        <a class="cta secondary" href="<?= BASE_URL ?>/auth/login.php">I already have an account</a>
    <?php else: ?>
        <a class="cta" href="<?= BASE_URL ?>/<?= $_SESSION['user_role'] === 'passenger' ? 'passenger/dashboard.php' : (in_array($_SESSION['user_role'],['admin','operator']) ? 'admin/dashboard.php' : 'driver/dashboard.php') ?>">Go to my dashboard</a>
    <?php endif; ?>
</section>

<section class="modules-grid">
    <div class="module-card">
        <h3>Passenger app</h3>
        <p>Search routes, compare live fares, book a seat, pay, and track your ride.</p>
    </div>
    <div class="module-card">
        <h3>Driver / operator tools</h3>
        <p>Report GPS position, manage trips, fill seats, get dispatched to demand.</p>
    </div>
    <div class="module-card">
        <h3>Admin &amp; authority dashboard</h3>
        <p>Fleet, route and vehicle management, billing, and network-wide analytics.</p>
    </div>
    <div class="module-card">
        <h3>AI insight layer</h3>
        <p>Congestion &amp; demand forecasting, fraud flags, feeding every other module.</p>
    </div>
    <div class="module-card">
        <h3>Digital payments</h3>
        <p>Mobile-money style wallet payments recorded against every booking.</p>
    </div>
    <div class="module-card">
        <h3>Safety / SOS</h3>
        <p>One-tap emergency alert with live location shared to authorities.</p>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
