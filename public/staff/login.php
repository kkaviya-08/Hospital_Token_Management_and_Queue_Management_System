<?php
require_once '../../config/auth.php';
require_once '../../config/redis.php';

if (!empty($_SESSION['staff_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Seed default demo staff account if not already present
$defaultStaffEmail = 'staff@hospital.com';
$defaultStaffPass = 'staff123';
if (!$redis->exists('staff:email:' . $defaultStaffEmail)) {
    $c = (int)($redis->get('staff:counter') ?? 0);
    $staffId = 'S' . str_pad($c + 1, 4, '0', STR_PAD_LEFT);
    $staffData = [
        'id'         => $staffId,
        'name'       => 'Front Desk Staff',
        'email'      => $defaultStaffEmail,
        'phone'      => '9876543210',
        'role'       => 'Receptionist',
        'password'   => password_hash($defaultStaffPass, PASSWORD_DEFAULT),
        'status'     => 'Active',
        'created_at' => date('Y-m-d H:i:s')
    ];
    $redis->hmset('staff:' . $staffId, $staffData);
    $redis->set('staff:email:' . $defaultStaffEmail, $staffId);
    $redis->set('staff:counter', $c + 1);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both your email address and password.';
    } else {
        $staffId = safeRedisGet($redis, 'staff:email:' . $email);

        if (!$staffId) {
            $error = 'Invalid email or password.';
        } else {
            $staff = safeRedisHGetAll($redis, 'staff:' . $staffId);

            if (
                empty($staff) ||
                ($staff['status'] ?? '') !== 'Active' ||
                !password_verify($password, $staff['password'] ?? '')
            ) {
                $error = 'Invalid email or password.';
            } else {
                $_SESSION['staff_id'] = $staff['id'];
                $_SESSION['staff_name'] = $staff['name'];
                $_SESSION['staff_email'] = $staff['email'];
                $_SESSION['staff_role'] = $staff['role'] ?? 'Hospital Staff';

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
    <title>Staff Login | HospitalCare</title>
    <link rel="stylesheet" href="/hospital-queue-system/assets/css/style.css?v=201">
</head>
<body class="auth-page">

<div class="login-container">
    <div class="login-brand">
        <div class="brand-logo">H</div>
        <h1>HospitalCare</h1>
        <p>Hospital Staff &amp; Reception Portal</p>
    </div>

    <div class="login-card">
        <div class="login-heading">
            <h2>Staff Sign In</h2>
            <p>Sign in to manage patient registrations, tokens, and queues</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert error">
                <span>⚠️ <?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="form-group" style="margin-bottom:16px;">
                <label>Staff Email Address</label>
                <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? 'staff@hospital.com') ?>" placeholder="staff@hospital.com" required autofocus>
            </div>

            <div class="form-group" style="margin-bottom:22px;">
                <label>Password</label>
                <input type="password" name="password" placeholder="Enter staff password" required>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; min-height:44px;">
                Sign In to Staff Portal
            </button>
        </form>

        <div class="login-info">
            Default credentials: <strong>staff@hospital.com</strong> / <strong>staff123</strong>
            <br>Staff accounts can also be created by Hospital Administration.
        </div>
    </div>

    <div style="text-align:center; margin-top:20px;">
        <a href="../index.php" class="back-link">← Return to Home Page</a>
    </div>
</div>

</body>
</html>