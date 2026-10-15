<?php
require_once '../../config/auth.php';
requireDoctorLogin();
require_once '../../config/redis.php';

$doctorId = $_SESSION['doctor_id'];
$doctorName = $_SESSION['doctor_name'] ?? 'Doctor';
$department = $_SESSION['doctor_department'] ?? 'General Medicine';
$departmentKey = $_SESSION['doctor_department_key'] ?? 'general-medicine';
$specialization = $_SESSION['doctor_specialization'] ?? 'Physician';

$date = date('Y-m-d');
$queueKey = 'queue:' . $departmentKey . ':' . $date;
$currentServingKey = 'queue:current:' . $departmentKey . ':' . $date;

// Read queue for doctor's assigned department
$tokenIds = safeRedisLRange($redis, $queueKey, 0, -1);

$waitingCount = 0;
$servingCount = 0;
$completedCount = 0;
$skippedCount = 0;

$currentToken = null;
$waitingPatients = [];

// Check currently serving for this doctor or this department
$servingTokenId = safeRedisGet($redis, 'doctor:serving:' . $doctorId) ?? safeRedisGet($redis, $currentServingKey);

foreach ($tokenIds as $tid) {
    $tok = safeRedisHGetAll($redis, 'token:' . $tid);
    if (empty($tok)) {
        continue;
    }

    $st = $tok['status'] ?? 'Waiting';
    $rRisk = $tok['risk_level'] ?? $tok['priority'] ?? 'Normal';
    $priorityVal = getRiskPriorityValue($rRisk);

    if ($st === 'Waiting') {
        $waitingCount++;
        $waitingPatients[] = [
            'id'         => $tid,
            'data'       => $tok,
            'priority'   => $priorityVal,
            'created_at' => $tok['created_at'] ?? ''
        ];
    } elseif ($st === 'Serving') {
        $servingCount++;
        $currentToken = $tok;
    } elseif ($st === 'Completed') {
        $completedCount++;
    } elseif ($st === 'Skipped') {
        $skippedCount++;
    }
}

// Fallback to check specific serving token ID
if (!$currentToken && $servingTokenId) {
    $stData = safeRedisHGetAll($redis, 'token:' . $servingTokenId);
    if (!empty($stData) && ($stData['status'] ?? '') === 'Serving') {
        $currentToken = $stData;
        $servingCount = 1;
    }
}

// Sort waiting patients by Risk Priority (Emergency -> High -> Normal) then FIFO
usort($waitingPatients, function ($a, $b) {
    if ($a['priority'] !== $b['priority']) {
        return $a['priority'] <=> $b['priority'];
    }
    return strcasecmp($a['created_at'], $b['created_at']);
});

$totalPatientsToday = count($tokenIds);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Dashboard | HospitalCare</title>
    <link rel="stylesheet" href="/hospital-queue-system/assets/css/style.css?v=201">
</head>
<body>

