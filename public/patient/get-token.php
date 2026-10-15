<?php
require_once '../../config/auth.php';
requirePatientLogin();
require_once '../../config/redis.php';

$patientId = getPatientId();
$patient = safeRedisHGetAll($redis, 'patient:' . $patientId);
$patientName = $patient['name'] ?? getPatientName();

// Fetch active departments dynamically from Redis
$departments = getAllActiveDepartments($redis);

$preselectedDept = trim($_GET['dept'] ?? '');

$message = '';
$error = '';

$activeTokenId = safeRedisGet($redis, 'patient:active-token:' . $patientId);
if ($activeTokenId) {
    $activeData = safeRedisHGetAll($redis, 'token:' . $activeTokenId);
    $st = $activeData['status'] ?? '';
    if (in_array($st, ['Waiting', 'Serving'], true)) {
        header('Location: my-token.php');
        exit;
    } else {
        // Clear stale reference
        $redis->del('patient:active-token:' . $patientId);
        $activeTokenId = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $departmentKey = trim($_POST['department_key'] ?? '');

    // Validate department
    $deptHash = safeRedisHGetAll($redis, 'department:' . $departmentKey);

    if (empty($deptHash)) {
        $error = 'Please select a valid clinical department.';
    } else {
        $date = date('Y-m-d');

        // Department prefix (first 3 letters uppercase)
        $prefixClean = preg_replace('/[^A-Za-z]/', '', $departmentKey);
        $tokenPrefix = strtoupper(substr($prefixClean, 0, 3));
        if (strlen($tokenPrefix) < 3) {
            $tokenPrefix = str_pad($tokenPrefix, 3, 'X');
        }

        $tokenNum = getNextDepartmentTokenNumber($redis, $departmentKey, $date);
        $tokenLabel = $tokenPrefix . '-' . str_pad($tokenNum, 3, '0', STR_PAD_LEFT);

        $tokenId = 'T' . date('YmdHis') . $tokenNum . rand(10, 99);

        // Standard patient online self-service tokens default to Normal triage priority
        $tokenData = [
            'id'             => $tokenId,
            'patient_id'     => $patientId,
            'patient_name'   => $patientName,
            'department'     => $deptHash['name'] ?? ucfirst($departmentKey),
            'department_key' => $departmentKey,
            'token_number'   => (string)$tokenNum,
            'token_label'    => $tokenLabel,
            'risk_level'     => 'Normal',
            'priority'       => 'Normal',
            'priority_value' => '3',
            'status'         => 'Waiting',
            'date'           => $date,
            'created_at'     => date('Y-m-d H:i:s'),
            'created_by'     => 'Patient Self-Service'
        ];

        $redis->hmset('token:' . $tokenId, $tokenData);
        $redis->rpush('queue:' . $departmentKey . ':' . $date, $tokenId);
        $redis->set('patient:active-token:' . $patientId, $tokenId);

        header('Location: my-token.php?token=' . urlencode($tokenId));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Get Token | HospitalCare</title>
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
        <a href="dashboard.php" class="btn btn-secondary btn-sm">Dashboard</a>
        <a href="logout.php" class="btn btn-secondary btn-sm">Sign Out</a>
    </div>
</header>

<div class="standalone-page">
    <div style="margin-top:16px;">
        <a href="dashboard.php" class="back-link">&larr; Back to Dashboard</a>
    </div>

    <div class="login-container" style="max-width:550px; margin:20px auto 40px;">
        <div class="content-card" style="box-shadow:var(--shadow-lg);">
            <div class="card-header">
                <div>
                    <h2>Generate Hospital Token</h2>
                    <p>Select your required department to join the outpatient queue</p>
                </div>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert error">
                    <span>⚠️ <?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="form-group" style="margin-bottom:20px;">
                    <label>Patient Full Name</label>
                    <input type="text" value="<?= htmlspecialchars($patientName) ?> (ID: <?= htmlspecialchars($patientId) ?>)" disabled style="background:var(--bg-subtle);">
                </div>

                <div class="form-group" style="margin-bottom:24px;">
                    <label>Select Clinical Department *</label>
                    <select name="department_key" required style="font-size:15px; min-height:46px;">
                        <option value="">-- Choose Clinical Department --</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= htmlspecialchars($d['key']) ?>" <?= ($preselectedDept === $d['key']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="margin-top:6px; font-size:12px; color:var(--text-muted);">
                        Emergency triage is evaluated at physical check-in by hospital staff.
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg" style="width:100%;">
                    🎟️ Confirm &amp; Issue Token
                </button>
            </form>
        </div>
    </div>
</div>

</body>
</html>