<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'Sign up';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Bind raw input; escape only at render (defect D-04 fix).
    $name  = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $role  = in_array($_POST['role'] ?? '', ['passenger', 'driver', 'operator'], true) ? $_POST['role'] : 'passenger';
    $password = (string)($_POST['password'] ?? '');

    if (!$name || !$email || !$phone || strlen($password) < 6) {
        flash('error', 'Please fill in all fields; password must be at least 6 characters.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Please enter a valid email address.');
    } else {
        $check = $pdo->prepare('SELECT id FROM users WHERE email = ? OR phone = ?');
        $check->execute([$email, $phone]);
        if ($check->fetch()) {
            flash('error', 'An account with that email or phone already exists.');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare(
                'INSERT INTO users (full_name, email, phone, password_hash, role) VALUES (?,?,?,?,?)'
            );
            $stmt->execute([$name, $email, $phone, $hash, $role]);
            $userId = (int) $pdo->lastInsertId();

            // If registering as an operator, create the operator record too.
            if ($role === 'operator') {
                $company = trim((string)($_POST['company_name'] ?? ($name . ' Transport')));
                $op = $pdo->prepare(
                    'INSERT INTO operators (owner_user_id, company_name, status) VALUES (?,?,?)'
                );
                $op->execute([$userId, $company, 'pending']);
            }
            // If registering as a driver, create a bare driver profile.
            if ($role === 'driver') {
                $license = trim((string)($_POST['license_number'] ?? 'PENDING'));
                $dr = $pdo->prepare(
                    'INSERT INTO drivers (user_id, license_number, status) VALUES (?,?,?)'
                );
                $dr->execute([$userId, $license, 'offline']);
            }

            flash('success', 'Account created. Please log in.');
            redirect('/auth/login.php');
        }
    }
}
require __DIR__ . '/../includes/header.php';
?>
<section class="form-card">
    <h2>Create your UniGo account</h2>
    <form method="post">
        <?= csrf_field() ?>
        <label>Full name<input type="text" name="full_name" required></label>
        <label>Email<input type="email" name="email" required></label>
        <label>Phone<input type="text" name="phone" placeholder="+2567........" required></label>
        <label>Password<input type="password" name="password" minlength="6" required></label>
        <label>I am a...
            <select name="role" id="roleSelect">
                <option value="passenger">Passenger</option>
                <option value="driver">Driver</option>
                <option value="operator">Transport operator / fleet owner</option>
            </select>
        </label>
        <div id="operatorField" style="display:none">
            <label>Company name<input type="text" name="company_name"></label>
        </div>
        <div id="driverField" style="display:none">
            <label>Driving license number<input type="text" name="license_number"></label>
        </div>
        <button type="submit" class="cta">Sign up</button>
    </form>
    <p>Already have an account? <a href="<?= BASE_URL ?>/auth/login.php">Log in</a></p>
</section>
<script>
const roleSelect = document.getElementById('roleSelect');
roleSelect.addEventListener('change', () => {
    document.getElementById('operatorField').style.display = roleSelect.value === 'operator' ? 'block' : 'none';
    document.getElementById('driverField').style.display = roleSelect.value === 'driver' ? 'block' : 'none';
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
