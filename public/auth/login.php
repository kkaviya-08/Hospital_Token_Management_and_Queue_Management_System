<?php
session_start();

// If user already has an active session, redirect to corresponding dashboard
if (!empty($_SESSION['admin_id'])) {
    header('Location: ../admin/dashboard.php');
    exit;
}
if (!empty($_SESSION['staff_id'])) {
    header('Location: ../staff/dashboard.php');
    exit;
}
if (!empty($_SESSION['doctor_id'])) {
    header('Location: ../doctor/dashboard.php');
    exit;
}
if (!empty($_SESSION['patient_id'])) {
    header('Location: ../patient/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital Portal Login | HospitalCare</title>
    <link rel="stylesheet" href="/hospital-queue-system/assets/css/style.css?v=201">
</head>
<body class="auth-page">

<div class="login-container">
    <div class="login-brand">
        <div class="brand-logo">H</div>
        <h1>HospitalCare</h1>
        <p>Real-Time Hospital Token and Queue Management System</p>
    </div>

    <div class="login-card">
        <div class="login-heading">
            <h2>Select Login Portal</h2>
            <p>Choose your authorized hospital role to proceed.</p>
        </div>

        <div style="display:flex; flex-direction:column; gap:10px; margin-top:16px;">
            <a href="../staff/login.php" class="btn btn-primary" style="justify-content:flex-start; padding:10px 14px; font-size:13.5px;">
                <strong>Hospital Staff Portal</strong>
            </a>
            <a href="../doctor/login.php" class="btn btn-secondary" style="justify-content:flex-start; padding:10px 14px; font-size:13.5px;">
                <strong>Doctor Consultation Portal</strong>
            </a>
            <a href="../admin/login.php" class="btn btn-secondary" style="justify-content:flex-start; padding:10px 14px; font-size:13.5px;">
                <strong>Hospital Administrator Portal</strong>
            </a>
            <a href="../patient/login.php" class="btn btn-secondary" style="justify-content:flex-start; padding:10px 14px; font-size:13.5px;">
                <strong>Patient Token Portal</strong>
            </a>
        </div>

        <div class="login-info">
            Access to hospital portals is restricted to authorized personnel.
        </div>
    </div>

    <div style="text-align:center; margin-top:20px;">
        <a href="../index.php" class="back-link">← Return to Home Page</a>
    </div>
</div>

</body>
</html>
