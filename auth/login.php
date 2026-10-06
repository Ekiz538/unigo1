<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'Log in';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Defect D-04 fix: bind the raw submitted value, escape only on output.
    // Escaping before the query was injection-safe but broke any address
    // containing a character such as an apostrophe.
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $stmt = $pdo->prepare('SELECT id, full_name, password_hash, role, status FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash']) && $user['status'] === 'active') {
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['user_role'] = $user['role'];

        if ($user['role'] === 'passenger') {
            redirect('/passenger/dashboard.php');
        } elseif ($user['role'] === 'driver') {
            redirect('/driver/dashboard.php');
        } else {
            redirect('/admin/dashboard.php');
        }
    } else {
        flash('error', 'Invalid email or password.');
    }
}
require __DIR__ . '/../includes/header.php';
?>
<section class="form-card">
    <h2>Log in to UniGo</h2>
    <form method="post">
        <?= csrf_field() ?>
        <label>Email<input type="email" name="email" required></label>
        <label>Password<input type="password" name="password" required></label>
        <button type="submit" class="cta">Log in</button>
    </form>
    <p class="hint">Demo accounts (password <code>Password123</code>): admin@unigo.africa &middot;
       grace@example.com (passenger) &middot; peter@example.com (driver) &middot; ops@kayola.co.ug (operator)</p>
    <p>No account? <a href="<?= BASE_URL ?>/auth/register.php">Sign up</a></p>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
