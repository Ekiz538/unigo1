<?php $u = current_user(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? clean($pageTitle) . ' - ' : '' ?><?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="<?= BASE_URL ?>/index.php">Uni<span>Go</span></a>
        <nav>
            <?php if ($u): ?>
                <?php if ($u['role'] === 'passenger'): ?>
                    <a href="<?= BASE_URL ?>/passenger/dashboard.php">Dashboard</a>
                    <a href="<?= BASE_URL ?>/passenger/search.php">Find a trip</a>
                    <a href="<?= BASE_URL ?>/passenger/my_bookings.php">My bookings</a>
                    <a href="<?= BASE_URL ?>/passenger/sos.php" class="sos">SOS</a>
                <?php elseif ($u['role'] === 'driver'): ?>
                    <a href="<?= BASE_URL ?>/driver/dashboard.php">Dashboard</a>
                    <a href="<?= BASE_URL ?>/driver/trips.php">My trips</a>
                <?php elseif (in_array($u['role'], ['admin','operator'], true)): ?>
                    <a href="<?= BASE_URL ?>/admin/dashboard.php">Dashboard</a>
                    <a href="<?= BASE_URL ?>/admin/vehicles.php">Vehicles</a>
                    <a href="<?= BASE_URL ?>/admin/routes.php">Routes</a>
                    <a href="<?= BASE_URL ?>/admin/trips.php">Trips</a>
                    <a href="<?= BASE_URL ?>/admin/subscriptions.php">Billing</a>
                    <a href="<?= BASE_URL ?>/admin/ai_insights.php">AI insights</a>
                <?php endif; ?>
                <span class="who"><?= clean($u['name']) ?> (<?= clean($u['role']) ?>)</span>
                <a href="<?= BASE_URL ?>/auth/logout.php">Log out</a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>/auth/login.php">Log in</a>
                <a href="<?= BASE_URL ?>/auth/register.php" class="cta">Sign up</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="container">
<?php
$flashSuccess = flash('success');
$flashError   = flash('error');
if ($flashSuccess): ?>
    <div class="alert alert-success"><?= clean($flashSuccess) ?></div>
<?php endif;
if ($flashError): ?>
    <div class="alert alert-error"><?= clean($flashError) ?></div>
<?php endif; ?>
