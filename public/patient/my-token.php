<?php
require_once '../../config/auth.php';
requirePatientLogin();
require_once '../../config/redis.php';

$patientId = getPatientId();
$patient = safeRedisHGetAll($redis, 'patient:' . $patientId);
$patientName = $patient['name'] ?? getPatientName();

$tokenId = trim($_GET['token'] ?? '') ?: safeRedisGet($redis, 'patient:active-token:' . $patientId);

$token = null;
$position = 0;
$patientsAhead = 0;
$currentServingToken = null;

if ($tokenId) {
    $token = safeRedisHGetAll($redis, 'token:' . $tokenId);

    if (!empty($token) && isset($token['department_key'])) {
        $deptKey = $token['department_key'];
        $date = $token['date'] ?? date('Y-m-d');
        $queueKey = 'queue:' . $deptKey . ':' . $date;

        // Current serving for this department
        $currentServingId = safeRedisGet($redis, 'queue:current:' . $deptKey . ':' . $date);
        if ($currentServingId) {
            $curData = safeRedisHGetAll($redis, 'token:' . $currentServingId);
            if (!empty($curData) && ($curData['status'] ?? '') === 'Serving') {
                $currentServingToken = $curData['token_label'] ?? $curData['token_number'] ?? $currentServingId;
            }
        }

        // Calculate accurate position in waiting priority queue
        if (($token['status'] ?? '') === 'Waiting') {
            $allTokenIds = safeRedisLRange($redis, $queueKey, 0, -1);
            $waitingList = [];

            foreach ($allTokenIds as $tid) {
                $tData = safeRedisHGetAll($redis, 'token:' . $tid);
                if (empty($tData) || ($tData['status'] ?? '') !== 'Waiting') {
                    continue;
                }
                $rRisk = $tData['risk_level'] ?? $tData['priority'] ?? 'Normal';
                $pVal = getRiskPriorityValue($rRisk);

                $waitingList[] = [
                    'id'         => $tid,
                    'priority'   => $pVal,
                    'created_at' => $tData['created_at'] ?? ''
                ];
            }

            // Sort: Priority 1 (Emergency) -> 2 (High) -> 3 (Normal), then FIFO
            usort($waitingList, function ($a, $b) {
                if ($a['priority'] !== $b['priority']) {
                    return $a['priority'] <=> $b['priority'];
                }
                return strcasecmp($a['created_at'], $b['created_at']);
            });

            foreach ($waitingList as $index => $item) {
                if ($item['id'] === $tokenId) {
                    $position = $index + 1;
                    $patientsAhead = $index;
                    break;
                }
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
    <title>My Token | HospitalCare</title>
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
    <div style="margin-top:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <a href="dashboard.php" class="back-link">&larr; Back to Dashboard</a>
        <button onclick="window.location.reload()" class="btn btn-secondary btn-sm">
            🔄 Refresh Queue Status
        </button>
    </div>

    <?php if (!$token): ?>
        <div class="content-card" style="margin-top:20px; text-align:center; padding:50px 20px;">
            <h2>No Active Token Found</h2>
            <p style="color:var(--text-muted); margin:10px 0 20px;">You do not currently have an active outpatient token.</p>
            <a href="get-token.php" class="btn btn-primary">
                + Get a New Token
            </a>
        </div>
    <?php else: ?>
        <?php
        $st = $token['status'] ?? 'Waiting';
        $tLabel = $token['token_label'] ?? $token['token_number'] ?? $token['id'];
        $stBadge = ($st === 'Serving') ? 'badge-serving' : (($st === 'Completed') ? 'badge-completed' : (($st === 'Skipped') ? 'badge-skipped' : 'badge-waiting'));
        $rRisk = $token['risk_level'] ?? $token['priority'] ?? 'Normal';
        $riskBadge = ($rRisk === 'Emergency') ? 'badge-emergency' : (($rRisk === 'High') ? 'badge-high' : 'badge-normal');
        ?>

        <!-- Token Header Hero -->
        <div class="content-card" style="margin-top:20px; border:2px solid var(--primary); padding:32px;">
            <div class="card-header">
                <div>
                    <span class="badge <?= $stBadge ?>" style="font-size:12px; margin-bottom:6px;"><?= htmlspecialchars($st) ?></span>
                    <h2 style="font-size:24px;">Outpatient Token Live Status</h2>
                    <p>Department: <strong><?= htmlspecialchars($token['department'] ?? '') ?></strong></p>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Triage Priority</div>
                    <span class="badge <?= $riskBadge ?>" style="margin-top:4px; font-size:12.5px;"><?= htmlspecialchars($rRisk) ?></span>
                </div>
            </div>

            <!-- Big Token Display -->
            <div style="text-align:center; padding:24px 0 10px; background:var(--bg-subtle); border-radius:var(--radius-md); margin-bottom:24px;">
                <div style="font-size:13px; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Your Assigned Token Number</div>
                <div style="font-size:52px; font-weight:800; color:var(--primary); line-height:1.15; letter-spacing:-1px;">
                    <?= htmlspecialchars($tLabel) ?>
                </div>
                <div style="color:var(--text-navy); font-size:15px; font-weight:600; margin-top:6px;">
                    <?= htmlspecialchars($token['patient_name'] ?? $patientName) ?> (ID: <?= htmlspecialchars($token['patient_id'] ?? $patientId) ?>)
                </div>
            </div>

            <!-- Queue Progress Metrics -->
            <div class="stats-grid" style="margin-bottom:0;">
                <div class="stat-card serving">
                    <div class="stat-label">Currently Serving</div>
                    <div class="stat-value" style="color:var(--primary); font-size:26px;">
                        <?= htmlspecialchars($currentServingToken ?? 'None') ?>
                    </div>
                </div>

                <div class="stat-card <?= ($position > 0) ? 'waiting' : '' ?>">
                    <div class="stat-label">Your Queue Position</div>
                    <div class="stat-value" style="color:var(--text-navy); font-size:26px;">
                        <?= ($st === 'Waiting' && $position > 0) ? "#{$position}" : (($st === 'Serving') ? 'NOW!' : 'Done') ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Patients Ahead of You</div>
                    <div class="stat-value" style="color:#b45309; font-size:26px;">
                        <?= ($st === 'Waiting') ? $patientsAhead : 0 ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Issued Time</div>
                    <div class="stat-value" style="font-size:22px;">
                        <?= htmlspecialchars(substr($token['created_at'] ?? '', 11, 8)) ?>
                    </div>
                </div>
            </div>

            <?php if ($st === 'Serving'): ?>
                <div class="alert success" style="margin-top:20px; font-size:15px; justify-content:center; text-align:center;">
                    🎉 <strong>YOUR TOKEN IS NOW BEING CALLED!</strong> Please proceed immediately to the consultation room.
                </div>
            <?php elseif ($st === 'Completed'): ?>
                <div class="alert info" style="margin-top:20px; font-size:14px; justify-content:center;">
                    ✓ Your consultation has been completed. Thank you for visiting HospitalCare!
                </div>
            <?php elseif ($st === 'Skipped'): ?>
                <div class="alert error" style="margin-top:20px; font-size:14px; justify-content:center;">
                    ⚠️ Your token was skipped. Please speak with reception staff to be re-queued.
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>