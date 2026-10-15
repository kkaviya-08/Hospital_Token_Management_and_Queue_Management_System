<?php
require_once '../../config/auth.php';
requireDoctorLogin();
require_once '../../config/redis.php';

$doctorId = $_SESSION['doctor_id'];
$doctorName = $_SESSION['doctor_name'] ?? 'Doctor';
$department = $_SESSION['doctor_department'] ?? 'General Medicine';
$departmentKey = $_SESSION['doctor_department_key'] ?? 'general-medicine';

$date = date('Y-m-d');
$queueKey = 'queue:' . $departmentKey . ':' . $date;
$currentServingKey = 'queue:current:' . $departmentKey . ':' . $date;
$doctorServingKey = 'doctor:serving:' . $doctorId;

$message = '';
$error = '';

// Handle Consultation Actions (Call Next, Complete, Skip)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    $tokenId = trim($_POST['token_id'] ?? '');

    // ACTION: CALL NEXT PATIENT
    if ($action === 'call_next') {
        // Check if doctor is already serving a patient
        $currentServingId = safeRedisGet($redis, $doctorServingKey) ?? safeRedisGet($redis, $currentServingKey);

        if ($currentServingId) {
            $curData = safeRedisHGetAll($redis, 'token:' . $currentServingId);
            if (($curData['status'] ?? '') === 'Serving') {
                $curLabel = $curData['token_label'] ?? $curData['token_number'] ?? $currentServingId;
                $error = "Please complete or skip currently serving patient ({$curLabel}) before calling another patient.";
            }
        }

        if ($error === '') {
            $tokenIds = safeRedisLRange($redis, $queueKey, 0, -1);
            $waitingCandidates = [];

            foreach ($tokenIds as $tid) {
                $tok = safeRedisHGetAll($redis, 'token:' . $tid);
                if (empty($tok) || ($tok['status'] ?? '') !== 'Waiting') {
                    continue;
                }
                $rRisk = $tok['risk_level'] ?? $tok['priority'] ?? 'Normal';
                $priorityVal = getRiskPriorityValue($rRisk);

                $waitingCandidates[] = [
                    'id'         => $tid,
                    'data'       => $tok,
                    'priority'   => $priorityVal,
                    'created_at' => $tok['created_at'] ?? ''
                ];
            }

            // Sort: Emergency (1) -> High (2) -> Normal (3), then FIFO by created_at
            usort($waitingCandidates, function ($a, $b) {
                if ($a['priority'] !== $b['priority']) {
                    return $a['priority'] <=> $b['priority'];
                }
                return strcasecmp($a['created_at'], $b['created_at']);
            });

            if (empty($waitingCandidates)) {
                $error = 'There are no waiting patients in your department queue.';
            } else {
                $nextPatient = $waitingCandidates[0];
                $nextTokenId = $nextPatient['id'];
                $nextTokenKey = 'token:' . $nextTokenId;

                $now = date('Y-m-d H:i:s');
                $redis->hset($nextTokenKey, 'status', 'Serving');
                $redis->hset($nextTokenKey, 'called_at', $now);
                $redis->hset($nextTokenKey, 'called_by', $doctorId);

                // Update both doctor-specific serving and department-wide serving keys
                $redis->set($doctorServingKey, $nextTokenId);
                $redis->set($currentServingKey, $nextTokenId);

                $label = $nextPatient['data']['token_label'] ?? $nextPatient['data']['token_number'] ?? $nextTokenId;
                $message = "Now consulting Token {$label} ({$nextPatient['data']['patient_name']}).";
            }
        }
    }

    // ACTION: COMPLETE CONSULTATION
    if ($action === 'complete' && $tokenId !== '') {
        $tokenKey = 'token:' . $tokenId;
        $tok = safeRedisHGetAll($redis, $tokenKey);

        if (!empty($tok)) {
            $now = date('Y-m-d H:i:s');
            $redis->hset($tokenKey, 'status', 'Completed');
            $redis->hset($tokenKey, 'completed_at', $now);
            $redis->hset($tokenKey, 'completed_by', $doctorId);

            // Clear serving keys
            if (safeRedisGet($redis, $doctorServingKey) === $tokenId) {
                $redis->del($doctorServingKey);
            }
            if (safeRedisGet($redis, $currentServingKey) === $tokenId) {
                $redis->del($currentServingKey);
            }

            // Clear patient active token
            $pId = $tok['patient_id'] ?? '';
            if ($pId !== '') {
                $redis->del('patient:active-token:' . $pId);
            }

            $label = $tok['token_label'] ?? $tok['token_number'] ?? $tokenId;
            $message = "Patient consultation for Token {$label} marked as Completed.";
        } else {
            $error = 'Token not found.';
        }
    }

    // ACTION: SKIP PATIENT
    if ($action === 'skip' && $tokenId !== '') {
        $tokenKey = 'token:' . $tokenId;
        $tok = safeRedisHGetAll($redis, $tokenKey);

        if (empty($tok)) {
            $error = 'Token not found.';
        } elseif (($tok['status'] ?? '') === 'Completed') {
            $error = 'Completed tokens cannot be skipped.';
        } elseif (($tok['status'] ?? '') === 'Skipped') {
            $error = 'Token is already marked as skipped.';
        } else {
            $now = date('Y-m-d H:i:s');
            $redis->hset($tokenKey, 'status', 'Skipped');
            $redis->hset($tokenKey, 'skipped_at', $now);
            $redis->hset($tokenKey, 'skipped_by', $doctorId);

            // Remove from active queue waiting list
            $redis->lrem($queueKey, 0, $tokenId);

            // Clear serving keys
            if (safeRedisGet($redis, $doctorServingKey) === $tokenId) {
                $redis->del($doctorServingKey);
            }
            if (safeRedisGet($redis, $currentServingKey) === $tokenId) {
                $redis->del($currentServingKey);
            }

            // Clear patient active token
            $pId = $tok['patient_id'] ?? '';
            if ($pId !== '') {
                $redis->del('patient:active-token:' . $pId);
            }

            // Track in skipped set for rapid retrieval
            $redis->sadd("skipped:{$departmentKey}:{$date}", $tokenId);

            $label = $tok['token_label'] ?? $tok['token_number'] ?? $tokenId;
            $message = "Token {$label} has been marked as Skipped.";
        }
    }

    // ACTION: DELETE PATIENT (Doctor has permission to delete patient records)
    if ($action === 'delete_patient') {
        $patientId = trim($_POST['patient_id'] ?? '');
        $patientKey = 'patient:' . $patientId;

        if ($redis->exists($patientKey) && safeRedisType($redis, $patientKey) === 'hash') {
            $deleted = deletePatientRecord($redis, $patientId);
            if ($deleted) {
                $message = 'Patient deleted successfully.';
            } else {
                $error = 'Failed to delete patient record.';
            }
        } else {
            $error = 'Patient record not found.';
        }
    }
}

