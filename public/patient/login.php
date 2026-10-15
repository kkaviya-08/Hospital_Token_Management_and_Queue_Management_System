<?php
require_once '../../config/auth.php';
require_once '../../config/redis.php';

if (!empty($_SESSION['patient_id'])) {
    header('Location: dashboard.php');
    exit;
}

$message = '';
$error = '';

if (isset($_GET['registered'])) {
    $message = 'Registration successful! Please sign in with your email and password.';
}

if (isset($_GET['deleted'])) {
    $message = 'Patient deleted successfully.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both your email address and password.';
    } else {
        $patientId = safeRedisGet($redis, 'patient:email:' . $email);

        if (!$patientId) {
            $error = 'No patient account found with this email.';
        } else {
            $patient = safeRedisHGetAll($redis, 'patient:' . $patientId);

            if (empty($patient) || !isset($patient['password'])) {
                $error = 'Unable to verify your account.';
            } elseif (!password_verify($password, $patient['password'])) {
                $error = 'Incorrect email or password.';
            } else {
                $_SESSION['patient_id'] = $patient['id'];
                $_SESSION['patient_name'] = $patient['name'];
                $_SESSION['patient_email'] = $patient['email'];

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
    <title>Patient Login | HospitalCare</title>
    <link rel="stylesheet" href="/hospital-queue-system/assets/css/style.css?v=201">
</head>
<body class="auth-page">

<div class="login-container">
    <div class="login-brand">
        <div class="brand-logo">H</div>
        <h1>HospitalCare</h1>
        <p>Patient Token &amp; Live Queue Portal</p>
    </div>

    <div class="login-card">
        <div class="login-heading">
            <h2>Patient Sign In</h2>
            <p>Access your active token and track queue position</p>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert success">
                <span>✅ <?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert error">
                <span>⚠️ <?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="form-group" style="margin-bottom:16px;">
                <label>Email Address</label>
                <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="patient@example.com" required autofocus>
            </div>

            <div class="form-group" style="margin-bottom:22px;">
                <label>Password</label>
                <input type="password" name="password" placeholder="Enter your password" required>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; min-height:44px;">
                Sign In to Patient Portal
            </button>
        </form>

        <div class="login-info">
            Don't have an account yet? <a href="register.php" style="color:var(--primary); font-weight:600;">Register as Patient</a>
        </div>
    </div>

    <div style="text-align:center; margin-top:20px;">
        <a href="../index.php" class="back-link">← Return to Home Page</a>
    </div>
</div>

</body>
</html>