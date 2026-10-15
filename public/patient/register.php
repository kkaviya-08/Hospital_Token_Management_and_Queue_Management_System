<?php
require_once '../../config/auth.php';
require_once '../../config/redis.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $age = trim($_POST['age'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($name === '' || $email === '' || $phone === '' || $age === '' || $gender === '' || $password === '') {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $error = 'Please enter a valid 10-digit phone number.';
    } elseif ((int)$age < 1 || (int)$age > 120) {
        $error = 'Please enter a valid age between 1 and 120.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must contain at least 6 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $existingPatient = safeRedisGet($redis, 'patient:email:' . $email);

        if ($existingPatient) {
            $error = 'An account with this email address already exists.';
        } else {
            // Synchronized patient ID
            $patientId = getNextPatientId($redis);

            $patientData = [
                'id'         => $patientId,
                'name'       => $name,
                'email'      => $email,
                'phone'      => $phone,
                'age'        => $age,
                'gender'     => $gender,
                'password'   => password_hash($password, PASSWORD_DEFAULT),
                'status'     => 'Active',
                'created_at' => date('Y-m-d H:i:s')
            ];

            $redis->hmset('patient:' . $patientId, $patientData);
            $redis->set('patient:email:' . $email, $patientId);

            header('Location: login.php?registered=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Registration | HospitalCare</title>
    <link rel="stylesheet" href="/hospital-queue-system/assets/css/style.css?v=201">
</head>
<body class="auth-page">

<div class="login-container" style="max-width:520px;">
    <div class="login-brand">
        <div class="brand-logo">H</div>
        <h1>HospitalCare</h1>
        <p>Patient Self-Service Token Registration</p>
    </div>

    <div class="login-card">
        <div class="login-heading">
            <h2>Create Patient Account</h2>
            <p>Register once to generate tokens and monitor your queue live</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert error">
                <span>⚠️ <?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="form-grid">
                <div class="form-group full">
                    <label>Full Name *</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" placeholder="e.g. Keerthana R" required>
                </div>

                <div class="form-group">
                    <label>Email Address *</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="e.g. keerthana@example.com" required>
                </div>

                <div class="form-group">
                    <label>Phone Number *</label>
                    <input type="text" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" placeholder="10-digit number" pattern="[0-9]{10}" maxlength="10" required>
                </div>

                <div class="form-group">
                    <label>Age *</label>
                    <input type="number" name="age" value="<?= htmlspecialchars($_POST['age'] ?? '') ?>" min="1" max="120" placeholder="e.g. 35" required>
                </div>

                <div class="form-group">
                    <label>Gender *</label>
                    <select name="gender" required>
                        <option value="">-- Select Gender --</option>
                        <option value="Male" <?= (($_POST['gender'] ?? '') === 'Male') ? 'selected' : '' ?>>Male</option>
                        <option value="Female" <?= (($_POST['gender'] ?? '') === 'Female') ? 'selected' : '' ?>>Female</option>
                        <option value="Other" <?= (($_POST['gender'] ?? '') === 'Other') ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Password *</label>
                    <input type="password" name="password" placeholder="At least 6 characters" required>
                </div>

                <div class="form-group">
                    <label>Confirm Password *</label>
                    <input type="password" name="confirm_password" placeholder="Repeat password" required>
                </div>

                <div class="form-actions full">
                    <button type="submit" class="btn btn-primary" style="width:100%; min-height:44px;">
                        Complete Registration &rarr;
                    </button>
                </div>
            </div>
        </form>

        <div class="login-info">
            Already have a patient account? <a href="login.php" style="color:var(--primary); font-weight:600;">Sign in here</a>
        </div>
    </div>

    <div style="text-align:center; margin-top:20px;">
        <a href="../index.php" class="back-link">← Return to Home Page</a>
    </div>
</div>

</body>
</html>