// Read current queue state
$tokenIds = safeRedisLRange($redis, $queueKey, 0, -1);

$waitingPatients = [];
$completedPatients = [];
$skippedPatients = [];
$currentToken = null;
$currentServingPatientData = null;

$servingId = safeRedisGet($redis, $doctorServingKey) ?? safeRedisGet($redis, $currentServingKey);

foreach ($tokenIds as $tid) {
    $tok = safeRedisHGetAll($redis, 'token:' . $tid);
    if (empty($tok)) {
        continue;
    }

    $st = $tok['status'] ?? 'Waiting';
    $rRisk = $tok['risk_level'] ?? $tok['priority'] ?? 'Normal';
    $priorityVal = getRiskPriorityValue($rRisk);

    $tokItem = [
        'id'         => $tid,
        'data'       => $tok,
        'priority'   => $priorityVal,
        'created_at' => $tok['created_at'] ?? ''
    ];

    if ($st === 'Waiting') {
        $waitingPatients[] = $tokItem;
    } elseif ($st === 'Serving') {
        $currentToken = $tok;
    } elseif ($st === 'Completed') {
        $completedPatients[] = $tokItem;
    } elseif ($st === 'Skipped') {
        $skippedPatients[] = $tokItem;
    }
}

if (!$currentToken && $servingId) {
    $stData = safeRedisHGetAll($redis, 'token:' . $servingId);
    if (!empty($stData) && ($stData['status'] ?? '') === 'Serving') {
        $currentToken = $stData;
    }
}

