<?php
require_once '../../config/auth.php';
requireStaffLogin();
require_once '../../config/redis.php';

$staffName = $_SESSION['staff_name'] ?? 'Staff Member';
$staffRole = $_SESSION['staff_role'] ?? 'Receptionist';

$message = '';
$error = '';
$generatedToken = null;

// Preselect patient if passed in URL
$preselectedPatientId = trim($_GET['patient_id'] ?? '');

// 1. Fetch active patients from Redis
$patients = [];
$patientKeys = $redis->keys('patient:P*');

foreach ($patientKeys as $key) {
    if (safeRedisType($redis, $key) === 'hash') {
        $patient = $redis->hgetall($key);
        if (!empty($patient) && isset($patient['id'])) {
            $status = strtolower(trim($patient['status'] ?? 'active'));
            if ($status === 'active') {
                $patients[] = $patient;
            }
        }
    }
}

usort($patients, function ($a, $b) {
    return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
});

// 2. Fetch active departments dynamically from Redis
$departments = getAllActiveDepartments($redis);

// 3. Process token generation form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patientId = trim($_POST['patient_id'] ?? '');
    $departmentKey = trim($_POST['department_key'] ?? '');
    $riskLevel = trim($_POST['risk_level'] ?? 'Normal');

    if (!in_array($riskLevel, ['Emergency', 'High', 'Normal'], true)) {
        $riskLevel = 'Normal';
    }

    if ($patientId === '' || $departmentKey === '') {
        $error = 'Please select both a patient and a clinical department.';
    } else {
        $patient = safeRedisHGetAll($redis, 'patient:' . $patientId);
        $department = safeRedisHGetAll($redis, 'department:' . $departmentKey);

        if (empty($patient)) {
            $error = 'The selected patient does not exist.';
        } elseif (empty($department)) {
            $error = 'The selected department does not exist.';
        } else {
            // Check for existing active token
            $activeTokenKey = 'patient:active-token:' . $patientId;
            $existingTokenId = safeRedisGet($redis, $activeTokenKey);

            if ($existingTokenId) {
                $existingToken = safeRedisHGetAll($redis, 'token:' . $existingTokenId);
                $existingStatus = $existingToken['status'] ?? '';

                if (in_array($existingStatus, ['Waiting', 'Serving'], true)) {
                    $tokenLabel = $existingToken['token_label'] ?? $existingToken['token_number'] ?? $existingTokenId;
                    $error = "This patient already has an active token ({$tokenLabel}) with status '{$existingStatus}'. Please complete or resolve it first.";
                } else {
                    // Token is Completed or Skipped; remove stale reference
                    $redis->del($activeTokenKey);
                }
            }

            if ($error === '') {
                $date = date('Y-m-d');

                // Department prefix (first 3 letters uppercase, e.g. CAR, GEN, NEU)
                $prefixClean = preg_replace('/[^A-Za-z]/', '', $departmentKey);
                $tokenPrefix = strtoupper(substr($prefixClean, 0, 3));
                if (strlen($tokenPrefix) < 3) {
                    $tokenPrefix = str_pad($tokenPrefix, 3, 'X');
                }

                // Increment department daily counter safely
                $tokenNum = getNextDepartmentTokenNumber($redis, $departmentKey, $date);
                $tokenLabel = $tokenPrefix . '-' . str_pad($tokenNum, 3, '0', STR_PAD_LEFT);

                // Unique token ID
                $tokenId = 'T' . date('YmdHis') . $tokenNum . rand(10, 99);
                $priorityValue = getRiskPriorityValue($riskLevel);

                $tokenData = [
                    'id'             => $tokenId,
                    'patient_id'     => $patientId,
                    'patient_name'   => $patient['name'] ?? '',
                    'department'     => $department['name'] ?? ucfirst($departmentKey),
                    'department_key' => $departmentKey,
                    'token_number'   => (string)$tokenNum,
                    'token_label'    => $tokenLabel,
                    'risk_level'     => $riskLevel,
                    'priority'       => $riskLevel,
                    'priority_value' => (string)$priorityValue,
                    'status'         => 'Waiting',
                    'date'           => $date,
                    'created_at'     => date('Y-m-d H:i:s'),
                    'created_by'     => $_SESSION['staff_id']
                ];

                // 1. Store token hash in Redis
                $redis->hmset('token:' . $tokenId, $tokenData);

                // 2. Add to department queue list
                $queueKey = 'queue:' . $departmentKey . ':' . $date;
                $redis->rpush($queueKey, $tokenId);

                // 3. Set patient active token
                $redis->set($activeTokenKey, $tokenId);

                $message = "Token {$tokenLabel} generated successfully for {$patient['name']}.";
                $generatedToken = $tokenData;
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
    <title>Token Generation | HospitalCare</title>
    <link rel="stylesheet" href="/hospital-queue-system/assets/css/style.css?v=201">
</head>
<body>

<div class="staff-layout">

    <!-- Unified Staff Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="brand-logo small">H</div>
            <div>
                <strong>HospitalCare</strong>
                <span>Staff Portal</span>
            </div>
        </div>

        <nav class="sidebar-nav">
            <a href="dashboard.php" class="nav-link">Dashboard</a>
            <a href="patients.php" class="nav-link">Patients</a>
            <a href="token.php" class="nav-link active">Token Management</a>
            <a href="queue.php" class="nav-link">Queue Management</a>
        </nav>

        <div class="sidebar-bottom">
            <div class="staff-profile">
                <div class="profile-avatar">
                    <?= strtoupper(substr($staffName, 0, 1)) ?>
                </div>
                <div>
                    <strong><?= htmlspecialchars($staffName) ?></strong>
                    <span><?= htmlspecialchars($staffRole) ?></span>
                </div>
            </div>
            <a href="logout.php" class="logout-link">Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-header">
            <div>
                <div class="eyebrow">Outpatient Admission &amp; Triage</div>
                <h1>Token Generation</h1>
                <p>Issue clinical queue tokens with 3-tier risk-based triage priority (Emergency, High, Normal).</p>
            </div>
        </header>

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

        <!-- Success Token Print/Summary Card if just created -->
        <?php if ($generatedToken): ?>
            <div class="content-card" style="border:2px solid var(--primary-border); background:#fbfcfe;">
                <div class="card-header">
                    <div>
                        <h2>Token Issued Successfully</h2>
                        <p>Hand this token information to the patient or call next in queue</p>
                    </div>
                    <span class="badge badge-serving" style="font-size:13px; padding:6px 14px;">Active Token</span>
                </div>

                <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:20px; padding:18px 0; border-top:1px solid var(--border-light); border-bottom:1px solid var(--border-light);">
                    <div>
                        <div style="font-size:12px; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Token Number</div>
                        <div style="font-size:36px; font-weight:800; color:var(--primary); line-height:1.2;">
                            <?= htmlspecialchars($generatedToken['token_label']) ?>
                        </div>
                    </div>

                    <div>
                        <div style="font-size:12px; color:var(--text-muted);">Patient Name</div>
                        <strong style="font-size:16px; color:var(--text-navy);"><?= htmlspecialchars($generatedToken['patient_name']) ?></strong>
                        <div style="font-size:12px; color:var(--text-muted);">ID: <?= htmlspecialchars($generatedToken['patient_id']) ?></div>
                    </div>

                    <div>
                        <div style="font-size:12px; color:var(--text-muted);">Department</div>
                        <strong style="font-size:16px; color:var(--text-navy);"><?= htmlspecialchars($generatedToken['department']) ?></strong>
                    </div>

                    <div>
                        <div style="font-size:12px; color:var(--text-muted); margin-bottom:4px;">Triage Risk Priority</div>
                        <?php
                        $rRisk = $generatedToken['risk_level'];
                        $riskClass = ($rRisk === 'Emergency') ? 'badge-emergency' : (($rRisk === 'High') ? 'badge-high' : 'badge-normal');
                        ?>
                        <span class="badge <?= $riskClass ?>" style="font-size:13px; padding:6px 12px;">
                            <?= htmlspecialchars($rRisk) ?> Priority
                        </span>
                    </div>

                    <div>
                        <a href="queue.php?department=<?= urlencode($generatedToken['department_key']) ?>" class="btn btn-primary">
                            Go to Department Queue →
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Token Generation Form Card -->
        <div class="content-card">
            <div class="card-header">
                <div>
                    <h2>Generate New Queue Token</h2>
                    <p>Select registered patient, clinical department, and triage risk evaluation</p>
                </div>
            </div>

            <form method="POST" autocomplete="off">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Select Patient *</label>
                        <select name="patient_id" required>
                            <option value="">-- Choose Patient --</option>
                            <?php foreach ($patients as $p): ?>
                                <option value="<?= htmlspecialchars($p['id']) ?>" <?= ($preselectedPatientId === $p['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['name']) ?> (ID: <?= htmlspecialchars($p['id']) ?> | Phone: <?= htmlspecialchars($p['phone'] ?? '—') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div style="margin-top:6px; font-size:12px;">
                            Not found? <a href="patients.php" style="color:var(--primary); font-weight:600;">+ Register New Patient</a>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Select Clinical Department *</label>
                        <select name="department_key" required>
                            <option value="">-- Choose Department --</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= htmlspecialchars($dept['key']) ?>">
                                    <?= htmlspecialchars($dept['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label>Triage Risk Priority Level *</label>
                        <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:14px; margin-top:6px;">
                            <label style="border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:14px; display:flex; align-items:flex-start; gap:10px; cursor:pointer; background:#ffffff;">
                                <input type="radio" name="risk_level" value="Emergency" style="width:auto; min-height:auto; margin-top:2px;">
                                <div>
                                    <div style="font-weight:700; color:#b91c1c;">🚨 Emergency</div>
                                    <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">Critical condition. Called first before all others.</div>
                                </div>
                            </label>

                            <label style="border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:14px; display:flex; align-items:flex-start; gap:10px; cursor:pointer; background:#ffffff;">
                                <input type="radio" name="risk_level" value="High" style="width:auto; min-height:auto; margin-top:2px;">
                                <div>
                                    <div style="font-weight:700; color:#c2410c;">⚠️ High Risk</div>
                                    <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">Urgent care needed. Called ahead of normal cases.</div>
                                </div>
                            </label>

                            <label style="border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:14px; display:flex; align-items:flex-start; gap:10px; cursor:pointer; background:#ffffff;">
                                <input type="radio" name="risk_level" value="Normal" checked style="width:auto; min-height:auto; margin-top:2px;">
                                <div>
                                    <div style="font-weight:700; color:#334155;">🟢 Normal</div>
                                    <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">Standard outpatient consultation. FIFO order.</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="form-actions full">
                        <button type="submit" class="btn btn-primary btn-lg">
                            🎟️ Generate &amp; Issue Token
                        </button>
                    </div>
                </div>
            </form>
        </div>

    </main>

</div>

</body>
</html>