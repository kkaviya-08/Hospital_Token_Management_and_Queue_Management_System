<?php
require_once '../../config/auth.php';
requirePatientLogin();
require_once '../../config/redis.php';

$patientId = getPatientId();
$patient = safeRedisHGetAll($redis, 'patient:' . $patientId);
$patientName = $patient['name'] ?? getPatientName();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // Patient can update their OWN details only
    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $age = trim($_POST['age'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $newPassword = $_POST['password'] ?? '';

        $patientKey = 'patient:' . $patientId;

        if (!$redis->exists($patientKey) || safeRedisType($redis, $patientKey) !== 'hash') {
            $error = 'Patient record not found.';
        } elseif ($name === '' || $phone === '' || $age === '' || $gender === '') {
            $error = 'Please fill in all required fields (Name, Phone, Age, Gender).';
        } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
            $error = 'Please enter a valid 10-digit phone number.';
        } elseif ((int)$age < 1 || (int)$age > 120) {
            $error = 'Please enter a valid age between 1 and 120.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $currentEmail = strtolower(trim($patient['email'] ?? ''));

            // Check if email changed and is taken by another patient
            if ($email !== '' && $email !== $currentEmail) {
                $existingId = safeRedisGet($redis, 'patient:email:' . $email);
                if ($existingId && $existingId !== $patientId) {
                    $error = 'A patient account with this email address already exists.';
                }
            }

            if ($error === '' && $newPassword !== '' && strlen($newPassword) < 6) {
                $error = 'Password must be at least 6 characters.';
            }

            if ($error === '') {
                // If email changed, update index
                if ($email !== $currentEmail) {
                    if (!empty($currentEmail)) {
                        $redis->del('patient:email:' . $currentEmail);
                    }
                    if ($email !== '') {
                        $redis->set('patient:email:' . $email, $patientId);
                    }
                }

                $updates = [
                    'name'   => $name,
                    'phone'  => $phone,
                    'age'    => $age,
                    'gender' => $gender,
                    'email'  => $email
                ];

                if ($newPassword !== '') {
                    $updates['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
                }

                $redis->hmset($patientKey, $updates);

                $_SESSION['patient_name'] = $name;
                $_SESSION['patient_email'] = $email;
                $patientName = $name;
                $patient = safeRedisHGetAll($redis, $patientKey);

                // If patient has an active token, update token's patient_name
                $curActiveTok = safeRedisGet($redis, 'patient:active-token:' . $patientId);
                if ($curActiveTok && $redis->exists('token:' . $curActiveTok)) {
                    $redis->hset('token:' . $curActiveTok, 'patient_name', $name);
                }

                $message = 'Patient details updated successfully.';
            }
        }
    }

    // Patient can delete their OWN patient record only
    if ($action === 'delete_account') {
        $deleted = deletePatientRecord($redis, $patientId);
        if ($deleted) {
            destroyUserSession('/hospital-queue-system/public/patient/login.php?deleted=1');
        } else {
            $error = 'Unable to delete patient account.';
        }
    }
}

$activeTokenId = safeRedisGet($redis, 'patient:active-token:' . $patientId);
$tokenData = null;

if ($activeTokenId) {
    $tokenData = safeRedisHGetAll($redis, 'token:' . $activeTokenId);
    if (empty($tokenData)) {
        $activeTokenId = null;
    }
}

// Load active departments dynamically from Redis
$departments = getAllActiveDepartments($redis);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Dashboard | HospitalCare</title>
    <link rel="stylesheet" href="/hospital-queue-system/assets/css/style.css?v=201">
</head>
<body>

<header class="public-navbar">
    <a href="../index.php" class="public-logo">
        <div class="brand-logo small">H</div>
        <span>HospitalCare</span>
    </a>

    <div style="display:flex; align-items:center; gap:20px;">
        <span style="color:var(--text-navy); font-weight:600;">
            Hello, <?= htmlspecialchars($patientName) ?>
        </span>
        <a href="logout.php" class="btn btn-secondary btn-sm">Sign Out</a>
    </div>
</header>

