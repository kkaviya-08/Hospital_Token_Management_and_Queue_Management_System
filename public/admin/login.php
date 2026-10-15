<?php
require_once '../../config/auth.php';
require_once '../../config/redis.php';

if (!empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

$adminEmail = 'admin@hospital.com';
$adminPassword = 'admin123';

if (!$redis->exists('admin:email:' . $adminEmail)) {
    $adminId = 'A0001';
    $adminData = [
        'id' => $adminId,
        'name' => 'Hospital Administrator',
        'email' => $adminEmail,
        'password' => password_hash($adminPassword, PASSWORD_DEFAULT),
        'role' => 'Admin',
        'status' => 'Active',
        'created_at' => date('Y-m-d H:i:s')
    ];
    $redis->hmset('admin:' . $adminId, $adminData);
    $redis->set('admin:email:' . $adminEmail, $adminId);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } else {
        $adminId = safeRedisGet($redis, 'admin:email:' . $email);
        if (!$adminId) {
            $error = 'Invalid admin credentials.';
        } else {
            $admin = safeRedisHGetAll($redis, 'admin:' . $adminId);
            if (
                empty($admin) ||
                ($admin['status'] ?? '') !== 'Active' ||
                !password_verify($password, $admin['password'] ?? '')
            ) {
                $error = 'Invalid admin credentials.';
            } else {
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_name'] = $admin['name'];
                $_SESSION['admin_email'] = $admin['email'];
                $_SESSION['admin_role'] = 'Admin';

                header('Location: dashboard.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | HospitalCare</title>
    <link rel="stylesheet" href="/hospital-queue-system/assets/css/style.css?v=201">
</head>
<body class="auth-page">

<div class="login-container">
    <div class="login-brand">
        <div class="brand-logo">H</div>
        <h1>HospitalCare</h1>
        <p>Administration Control Center</p>
    </div>

    <div class="login-card">
        <div class="login-heading">
            <h2>Administrator Login</h2>
            <p>Access hospital system configuration &amp; management</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert error">
                <span>⚠️ <?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="form-group" style="margin-bottom:16px;">
                <label>Administrator Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? 'admin@hospital.com') ?>" placeholder="admin@hospital.com" required autofocus>
            </div>

            <div class="form-group" style="margin-bottom:22px;">
                <label>Password</label>
                <input type="password" name="password" placeholder="Enter administrator password" required>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; min-height:44px;">
                Sign In to Admin Dashboard
            </button>
        </form>

        <div class="login-info">
            Default credentials: <strong>admin@hospital.com</strong> / <strong>admin123</strong>
        </div>
    </div>

    <div style="text-align:center; margin-top:20px;">
        <a href="../index.php" class="back-link">← Return to Home Page</a>
    </div>
</div>

</body>
</html>