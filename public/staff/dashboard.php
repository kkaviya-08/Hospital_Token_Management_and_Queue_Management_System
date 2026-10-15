<?php
require_once '../../config/auth.php';
requireStaffLogin();
require_once '../../config/redis.php';

$staffName = $_SESSION['staff_name'] ?? 'Staff Member';
$staffRole = $_SESSION['staff_role'] ?? 'Receptionist';
$today = date('Y-m-d');

// 1. Total registered patients count
$patientKeys = $redis->keys('patient:P*');
$patientCount = 0;
foreach ($patientKeys as $k) {
    if (safeRedisType($redis, $k) === 'hash') {
        $patientCount++;
    }
}

// 2. Count today's token statuses across department queues
$waitingTokens = 0;
$servingTokens = 0;
$completedTokens = 0;
$skippedTokens = 0;
$departmentSummaries = [];
$recentTokens = [];

$allDepartments = getAllActiveDepartments($redis);

foreach ($allDepartments as $dept) {
    $deptKey = $dept['key'];
    $queueKey = 'queue:' . $deptKey . ':' . $today;

    $dWaiting = 0;
    $dServing = 0;
    $dCompleted = 0;
    $dCurrentToken = null;

    if (safeRedisType($redis, $queueKey) === 'list') {
        $tids = $redis->lrange($queueKey, 0, -1);
        foreach ($tids as $tid) {
            $tokenData = safeRedisHGetAll($redis, 'token:' . $tid);
            if (empty($tokenData)) {
                continue;
            }

            $st = $tokenData['status'] ?? 'Waiting';
            if ($st === 'Waiting') {
                $waitingTokens++;
                $dWaiting++;
            } elseif ($st === 'Serving') {
                $servingTokens++;
                $dServing++;
                if (!$dCurrentToken) {
                    $dCurrentToken = $tokenData['token_label'] ?? $tokenData['token_number'] ?? $tid;
                }
            } elseif ($st === 'Completed') {
                $completedTokens++;
                $dCompleted++;
            } elseif ($st === 'Skipped') {
                $skippedTokens++;
            }

            // Collect recent token
            $recentTokens[] = $tokenData;
        }
    }

    if ($dWaiting > 0 || $dServing > 0 || $dCompleted > 0) {
        $departmentSummaries[] = [
            'name'           => $dept['name'],
            'key'            => $deptKey,
            'waiting'        => $dWaiting,
            'serving'        => $dServing,
            'completed'      => $dCompleted,
            'current_token'  => $dCurrentToken ?? '—'
        ];
    }
}

