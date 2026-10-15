<?php
require_once '../../config/auth.php';
require_once '../../config/redis.php';

if (!empty($_SESSION['doctor_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Seed default doctor account if not exists
$doctorEmail = 'doctor@hospital.com';
$doctorPassword = 'doctor123';

if (!$redis->exists('doctor:email:' . $doctorEmail)) {
    $doctorId = 'D0001';
    $doctorData = [
        'id'             => $doctorId,
        'name'           => 'Dr. Arun Kumar',
        'email'          => $doctorEmail,
        'password'       => password_hash($doctorPassword, PASSWORD_DEFAULT),
        'department'     => 'General Medicine',
        'department_key' => 'general-medicine',
        'specialization' => 'General Physician',
        'status'         => 'Active',
        'created_at'     => date('Y-m-d H:i:s')
    ];
    $redis->hmset('doctor:' . $doctorId, $doctorData);
    $redis->set('doctor:email:' . $doctorEmail, $doctorId);
    $redis->sadd('department:general-medicine:doctors', $doctorId);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both your email address and password.';
    } else {
        $doctorId = safeRedisGet($redis, 'doctor:email:' . $email);

        if (!$doctorId) {
            $error = 'Doctor account not found with this email.';
        } else {
            $doctor = safeRedisHGetAll($redis, 'doctor:' . $doctorId);

            if (
                empty($doctor) ||
                ($doctor['status'] ?? '') !== 'Active' ||
                !password_verify($password, $doctor['password'] ?? '')
            ) {
                $error = 'Incorrect email or password.';
            } else {
                $_SESSION['doctor_id'] = $doctor['id'];
                $_SESSION['doctor_name'] = $doctor['name'];
                $_SESSION['doctor_email'] = $doctor['email'];
                $_SESSION['doctor_department'] = $doctor['department'] ?? 'General Medicine';
                $_SESSION['doctor_department_key'] = $doctor['department_key'] ?? 'general-medicine';
                $_SESSION['doctor_specialization'] = $doctor['specialization'] ?? 'Physician';

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
    <title>Doctor Login | HospitalCare</title>
    <link rel="stylesheet" href="/hospital-queue-system/assets/css/style.css?v=201">
</head>
<body class="auth-page">

<div class="login-container">
    <div class="login-brand">
        <div class="brand-logo">H</div>
        <h1>HospitalCare</h1>
        <p>Physician &amp; Clinical Consultation Portal</p>
    </div>

    <div class="login-card">
        <div class="login-heading">
            <h2>Doctor Sign In</h2>
            <p>Sign in to manage your clinical department patient queue</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert error">
                <span>⚠️ <?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="form-group" style="margin-bottom:16px;">
                <label>Doctor Email Address</label>
                <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? 'doctor@hospital.com') ?>" placeholder="doctor@hospital.com" required autofocus>
            </div>

            <div class="form-group" style="margin-bottom:22px;">
                <label>Password</label>
                <input type="password" name="password" placeholder="Enter your password" required>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; min-height:44px;">
                Sign In to Doctor Portal
            </button>
        </form>

        <div class="login-info">
            Default credentials: <strong>doctor@hospital.com</strong> / <strong>doctor123</strong>
            <br>Department assigned: <strong>General Medicine</strong>
        </div>
    </div>

    <div style="text-align:center; margin-top:20px;">
        <a href="../index.php" class="back-link">← Return to Home Page</a>
    </div>
</div>

</body>
</html>