// Fetch full patient demographic details for currently serving patient
if ($currentToken && !empty($currentToken['patient_id'])) {
    $currentServingPatientData = safeRedisHGetAll($redis, 'patient:' . $currentToken['patient_id']);
}

// Sort waiting patients by Risk Priority (Emergency -> High -> Normal) then FIFO
usort($waitingPatients, function ($a, $b) {
    if ($a['priority'] !== $b['priority']) {
        return $a['priority'] <=> $b['priority'];
    }
    return strcasecmp($a['created_at'], $b['created_at']);
});

$waitingCount = count($waitingPatients);
$completedCount = count($completedPatients);
$skippedCount = count($skippedPatients);
$servingCount = $currentToken ? 1 : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Queue Management | HospitalCare</title>
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
            <a href="dashboard.php" class="nav-link">Dashboard</a>
            <a href="queue.php" class="nav-link active">Department Queue</a>
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
                <div class="eyebrow">Assigned Clinical Department: <?= htmlspecialchars($department) ?></div>
                <h1>Consultation Queue</h1>
                <p>Manage patient flow, review medical information, and call patients in priority triage order.</p>
            </div>
            <div style="display:flex; align-items:center; gap:12px;">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="call_next">
                    <button type="submit" class="btn btn-primary btn-lg" style="box-shadow:0 4px 12px rgba(37, 99, 235, 0.25);">
                        📢 Call Next Patient
                    </button>
                </form>
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

        <!-- Queue Status Summary Grid -->
        <div class="stats-grid">
            <div class="stat-card waiting">
                <div class="stat-label">Waiting Patients</div>
                <div class="stat-value" style="color:#b45309;"><?= $waitingCount ?></div>
            </div>
            <div class="stat-card serving">
                <div class="stat-label">In Consultation</div>
                <div class="stat-value" style="color:var(--primary);"><?= $servingCount ?></div>
            </div>
            <div class="stat-card completed">
                <div class="stat-label">Completed Today</div>
                <div class="stat-value" style="color:var(--success);"><?= $completedCount ?></div>
            </div>
            <div class="stat-card skipped">
                <div class="stat-label">Skipped Today</div>
                <div class="stat-value" style="color:var(--danger);"><?= $skippedCount ?></div>
            </div>
        </div>

        <!-- Currently Serving Consultation Card -->
        <?php if ($currentToken): ?>
            <?php
            $cRisk = $currentToken['risk_level'] ?? $currentToken['priority'] ?? 'Normal';
            $cRiskBadge = ($cRisk === 'Emergency') ? 'badge-emergency' : (($cRisk === 'High') ? 'badge-high' : 'badge-normal');
            ?>
            <div class="content-card" style="border:2px solid var(--primary); padding:28px; background:#ffffff;">
                <div class="card-header" style="border-bottom:1px solid var(--border-light); padding-bottom:16px;">
                    <div>
                        <span class="badge badge-serving" style="font-size:12px; margin-bottom:6px;">Active Consultation</span>
                        <h2 style="font-size:22px;">Patient Consultation in Progress</h2>
                    </div>

                    <div class="actions">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="complete">
                            <input type="hidden" name="token_id" value="<?= htmlspecialchars($currentToken['id'] ?? '') ?>">
                            <button type="submit" class="btn btn-success">
                                Complete Consultation
                            </button>
                        </form>

                        <form method="POST" style="display:inline;" onsubmit="return confirm('Skip this patient token?');">
                            <input type="hidden" name="action" value="skip">
                            <input type="hidden" name="token_id" value="<?= htmlspecialchars($currentToken['id'] ?? '') ?>">
                            <button type="submit" class="btn btn-danger">
                                Skip Patient
                            </button>
                        </form>

                        <?php if (!empty($currentToken['patient_id'])): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to permanently delete this patient record?');">
                                <input type="hidden" name="action" value="delete_patient">
                                <input type="hidden" name="patient_id" value="<?= htmlspecialchars($currentToken['patient_id'] ?? '') ?>">
                                <button type="submit" class="btn btn-secondary" style="color:var(--danger); border-color:var(--danger-border);">
                                    Delete Patient
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:20px; margin-top:20px;">
                    <div>
                        <div style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Token Number</div>
                        <div style="font-size:32px; font-weight:800; color:var(--primary); line-height:1.2;">
                            <?= htmlspecialchars($currentToken['token_label'] ?? $currentToken['token_number'] ?? $currentToken['id']) ?>
                        </div>
                        <div style="margin-top:6px;">
                            <span class="badge <?= $cRiskBadge ?>" style="font-size:12px;">
                                <?= htmlspecialchars($cRisk) ?> Priority
                            </span>
                        </div>
                    </div>

                    <div>
                        <div style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Patient Name &amp; ID</div>
                        <strong style="font-size:18px; color:var(--text-navy); display:block;">
                            <?= htmlspecialchars($currentToken['patient_name'] ?? '—') ?>
                        </strong>
                        <span style="color:var(--text-muted); font-size:13px;">Patient ID: <?= htmlspecialchars($currentToken['patient_id'] ?? '—') ?></span>
                    </div>

                    <div>
                        <div style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Demographics</div>
                        <div style="font-size:14px; color:var(--text-navy); margin-top:4px;">
                            Age: <strong><?= htmlspecialchars($currentServingPatientData['age'] ?? '—') ?> yrs</strong> &bull;
                            Gender: <strong><?= htmlspecialchars($currentServingPatientData['gender'] ?? '—') ?></strong>
                        </div>
                        <div style="font-size:13px; color:var(--text-muted); margin-top:2px;">
                            Phone: <?= htmlspecialchars($currentServingPatientData['phone'] ?? '—') ?>
                        </div>
                    </div>

                    <div>
                        <div style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Consultation Timeline</div>
                        <div style="font-size:13px; color:var(--text-body); margin-top:4px;">
                            Called: <strong><?= htmlspecialchars(substr($currentToken['called_at'] ?? '', 11, 8)) ?></strong>
                        </div>
                        <div style="font-size:13px; color:var(--text-muted); margin-top:2px;">
                            Issued: <?= htmlspecialchars(substr($currentToken['created_at'] ?? '', 11, 8)) ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="content-card" style="padding:22px 28px; border-left:4px solid var(--border-color);">
                <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px;">
                    <div>
                        <h2 style="font-size:17px; margin-bottom:4px;">No Patient Currently in Consultation</h2>
                        <p style="color:var(--text-muted); font-size:13px;">
                            Ready to examine the next patient? Click "Call Next Patient" to admit the top priority case.
                        </p>
                    </div>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="call_next">
                        <button type="submit" class="btn btn-primary">
                            Call Next Patient
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Waiting Patients Priority Queue -->
        <div class="content-card">
            <div class="card-header">
                <div>
                    <h2>Waiting Patients (Priority Triage Order)</h2>
                    <p>Sorted by clinical triage: Emergency cases first, followed by High Risk, then Normal FIFO</p>
                </div>
                <span class="record-count"><?= $waitingCount ?> Waiting</span>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Call Order</th>
                            <th>Token</th>
                            <th>Patient Name</th>
                            <th>Patient ID</th>
                            <th>Triage Risk</th>
                            <th>Waiting Since</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($waitingPatients)): ?>
                            <tr>
                                <td colspan="8" class="empty-state">
                                    No patients currently waiting in <?= htmlspecialchars($department) ?> queue.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $rank = 1; ?>
                            <?php foreach ($waitingPatients as $wp): ?>
                                <?php
                                $wRisk = $wp['data']['risk_level'] ?? $wp['data']['priority'] ?? 'Normal';
                                $riskClass = ($wRisk === 'Emergency') ? 'badge-emergency' : (($wRisk === 'High') ? 'badge-high' : 'badge-normal');
                                ?>
                                <tr>
                                    <td>
                                        <span class="badge" style="background:var(--bg-subtle); color:var(--text-navy); font-weight:700;">
                                            #<?= $rank++ ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong style="color:var(--primary); font-size:15px;">
                                            <?= htmlspecialchars($wp['data']['token_label'] ?? $wp['data']['token_number'] ?? $wp['id']) ?>
                                        </strong>
                                    </td>
                                    <td><strong><?= htmlspecialchars($wp['data']['patient_name'] ?? '—') ?></strong></td>
                                    <td><?= htmlspecialchars($wp['data']['patient_id'] ?? '—') ?></td>
                                    <td>
                                        <span class="badge <?= $riskClass ?>">
                                            <?= htmlspecialchars($wRisk) ?>
                                        </span>
                                    </td>
                                    <td style="color:var(--text-muted); font-size:12.5px;">
                                        <?= htmlspecialchars(substr($wp['data']['created_at'] ?? '', 11, 8)) ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-waiting">Waiting</span>
                                    </td>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this patient record?');">
                                            <input type="hidden" name="action" value="delete_patient">
                                            <input type="hidden" name="patient_id" value="<?= htmlspecialchars($wp['data']['patient_id'] ?? '') ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">
                                                Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Today's Completed Consultations -->
        <div class="content-card">
            <div class="card-header">
                <div>
                    <h2>Today's Completed Consultations</h2>
                    <p>Patients who have completed consultation today</p>
                </div>
                <span class="record-count"><?= $completedCount ?> Completed</span>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Token</th>
                            <th>Patient Name</th>
                            <th>Patient ID</th>
                            <th>Priority</th>
                            <th>Completed At</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($completedPatients)): ?>
                            <tr>
                                <td colspan="7" class="empty-state">No completed consultations yet today.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($completedPatients as $cp): ?>
                                <?php
                                $cTok = $cp['data'];
                                $cRisk = $cTok['risk_level'] ?? $cTok['priority'] ?? 'Normal';
                                $cRiskBadge = ($cRisk === 'Emergency') ? 'badge-emergency' : (($cRisk === 'High') ? 'badge-high' : 'badge-normal');
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($cTok['token_label'] ?? $cTok['token_number'] ?? $cTok['id']) ?></strong></td>
                                    <td><?= htmlspecialchars($cTok['patient_name'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($cTok['patient_id'] ?? '—') ?></td>
                                    <td><span class="badge <?= $cRiskBadge ?>"><?= htmlspecialchars($cRisk) ?></span></td>
                                    <td style="color:var(--text-muted); font-size:12.5px;">
                                        <?= htmlspecialchars(substr($cTok['completed_at'] ?? '', 11, 8)) ?>
                                    </td>
                                    <td><span class="badge badge-completed">Completed</span></td>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this patient record?');">
                                            <input type="hidden" name="action" value="delete_patient">
                                            <input type="hidden" name="patient_id" value="<?= htmlspecialchars($cTok['patient_id'] ?? '') ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">
                                                Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Today's Skipped Patients -->
        <div class="content-card">
            <div class="card-header">
                <div>
                    <h2>Today's Skipped Patients</h2>
                    <p>Tokens that were called and skipped during consultation (Patient records remain intact)</p>
                </div>
                <span class="record-count"><?= $skippedCount ?> Skipped</span>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Token</th>
                            <th>Patient Name</th>
                            <th>Patient ID</th>
                            <th>Priority</th>
                            <th>Skipped At</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($skippedPatients)): ?>
                            <tr>
                                <td colspan="7" class="empty-state">No skipped patients recorded today.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($skippedPatients as $sp): ?>
                                <?php
                                $sTok = $sp['data'];
                                $sRisk = $sTok['risk_level'] ?? $sTok['priority'] ?? 'Normal';
                                $sRiskBadge = ($sRisk === 'Emergency') ? 'badge-emergency' : (($sRisk === 'High') ? 'badge-high' : 'badge-normal');
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($sTok['token_label'] ?? $sTok['token_number'] ?? $sTok['id']) ?></strong></td>
                                    <td><?= htmlspecialchars($sTok['patient_name'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($sTok['patient_id'] ?? '—') ?></td>
                                    <td><span class="badge <?= $sRiskBadge ?>"><?= htmlspecialchars($sRisk) ?></span></td>
                                    <td style="color:var(--text-muted); font-size:12.5px;">
                                        <?= htmlspecialchars(substr($sTok['skipped_at'] ?? '', 11, 8)) ?>
                                    </td>
                                    <td><span class="badge badge-skipped">Skipped</span></td>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to permanently delete this patient record?');">
                                            <input type="hidden" name="action" value="delete_patient">
                                            <input type="hidden" name="patient_id" value="<?= htmlspecialchars($sTok['patient_id'] ?? '') ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">
                                                Delete
                                            </button>
                                        </form>
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