<div class="standalone-page">
    <header class="top-header" style="margin-top:20px;">
        <div>
            <div class="eyebrow">Outpatient Portal</div>
            <h1>Patient Dashboard</h1>
            <p>Welcome, <strong><?= htmlspecialchars($patientName) ?></strong> (Patient ID: <?= htmlspecialchars($patientId) ?>). Track your consultation token.</p>
        </div>
        <div>
            <?php if (!$activeTokenId): ?>
                <a href="get-token.php" class="btn btn-primary btn-lg">
                    + Generate New Token
                </a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($message !== ''): ?>
        <div class="alert success" style="margin-top:16px;">
            <span><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert error" style="margin-top:16px;">
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Patient Profile & Account Settings Card -->
    <div class="content-card">
        <div class="card-header">
            <div>
                <h2>My Profile &amp; Account Settings</h2>
                <p>Manage your outpatient personal details and contact records</p>
            </div>
            <div style="display:flex; gap:10px; align-items:center;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="var s = document.getElementById('edit-profile-section'); s.style.display = (s.style.display === 'none' ? 'block' : 'none');">
                    ✏️ Edit My Details
                </button>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to permanently delete your patient account? This will cancel any active queue tokens.');">
                    <input type="hidden" name="action" value="delete_account">
                    <button type="submit" class="btn btn-danger btn-sm">
                        🗑 Delete My Account
                    </button>
                </form>
            </div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:18px; padding:12px 0;">
            <div>
                <div style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Full Name</div>
                <strong style="font-size:15px; color:var(--text-navy);"><?= htmlspecialchars($patient['name'] ?? $patientName) ?></strong>
            </div>
            <div>
                <div style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Mobile Phone</div>
                <strong style="font-size:15px; color:var(--text-navy);"><?= htmlspecialchars($patient['phone'] ?? '—') ?></strong>
            </div>
            <div>
                <div style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Age</div>
                <strong style="font-size:15px; color:var(--text-navy);"><?= htmlspecialchars($patient['age'] ?? '—') ?> Years</strong>
            </div>
            <div>
                <div style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Gender</div>
                <strong style="font-size:15px; color:var(--text-navy);"><?= htmlspecialchars($patient['gender'] ?? '—') ?></strong>
            </div>
            <div>
                <div style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Email Address</div>
                <strong style="font-size:15px; color:var(--text-navy);"><?= htmlspecialchars($patient['email'] ?? '—') ?></strong>
            </div>
        </div>

        <!-- Edit Profile Form (revealed via Edit button or error) -->
        <div id="edit-profile-section" style="display:<?= ($error !== '') ? 'block' : 'none' ?>; margin-top:20px; padding-top:20px; border-top:1px solid var(--border-light);">
            <h3 style="font-size:15px; margin-bottom:14px; color:var(--text-navy);">Update My Personal Details</h3>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="action" value="update_profile">

                <div class="form-grid">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($patient['name'] ?? $patientName) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>10-Digit Mobile Phone Number *</label>
                        <input type="text" name="phone" value="<?= htmlspecialchars($patient['phone'] ?? '') ?>" pattern="[0-9]{10}" maxlength="10" required>
                    </div>

                    <div class="form-group">
                        <label>Age (Years) *</label>
                        <input type="number" name="age" min="1" max="120" value="<?= htmlspecialchars($patient['age'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Gender *</label>
                        <select name="gender" required>
                            <option value="">-- Select Gender --</option>
                            <option value="Male" <?= (($patient['gender'] ?? '') === 'Male') ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= (($patient['gender'] ?? '') === 'Female') ? 'selected' : '' ?>>Female</option>
                            <option value="Other" <?= (($patient['gender'] ?? '') === 'Other') ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($patient['email'] ?? '') ?>" placeholder="patient@example.com">
                    </div>

                    <div class="form-group">
                        <label>New Password (Optional)</label>
                        <input type="password" name="password" placeholder="Leave blank to keep existing password">
                    </div>

                    <div class="form-actions full">
                        <button type="submit" class="btn btn-primary">
                            ✓ Save Updated Details
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('edit-profile-section').style.display='none';">
                            Cancel
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Active Token Banner if any -->
    <?php if ($tokenData): ?>
        <?php
        $tStatus = $tokenData['status'] ?? 'Waiting';
        $tLabel = $tokenData['token_label'] ?? $tokenData['token_number'] ?? $tokenData['id'];
        $stBadge = ($tStatus === 'Serving') ? 'badge-serving' : (($tStatus === 'Completed') ? 'badge-completed' : (($tStatus === 'Skipped') ? 'badge-skipped' : 'badge-waiting'));
        ?>
        <div class="content-card" style="border:2px solid var(--primary); background:#ffffff; padding:28px;">
            <div class="card-header">
                <div>
                    <span class="badge <?= $stBadge ?>" style="font-size:12px; margin-bottom:6px;"><?= htmlspecialchars($tStatus) ?></span>
                    <h2 style="font-size:22px;">Your Active Hospital Token</h2>
                </div>
                <div>
                    <a href="my-token.php" class="btn btn-primary">
                        View Live Queue Status &rarr;
                    </a>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:20px; margin-top:16px;">
                <div>
                    <div style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Token Number</div>
                    <div style="font-size:36px; font-weight:800; color:var(--primary); line-height:1.2;">
                        <?= htmlspecialchars($tLabel) ?>
                    </div>
                </div>

                <div>
                    <div style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Clinical Department</div>
                    <strong style="font-size:17px; color:var(--text-navy); display:block; margin-top:4px;">
                        <?= htmlspecialchars($tokenData['department'] ?? 'General Medicine') ?>
                    </strong>
                </div>

                <div>
                    <div style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Triage Risk Priority</div>
                    <div style="margin-top:6px;">
                        <?php
                        $rRisk = $tokenData['risk_level'] ?? $tokenData['priority'] ?? 'Normal';
                        $riskBadge = ($rRisk === 'Emergency') ? 'badge-emergency' : (($rRisk === 'High') ? 'badge-high' : 'badge-normal');
                        ?>
                        <span class="badge <?= $riskBadge ?>"><?= htmlspecialchars($rRisk) ?></span>
                    </div>
                </div>

                <div>
                    <div style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Generated Time</div>
                    <div style="font-size:14px; color:var(--text-navy); margin-top:6px;">
                        <?= htmlspecialchars(substr($tokenData['created_at'] ?? '', 11, 8)) ?>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="content-card" style="border-left:4px solid var(--primary); padding:24px 28px;">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px;">
                <div>
                    <h2 style="font-size:18px; margin-bottom:4px;">You have no active token today</h2>
                    <p style="color:var(--text-muted); font-size:13.5px;">Select your required clinical department below to generate your queue token.</p>
                </div>
                <a href="get-token.php" class="btn btn-primary">
                    Get Token Now &rarr;
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Clinical Departments Directory -->
    <div class="content-card">
        <div class="card-header">
            <div>
                <h2>Clinical Hospital Departments</h2>
                <p>Choose any department to generate an outpatient consultation token</p>
            </div>
            <span class="record-count"><?= count($departments) ?> Available Departments</span>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:18px;">
            <?php foreach ($departments as $d): ?>
                <div style="background:var(--bg-subtle); border:1px solid var(--border-color); border-radius:var(--radius-md); padding:20px; display:flex; flex-direction:column; justify-content:space-between;">
                    <div>
                        <strong style="font-size:16px; color:var(--text-navy); display:block; margin-bottom:6px;">
                            <?= htmlspecialchars($d['name']) ?>
                        </strong>
                        <p style="font-size:13px; color:var(--text-muted); margin-bottom:14px; line-height:1.45;">
                            <?= htmlspecialchars($d['description'] ?? 'Comprehensive outpatient medical consultation.') ?>
                        </p>
                    </div>

                    <div>
                        <a href="get-token.php?dept=<?= urlencode($d['key']) ?>" class="btn btn-secondary btn-sm" style="width:100%;">
                            Select &amp; Get Token &rarr;
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

</body>
</html>