// Sort recent tokens by created_at DESC, limit to top 6
usort($recentTokens, function ($a, $b) {
    return strcasecmp($b['created_at'] ?? '', $a['created_at'] ?? '');
});
$recentTokens = array_slice($recentTokens, 0, 6);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard | HospitalCare</title>
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
            <a href="dashboard.php" class="nav-link active">Dashboard</a>
            <a href="patients.php" class="nav-link">Patients</a>
            <a href="token.php" class="nav-link">Token Management</a>
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
                <div class="eyebrow">Front Desk &amp; Patient Services</div>
                <h1>Staff Operations Dashboard</h1>
                <p>Welcome, <strong><?= htmlspecialchars($staffName) ?></strong>. Manage outpatient admissions and real-time tokens.</p>
            </div>
            <div style="font-size:13px; color:var(--text-muted); background:#ffffff; border:1px solid var(--border-color); padding:8px 14px; border-radius:var(--radius-sm);">
                📅 Today: <strong><?= date('d M Y') ?></strong>
            </div>
        </header>

        <!-- Operational Statistics Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Patients</div>
                <div class="stat-value"><?= $patientCount ?></div>
            </div>
            <div class="stat-card waiting">
                <div class="stat-label">Waiting in Queue</div>
                <div class="stat-value" style="color:#b45309;"><?= $waitingTokens ?></div>
            </div>
            <div class="stat-card serving">
                <div class="stat-label">Currently Serving</div>
                <div class="stat-value" style="color:var(--primary);"><?= $servingTokens ?></div>
            </div>
            <div class="stat-card completed">
                <div class="stat-label">Completed Consults</div>
                <div class="stat-value" style="color:var(--success);"><?= $completedTokens ?></div>
            </div>
        </div>

        <!-- Quick Operational Actions -->
        <div class="content-card">
            <div class="card-header">
                <div>
                    <h2>Quick Actions</h2>
                    <p>Primary front-desk queue management operations</p>
                </div>
            </div>

            <div style="display:flex; gap:14px; flex-wrap:wrap;">
                <a href="patients.php" class="btn btn-secondary">
                    👤 Register New Patient
                </a>
                <a href="token.php" class="btn btn-primary">
                    🎟️ Generate Patient Token
                </a>
                <a href="queue.php" class="btn btn-secondary">
                    ⏳ Live Department Queue
                </a>
            </div>
        </div>

        <!-- Department Queue Status Today -->
        <div class="content-card">
            <div class="card-header">
                <div>
                    <h2>Department Queue Summary (Today)</h2>
                    <p>Real-time queue load across hospital departments</p>
                </div>
                <span class="record-count"><?= count($departmentSummaries) ?> Active Queues</span>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Currently Serving</th>
                            <th>Waiting Patients</th>
                            <th>Completed</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($departmentSummaries)): ?>
                            <tr>
                                <td colspan="5" class="empty-state">
                                    No tokens issued yet today. Generate tokens to begin department queues.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($departmentSummaries as $ds): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($ds['name']) ?></strong></td>
                                    <td>
                                        <span class="badge badge-serving"><?= htmlspecialchars($ds['current_token']) ?></span>
                                    </td>
                                    <td>
                                        <strong style="color:#b45309;"><?= $ds['waiting'] ?> waiting</strong>
                                    </td>
                                    <td>
                                        <span style="color:var(--success);"><?= $ds['completed'] ?> done</span>
                                    </td>
                                    <td>
                                        <a href="queue.php?department=<?= urlencode($ds['key']) ?>" class="btn btn-secondary btn-sm">
                                            Manage Queue →
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Tokens Generated -->
        <div class="content-card">
            <div class="card-header">
                <div>
                    <h2>Recently Generated Tokens Today</h2>
                    <p>Latest tokens created by reception</p>
                </div>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Token</th>
                            <th>Patient Name</th>
                            <th>Department</th>
                            <th>Risk Priority</th>
                            <th>Time</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentTokens)): ?>
                            <tr>
                                <td colspan="6" class="empty-state">No tokens issued today.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentTokens as $rt): ?>
                                <?php
                                $rRisk = $rt['risk_level'] ?? $rt['priority'] ?? 'Normal';
                                $riskClass = (strcasecmp($rRisk, 'Emergency') === 0) ? 'badge-emergency' : ((strcasecmp($rRisk, 'High') === 0) ? 'badge-high' : 'badge-normal');
                                $rStatus = $rt['status'] ?? 'Waiting';
                                $stClass = ($rStatus === 'Serving') ? 'badge-serving' : (($rStatus === 'Completed') ? 'badge-completed' : (($rStatus === 'Skipped') ? 'badge-skipped' : 'badge-waiting'));
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($rt['token_label'] ?? $rt['token_number'] ?? $rt['id']) ?></strong></td>
                                    <td><?= htmlspecialchars($rt['patient_name'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($rt['department'] ?? '—') ?></td>
                                    <td><span class="badge <?= $riskClass ?>"><?= htmlspecialchars($rRisk) ?></span></td>
                                    <td style="color:var(--text-muted); font-size:12.5px;"><?= htmlspecialchars(substr($rt['created_at'] ?? '', 11, 8)) ?></td>
                                    <td><span class="badge <?= $stClass ?>"><?= htmlspecialchars($rStatus) ?></span></td>
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