<div class="doctor-layout">

    <!-- Unified Doctor Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="brand-logo small">H</div>
            <div>
                <strong>HospitalCare</strong>
                <span>Doctor Portal</span>
            </div>
        </div>

        <nav class="sidebar-nav">
            <a href="dashboard.php" class="nav-link active">Dashboard</a>
            <a href="queue.php" class="nav-link">Department Queue</a>
        </nav>

        <div class="sidebar-bottom">
            <div class="user-profile">
                <div class="profile-avatar">D</div>
                <div>
                    <strong><?= htmlspecialchars($doctorName) ?></strong>
                    <span><?= htmlspecialchars($department) ?></span>
                </div>
            </div>
            <a href="logout.php" class="logout-link">Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-header">
            <div>
                <div class="eyebrow">Clinical Consultation Dashboard</div>
                <h1>Welcome, <?= htmlspecialchars($doctorName) ?></h1>
                <p>
                    Assigned Clinical Department: <strong><?= htmlspecialchars($department) ?></strong> 
                    &bull; Specialization: <strong><?= htmlspecialchars($specialization) ?></strong>
                </p>
            </div>
            <div style="font-size:13px; color:var(--text-muted); background:#ffffff; border:1px solid var(--border-color); padding:8px 14px; border-radius:var(--radius-sm);">
                📅 Today: <strong><?= date('d M Y') ?></strong>
            </div>
        </header>

        <!-- Department Queue Counters -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Department Patients</div>
                <div class="stat-value"><?= $totalPatientsToday ?></div>
            </div>
            <div class="stat-card waiting">
                <div class="stat-label">Waiting for Consultation</div>
                <div class="stat-value" style="color:#b45309;"><?= $waitingCount ?></div>
            </div>
            <div class="stat-card serving">
                <div class="stat-label">Currently in Consultation</div>
                <div class="stat-value" style="color:var(--primary);"><?= $servingCount ?></div>
            </div>
            <div class="stat-card completed">
                <div class="stat-label">Completed Today</div>
                <div class="stat-value" style="color:var(--success);"><?= $completedCount ?></div>
            </div>
        </div>

        <!-- Currently Serving Patient Banner -->
        <?php if ($currentToken): ?>
            <?php
            $cRisk = $currentToken['risk_level'] ?? $currentToken['priority'] ?? 'Normal';
            $cRiskBadge = ($cRisk === 'Emergency') ? 'badge-emergency' : (($cRisk === 'High') ? 'badge-high' : 'badge-normal');
            ?>
            <div class="serving-banner">
                <div class="serving-banner-info">
                    <h3>Active Consultation &mdash; <?= htmlspecialchars($department) ?></h3>
                    <div class="serving-banner-token">
                        <?= htmlspecialchars($currentToken['token_label'] ?? $currentToken['token_number'] ?? $currentToken['id']) ?>
                    </div>
                    <div class="serving-banner-details">
                        <span>👤 Patient: <strong><?= htmlspecialchars($currentToken['patient_name'] ?? '—') ?></strong> (ID: <?= htmlspecialchars($currentToken['patient_id'] ?? '') ?>)</span>
                        <span>⏱️ Called at: <strong><?= htmlspecialchars(substr($currentToken['called_at'] ?? '', 11, 8)) ?></strong></span>
                        <span>Risk Triage: <span class="badge <?= $cRiskBadge ?>"><?= htmlspecialchars($cRisk) ?></span></span>
                    </div>
                </div>

                <div class="serving-banner-actions">
                    <a href="queue.php" class="btn btn-success">
                        Manage Patient Consultation →
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="content-card" style="padding:22px 28px; border-left:4px solid var(--primary);">
                <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px;">
                    <div>
                        <h2 style="font-size:17px; margin-bottom:4px;">No Patient Currently in Consultation</h2>
                        <p style="color:var(--text-muted); font-size:13px;">
                            You have <strong><?= $waitingCount ?></strong> patient(s) waiting in <?= htmlspecialchars($department) ?>.
                        </p>
                    </div>
                    <a href="queue.php" class="btn btn-primary">
                        Go to Queue to Call Next →
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Upcoming Waiting Patients (Triage Order) -->
        <div class="content-card">
            <div class="card-header">
                <div>
                    <h2>Upcoming Patients (Priority Triage Order)</h2>
                    <p>Emergency cases prioritized first, followed by High Risk and Normal FIFO cases</p>
                </div>
                <span class="record-count"><?= $waitingCount ?> Waiting</span>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Token</th>
                            <th>Patient Name</th>
                            <th>Patient ID</th>
                            <th>Triage Risk</th>
                            <th>Arrival Time</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($waitingPatients)): ?>
                            <tr>
                                <td colspan="7" class="empty-state">No waiting patients in <?= htmlspecialchars($department) ?> queue.</td>
                            </tr>
                        <?php else: ?>
                            <?php $rank = 1; ?>
                            <?php foreach (array_slice($waitingPatients, 0, 5) as $wp): ?>
                                <?php
                                $rRisk = $wp['data']['risk_level'] ?? $wp['data']['priority'] ?? 'Normal';
                                $riskClass = ($rRisk === 'Emergency') ? 'badge-emergency' : (($rRisk === 'High') ? 'badge-high' : 'badge-normal');
                                ?>
                                <tr>
                                    <td><span class="badge" style="background:var(--bg-subtle); color:var(--text-navy);">#<?= $rank++ ?></span></td>
                                    <td><strong style="color:var(--primary); font-size:15px;"><?= htmlspecialchars($wp['data']['token_label'] ?? $wp['data']['token_number'] ?? $wp['id']) ?></strong></td>
                                    <td><strong><?= htmlspecialchars($wp['data']['patient_name'] ?? '—') ?></strong></td>
                                    <td><?= htmlspecialchars($wp['data']['patient_id'] ?? '—') ?></td>
                                    <td><span class="badge <?= $riskClass ?>"><?= htmlspecialchars($rRisk) ?></span></td>
                                    <td style="color:var(--text-muted); font-size:12.5px;"><?= htmlspecialchars(substr($wp['data']['created_at'] ?? '', 11, 8)) ?></td>
                                    <td>
                                        <a href="queue.php" class="btn btn-secondary btn-sm">
                                            Open Queue →
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

</div>

</body>
</html>