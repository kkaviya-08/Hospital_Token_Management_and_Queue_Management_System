<?php
require_once '../../config/auth.php';
requireStaffLogin();
require_once '../../config/redis.php';

$staffName = $_SESSION['staff_name'] ?? 'Staff Member';
$staffRole = $_SESSION['staff_role'] ?? 'Receptionist';

$message = '';
$error = '';

// 1. Fetch active departments dynamically from Redis (restricted to the 4 departments)
$departments = getAllActiveDepartments($redis);
$allowedDeptKeys = array_column($departments, 'key');

// 2. Validate and retrieve selected date (default: today)
$today = date('Y-m-d');
$selectedDate = trim($_GET['date'] ?? $_POST['date'] ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = $today;
} else {
    $dParts = explode('-', $selectedDate);
    if (!checkdate((int)$dParts[1], (int)$dParts[2], (int)$dParts[0])) {
        $selectedDate = $today;
    }
}

// 3. Validate and retrieve selected department
$selectedDepartment = trim($_GET['department'] ?? $_POST['department'] ?? '');
if (!in_array($selectedDepartment, $allowedDeptKeys, true)) {
    $selectedDepartment = !empty($allowedDeptKeys) ? $allowedDeptKeys[0] : 'general-medicine';
}

$deptHash = safeRedisHGetAll($redis, 'department:' . $selectedDepartment);
$selectedDepartmentName = $deptHash['name'] ?? ucfirst(str_replace('-', ' ', $selectedDepartment));

$queueKey = 'queue:' . $selectedDepartment . ':' . $selectedDate;
$currentServingKey = 'queue:current:' . $selectedDepartment . ':' . $selectedDate;

// 4. Handle POST Actions (call_next, complete, skip)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // ACTION: CALL NEXT PATIENT
    if ($action === 'call_next') {
        $existingCurrentId = safeRedisGet($redis, $currentServingKey);
        if ($existingCurrentId) {
            $curTok = safeRedisHGetAll($redis, 'token:' . $existingCurrentId);
            if (($curTok['status'] ?? '') === 'Serving') {
                $curLabel = $curTok['token_label'] ?? $curTok['token_number'] ?? $existingCurrentId;
                $error = "Please complete or skip currently serving token ({$curLabel}) before calling the next patient.";
            }
        }

        if ($error === '') {
            $tokenIds = safeRedisLRange($redis, $queueKey, 0, -1);
            $waitingItems = [];

            foreach ($tokenIds as $tid) {
                $tok = safeRedisHGetAll($redis, 'token:' . $tid);
                if (empty($tok) || ($tok['status'] ?? '') !== 'Waiting') {
                    continue;
                }
                $rRisk = $tok['risk_level'] ?? $tok['priority'] ?? 'Normal';
                $priorityVal = getRiskPriorityValue($rRisk);

                $waitingItems[] = [
                    'id'         => $tid,
                    'data'       => $tok,
                    'priority'   => $priorityVal,
                    'created_at' => $tok['created_at'] ?? ''
                ];
            }

            // Sort: Priority 1 (Emergency) -> 2 (High) -> 3 (Normal), then FIFO by created_at
            usort($waitingItems, function ($a, $b) {
                if ($a['priority'] !== $b['priority']) {
                    return $a['priority'] <=> $b['priority'];
                }
                return strcasecmp($a['created_at'], $b['created_at']);
            });

            if (empty($waitingItems)) {
                $error = 'There are no waiting patients in this department queue for the selected date.';
            } else {
                $nextCandidate = $waitingItems[0];
                $nextId = $nextCandidate['id'];
                $nextKey = 'token:' . $nextId;

                $now = date('Y-m-d H:i:s');
                $redis->hset($nextKey, 'status', 'Serving');
                $redis->hset($nextKey, 'called_at', $now);
                $redis->hset($nextKey, 'called_by', $_SESSION['staff_id']);

                $redis->set($currentServingKey, $nextId);

                $pName = $nextCandidate['data']['patient_name'] ?? 'Patient';
                $tLabel = $nextCandidate['data']['token_label'] ?? $nextCandidate['data']['token_number'] ?? $nextId;
                $message = "Now serving token {$tLabel} ({$pName}).";
            }
        }
    }

    // ACTION: COMPLETE CONSULTATION
    if ($action === 'complete') {
        $tokenId = trim($_POST['token_id'] ?? '');
        if ($tokenId === '') {
            $error = 'Invalid token specified.';
        } else {
            $tokenKey = 'token:' . $tokenId;
            $token = safeRedisHGetAll($redis, $tokenKey);

            if (empty($token)) {
                $error = 'Token not found in Redis.';
            } else {
                $now = date('Y-m-d H:i:s');
                $redis->hset($tokenKey, 'status', 'Completed');
                $redis->hset($tokenKey, 'completed_at', $now);
                $redis->hset($tokenKey, 'completed_by', $_SESSION['staff_id']);

                $curId = safeRedisGet($redis, $currentServingKey);
                if ($curId === $tokenId) {
                    $redis->del($currentServingKey);
                }

                $pId = $token['patient_id'] ?? '';
                if ($pId !== '') {
                    $redis->del('patient:active-token:' . $pId);
                }

                $tLabel = $token['token_label'] ?? $token['token_number'] ?? $tokenId;
                $message = "Token {$tLabel} consultation completed successfully.";
            }
        }
    }

    // ACTION: SKIP PATIENT
    if ($action === 'skip') {
        $tokenId = trim($_POST['token_id'] ?? '');
        if ($tokenId === '') {
            $error = 'Invalid token specified.';
        } else {
            $tokenKey = 'token:' . $tokenId;
            $token = safeRedisHGetAll($redis, $tokenKey);

            if (empty($token)) {
                $error = 'Token not found in Redis.';
            } elseif (($token['status'] ?? '') === 'Completed') {
                $error = 'Completed tokens cannot be skipped.';
            } elseif (($token['status'] ?? '') === 'Skipped') {
                $error = 'Token is already marked as skipped.';
            } else {
                $now = date('Y-m-d H:i:s');
                $redis->hset($tokenKey, 'status', 'Skipped');
                $redis->hset($tokenKey, 'skipped_at', $now);
                $redis->hset($tokenKey, 'skipped_by', $_SESSION['staff_id']);

                // Remove from active queue waiting list
                $tokDept = $token['department_key'] ?? $selectedDepartment;
                $tokDate = $token['date'] ?? $selectedDate;
                $activeQKey = 'queue:' . $tokDept . ':' . $tokDate;
                $redis->lrem($activeQKey, 0, $tokenId);

                // Clear current serving key if this token was serving
                $curId = safeRedisGet($redis, $currentServingKey);
                if ($curId === $tokenId) {
                    $redis->del($currentServingKey);
                }

                // Clear patient active token pointer so returning patient can receive a new token
                $pId = $token['patient_id'] ?? '';
                if ($pId !== '') {
                    $redis->del('patient:active-token:' . $pId);
                }

                // Add to skipped set for fast and reliable retrieval
                $redis->sadd('skipped:' . $tokDept . ':' . $tokDate, $tokenId);

                $tLabel = $token['token_label'] ?? $token['token_number'] ?? $tokenId;
                $message = "Token {$tLabel} has been marked as skipped.";
            }
        }
    }
}

// 5. Load full queue records for selected date and department
$allQueueTokens = [];
$waitingTokens = [];
$skippedTokens = [];
$completedCount = 0;
$servingToken = null;
$seenTokenIds = [];

// Read primary queue list key from Redis
$tokenIds = safeRedisLRange($redis, $queueKey, 0, -1);
foreach ($tokenIds as $tid) {
    $tok = safeRedisHGetAll($redis, 'token:' . $tid);
    if (empty($tok)) {
        continue;
    }
    $seenTokenIds[$tid] = true;

    if (empty($tok['patient_name']) && !empty($tok['patient_id'])) {
        $pData = safeRedisHGetAll($redis, 'patient:' . $tok['patient_id']);
        if (!empty($pData['name'])) {
            $tok['patient_name'] = $pData['name'];
        }
    }

    $st = $tok['status'] ?? 'Waiting';
    $rRisk = $tok['risk_level'] ?? $tok['priority'] ?? 'Normal';
    $priorityVal = getRiskPriorityValue($rRisk);

    $tokItem = [
        'id'         => $tid,
        'data'       => $tok,
        'priority'   => $priorityVal,
        'status'     => $st,
        'created_at' => $tok['created_at'] ?? ''
    ];

    $allQueueTokens[] = $tokItem;

    if ($st === 'Waiting') {
        $waitingTokens[] = $tokItem;
    } elseif ($st === 'Serving') {
        if (!$servingToken) {
            $servingToken = $tok;
        }
    } elseif ($st === 'Completed') {
        $completedCount++;
    } elseif ($st === 'Skipped') {
        $skippedTokens[] = $tokItem;
    }
}

// Read skipped set for selected department and date
$skippedIds = safeRedisSMembers($redis, 'skipped:' . $selectedDepartment . ':' . $selectedDate);
foreach ($skippedIds as $sId) {
    if (isset($seenTokenIds[$sId])) {
        continue;
    }
    $tok = safeRedisHGetAll($redis, 'token:' . $sId);
    if (empty($tok)) {
        continue;
    }
    $seenTokenIds[$sId] = true;

    if (empty($tok['patient_name']) && !empty($tok['patient_id'])) {
        $pData = safeRedisHGetAll($redis, 'patient:' . $tok['patient_id']);
        if (!empty($pData['name'])) {
            $tok['patient_name'] = $pData['name'];
        }
    }

    $st = $tok['status'] ?? 'Skipped';
    $rRisk = $tok['risk_level'] ?? $tok['priority'] ?? 'Normal';
    $priorityVal = getRiskPriorityValue($rRisk);

    $tokItem = [
        'id'         => $sId,
        'data'       => $tok,
        'priority'   => $priorityVal,
        'status'     => $st,
        'created_at' => $tok['created_at'] ?? ''
    ];

    $allQueueTokens[] = $tokItem;
    $skippedTokens[] = $tokItem;
}

// Check tokens directly matching department and date if any exist outside list/set
try {
    $allTokenKeys = $redis->keys('token:*');
    foreach ($allTokenKeys as $tk) {
        if (safeRedisType($redis, $tk) !== 'hash') {
            continue;
        }
        $tid = substr($tk, strlen('token:'));
        if (isset($seenTokenIds[$tid])) {
            continue;
        }
        $tok = $redis->hgetall($tk);
        $tokDept = $tok['department_key'] ?? '';
        if ($tokDept === '') {
            if (preg_match('/^token:([a-z0-9\-]+):/', $tk, $m)) {
                $tokDept = $m[1];
            }
        }
        $tokDate = $tok['date'] ?? '';
        if ($tokDate === '') {
            if (!empty($tok['created_at'])) {
                $tokDate = substr($tok['created_at'], 0, 10);
            } elseif (preg_match('/(\d{4}-\d{2}-\d{2})/', $tk, $m)) {
                $tokDate = $m[1];
            }
        }

        if ($tokDept === $selectedDepartment && $tokDate === $selectedDate) {
            $seenTokenIds[$tid] = true;
            if (empty($tok['patient_name']) && !empty($tok['patient_id'])) {
                $pData = safeRedisHGetAll($redis, 'patient:' . $tok['patient_id']);
                if (!empty($pData['name'])) {
                    $tok['patient_name'] = $pData['name'];
                }
            }
            $st = $tok['status'] ?? 'Waiting';
            $rRisk = $tok['risk_level'] ?? $tok['priority'] ?? 'Normal';
            $priorityVal = getRiskPriorityValue($rRisk);

            $tokItem = [
                'id'         => $tid,
                'data'       => $tok,
                'priority'   => $priorityVal,
                'status'     => $st,
                'created_at' => $tok['created_at'] ?? ''
            ];

            $allQueueTokens[] = $tokItem;

            if ($st === 'Waiting') {
                $waitingTokens[] = $tokItem;
            } elseif ($st === 'Serving' && !$servingToken) {
                $servingToken = $tok;
            } elseif ($st === 'Completed') {
                $completedCount++;
            } elseif ($st === 'Skipped') {
                $skippedTokens[] = $tokItem;
            }
        }
    }
} catch (Exception $e) {
    error_log("queue fallback scan: " . $e->getMessage());
}

// Sort waiting tokens by Risk Priority (Emergency=1, High=2, Normal=3) then FIFO
usort($waitingTokens, function ($a, $b) {
    if ($a['priority'] !== $b['priority']) {
        return $a['priority'] <=> $b['priority'];
    }
    return strcasecmp($a['created_at'], $b['created_at']);
});

// Sort skipped tokens by time skipped / created descending
usort($skippedTokens, function ($a, $b) {
    $timeA = $a['data']['skipped_at'] ?? $a['created_at'] ?? '';
    $timeB = $b['data']['skipped_at'] ?? $b['created_at'] ?? '';
    return strcasecmp($timeB, $timeA);
});

// Check current serving key if $servingToken not found yet
if (!$servingToken) {
    $curId = safeRedisGet($redis, $currentServingKey);
    if ($curId) {
        $t = safeRedisHGetAll($redis, 'token:' . $curId);
        if (!empty($t) && ($t['status'] ?? '') === 'Serving') {
            $servingToken = $t;
        }
    }
}

$waitingCount = count($waitingTokens);
$skippedCount = count($skippedTokens);
$servingCount = $servingToken ? 1 : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Queue Management | HospitalCare</title>
    <link rel="stylesheet" href="/hospital-queue-system/assets/css/style.css?v=202">
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
            <a href="token.php" class="nav-link">Token Management</a>
            <a href="queue.php" class="nav-link active">Queue Management</a>
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
                <div class="eyebrow">Outpatient Consultation Flow</div>
                <h1>Queue Management</h1>
                <p>Monitor patient queue status, filter records by date and department, and call next patients.</p>
            </div>
            <div style="font-size:12.5px; color:var(--text-muted); background:#ffffff; border:1px solid var(--border-color); padding:6px 12px; border-radius:var(--radius-sm);">
                Selected Date: <strong><?= htmlspecialchars(date('d M Y', strtotime($selectedDate))) ?></strong>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <div class="alert success">
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert error">
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- Date & Department Filter Section (Neatly aligned above queue table) -->
        <div class="content-card filter-card">
            <form method="GET" action="queue.php" class="filter-form">
                <div class="filter-field" style="min-width:160px;">
                    <label for="filter_date">Date</label>
                    <input type="date" id="filter_date" name="date" value="<?= htmlspecialchars($selectedDate) ?>" required>
                </div>

                <div class="filter-field" style="min-width:220px;">
                    <label for="filter_dept">Department</label>
                    <select id="filter_dept" name="department">
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= htmlspecialchars($dept['key']) ?>" <?= ($selectedDepartment === $dept['key']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field" style="flex-direction:row; gap:6px;">
                    <button type="submit" class="btn btn-primary">View Queue</button>
                    <?php if ($selectedDate !== $today): ?>
                        <a href="queue.php?department=<?= urlencode($selectedDepartment) ?>&date=<?= $today ?>" class="btn btn-secondary">
                            Today
                        </a>
                    <?php endif; ?>
                </div>

                <div style="margin-left:auto; display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
                    <button type="submit" form="callNextTopForm" class="btn btn-primary">
                        Call Next Patient
                    </button>
                    <a href="token.php?department_key=<?= urlencode($selectedDepartment) ?>" class="btn btn-secondary">
                        + Issue Token
                    </a>
                </div>
            </form>
            <form id="callNextTopForm" method="POST" style="display:none;">
                <input type="hidden" name="action" value="call_next">
                <input type="hidden" name="department" value="<?= htmlspecialchars($selectedDepartment) ?>">
                <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
            </form>
        </div>

        <!-- Selected Queue Summary Counters -->
        <div class="stats-grid">
            <div class="stat-card waiting">
                <div class="stat-label">Waiting in Queue</div>
                <div class="stat-value" style="color:var(--warning);"><?= $waitingCount ?></div>
            </div>
            <div class="stat-card serving">
                <div class="stat-label">Currently Serving</div>
                <div class="stat-value" style="color:var(--primary);"><?= $servingCount ?></div>
            </div>
            <div class="stat-card completed">
                <div class="stat-label">Completed Consultations</div>
                <div class="stat-value" style="color:var(--success);"><?= $completedCount ?></div>
            </div>
            <div class="stat-card skipped">
                <div class="stat-label">Skipped Tokens</div>
                <div class="stat-value" style="color:var(--danger);"><?= $skippedCount ?></div>
            </div>
        </div>

        <!-- Currently Serving Patient Panel -->
        <?php if ($servingToken): ?>
            <?php
            $sRisk = $servingToken['risk_level'] ?? $servingToken['priority'] ?? 'Normal';
            $sRiskBadge = ($sRisk === 'Emergency') ? 'badge-emergency' : (($sRisk === 'High') ? 'badge-high' : 'badge-normal');
            ?>
            <div class="serving-banner">
                <div class="serving-banner-info">
                    <h3>Currently in Consultation &mdash; <?= htmlspecialchars($selectedDepartmentName) ?></h3>
                    <div class="serving-banner-token" style="font-size:22px; font-weight:700; color:var(--primary);">
                        <?= htmlspecialchars($servingToken['token_label'] ?? $servingToken['token_number'] ?? $servingToken['id']) ?>
                    </div>
                    <div class="serving-banner-details" style="font-size:12.5px; display:flex; gap:14px; flex-wrap:wrap; margin-top:2px;">
                        <span>Patient: <strong><?= htmlspecialchars($servingToken['patient_name'] ?? '—') ?></strong> (ID: <?= htmlspecialchars($servingToken['patient_id'] ?? '') ?>)</span>
                        <span>Called at: <strong><?= htmlspecialchars(substr($servingToken['called_at'] ?? '', 11, 8)) ?></strong></span>
                        <span>Priority: <span class="badge <?= $sRiskBadge ?>"><?= htmlspecialchars($sRisk) ?></span></span>
                    </div>
                </div>

                <div class="serving-banner-actions">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="complete">
                        <input type="hidden" name="department" value="<?= htmlspecialchars($selectedDepartment) ?>">
                        <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
                        <input type="hidden" name="token_id" value="<?= htmlspecialchars($servingToken['id'] ?? '') ?>">
                        <button type="submit" class="btn btn-success">
                            Complete Consultation
                        </button>
                    </form>

                    <form method="POST" style="display:inline;" onsubmit="return confirm('Skip this patient token?');">
                        <input type="hidden" name="action" value="skip">
                        <input type="hidden" name="department" value="<?= htmlspecialchars($selectedDepartment) ?>">
                        <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
                        <input type="hidden" name="token_id" value="<?= htmlspecialchars($servingToken['id'] ?? '') ?>">
                        <button type="submit" class="btn btn-danger">
                            Skip Patient
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Waiting Patients Priority Queue Detail (When patients are waiting) -->
        <?php if (!empty($waitingTokens)): ?>
            <div class="content-card">
                <div class="card-header">
                    <div>
                        <h2>Waiting Patients &mdash; Triage Ranking</h2>
                        <p>Order of care: Emergency &gt; High &gt; Normal, then FIFO registration order</p>
                    </div>
                    <span class="record-count"><?= $waitingCount ?> Waiting</span>
                </div>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Queue Rank</th>
                                <th>Token Number</th>
                                <th>Patient Name</th>
                                <th>Patient ID</th>
                                <th>Triage Priority</th>
                                <th>Issued Time</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $rank = 1; ?>
                            <?php foreach ($waitingTokens as $item): ?>
                                <?php
                                $wTok = $item['data'];
                                $wRisk = $wTok['risk_level'] ?? $wTok['priority'] ?? 'Normal';
                                $wRiskBadge = ($wRisk === 'Emergency') ? 'badge-emergency' : (($wRisk === 'High') ? 'badge-high' : 'badge-normal');
                                $wLabel = $wTok['token_label'] ?? $wTok['token_number'] ?? $item['id'];
                                ?>
                                <tr>
                                    <td>
                                        <span class="badge" style="background:var(--bg-subtle); color:var(--text-navy); font-weight:700;">
                                            #<?= $rank++ ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong style="color:var(--primary); font-size:13.5px;">
                                            <?= htmlspecialchars($wLabel) ?>
                                        </strong>
                                    </td>
                                    <td><strong><?= htmlspecialchars($wTok['patient_name'] ?? '—') ?></strong></td>
                                    <td><?= htmlspecialchars($wTok['patient_id'] ?? '—') ?></td>
                                    <td>
                                        <span class="badge <?= $wRiskBadge ?>">
                                            <?= htmlspecialchars($wRisk) ?>
                                        </span>
                                    </td>
                                    <td style="color:var(--text-muted); font-size:12px;">
                                        <?= htmlspecialchars(substr($wTok['created_at'] ?? '', 11, 8)) ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-waiting">Waiting</span>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Skip waiting token <?= htmlspecialchars($wLabel) ?>?');">
                                                <input type="hidden" name="action" value="skip">
                                                <input type="hidden" name="department" value="<?= htmlspecialchars($selectedDepartment) ?>">
                                                <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
                                                <input type="hidden" name="token_id" value="<?= htmlspecialchars($item['id']) ?>">
                                                <button type="submit" class="btn btn-secondary btn-sm">Skip</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Dedicated Skipped Patients / Skip List Section -->
        <div class="content-card">
            <div class="card-header">
                <div>
                    <h2>Skipped Patients / Skip List &mdash; <?= htmlspecialchars($selectedDepartmentName) ?> (<?= htmlspecialchars($selectedDate) ?>)</h2>
                    <p>Patients who missed their consultation turn. Records preserved in Redis; re-issue new tokens when patient returns.</p>
                </div>
                <span class="record-count"><?= $skippedCount ?> Skipped</span>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Token Number</th>
                            <th>Patient Name</th>
                            <th>Department</th>
                            <th>Original Token Date</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($skippedTokens)): ?>
                            <tr>
                                <td colspan="7" class="empty-state">
                                    No skipped patients found for the selected date and department.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($skippedTokens as $item): ?>
                                <?php
                                $sTok = $item['data'];
                                $sLabel = $sTok['token_label'] ?? $sTok['token_number'] ?? $item['id'];
                                $sDept = $sTok['department'] ?? $selectedDepartmentName;
                                $sDate = $sTok['date'] ?? (!empty($sTok['created_at']) ? substr($sTok['created_at'], 0, 10) : $selectedDate);
                                $sRisk = $sTok['risk_level'] ?? $sTok['priority'] ?? 'Normal';
                                $sRiskBadge = ($sRisk === 'Emergency') ? 'badge-emergency' : (($sRisk === 'High') ? 'badge-high' : 'badge-normal');
                                $pId = $sTok['patient_id'] ?? '';
                                ?>
                                <tr>
                                    <td>
                                        <strong style="color:var(--text-muted); font-size:13.5px;">
                                            <?= htmlspecialchars($sLabel) ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($sTok['patient_name'] ?? '—') ?></strong>
                                        <?php if ($pId !== ''): ?>
                                            <span style="color:var(--text-muted); font-size:11.5px; display:block;">ID: <?= htmlspecialchars($pId) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($sDept) ?></td>
                                    <td>
                                        <span style="color:var(--text-body); font-weight:500;">
                                            <?= htmlspecialchars($sDate) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $sRiskBadge ?>">
                                            <?= htmlspecialchars($sRisk) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-skipped">Skipped</span>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <a href="token.php?patient_id=<?= urlencode($pId) ?>&department_key=<?= urlencode($selectedDepartment) ?>" class="btn btn-secondary btn-sm" title="Generate a fresh token for this returning patient">
                                                + Issue New Token
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- All Queue Records Table for Selected Date & Department -->
        <div class="content-card">
            <div class="card-header">
                <div>
                    <h2>Queue Records: <?= htmlspecialchars($selectedDepartmentName) ?> (<?= htmlspecialchars($selectedDate) ?>)</h2>
                    <p>All token records for the selected date and clinical department.</p>
                </div>
                <span class="record-count"><?= count($allQueueTokens) ?> Total Records</span>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Token Number</th>
                            <th>Patient Name</th>
                            <th>Department</th>
                            <th>Priority</th>
                            <th>Current Status</th>
                            <th>Time / Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allQueueTokens)): ?>
                            <tr>
                                <td colspan="7" class="empty-state">
                                    No patients found for the selected date and department.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allQueueTokens as $item): ?>
                                <?php
                                $tok = $item['data'];
                                $st = $item['status'];
                                $stBadge = ($st === 'Serving') ? 'badge-serving' : (($st === 'Completed') ? 'badge-completed' : (($st === 'Skipped') ? 'badge-skipped' : 'badge-waiting'));
                                $rRisk = $tok['risk_level'] ?? $tok['priority'] ?? 'Normal';
                                $rRiskBadge = ($rRisk === 'Emergency') ? 'badge-emergency' : (($rRisk === 'High') ? 'badge-high' : 'badge-normal');
                                $tokLabel = $tok['token_label'] ?? $tok['token_number'] ?? $tok['id'];
                                $tokDept = $tok['department'] ?? $selectedDepartmentName;
                                $timeStr = !empty($tok['created_at']) ? substr($tok['created_at'], 11, 8) : '—';
                                ?>
                                <tr>
                                    <td>
                                        <strong style="color:var(--primary); font-size:13.5px;">
                                            <?= htmlspecialchars($tokLabel) ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($tok['patient_name'] ?? '—') ?></strong>
                                        <?php if (!empty($tok['patient_id'])): ?>
                                            <span style="color:var(--text-muted); font-size:11.5px; display:block;">ID: <?= htmlspecialchars($tok['patient_id']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($tokDept) ?></td>
                                    <td>
                                        <span class="badge <?= $rRiskBadge ?>">
                                            <?= htmlspecialchars($rRisk) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $stBadge ?>">
                                            <?= htmlspecialchars($st) ?>
                                        </span>
                                    </td>
                                    <td style="color:var(--text-muted); font-size:12px;">
                                        <?= htmlspecialchars($timeStr) ?>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <?php if ($st === 'Serving'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="complete">
                                                    <input type="hidden" name="department" value="<?= htmlspecialchars($selectedDepartment) ?>">
                                                    <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
                                                    <input type="hidden" name="token_id" value="<?= htmlspecialchars($tok['id'] ?? '') ?>">
                                                    <button type="submit" class="btn btn-success btn-sm">Complete</button>
                                                </form>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Skip this token?');">
                                                    <input type="hidden" name="action" value="skip">
                                                    <input type="hidden" name="department" value="<?= htmlspecialchars($selectedDepartment) ?>">
                                                    <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
                                                    <input type="hidden" name="token_id" value="<?= htmlspecialchars($tok['id'] ?? '') ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">Skip</button>
                                                </form>
                                            <?php elseif ($st === 'Waiting'): ?>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Skip waiting token <?= htmlspecialchars($tokLabel) ?>?');">
                                                    <input type="hidden" name="action" value="skip">
                                                    <input type="hidden" name="department" value="<?= htmlspecialchars($selectedDepartment) ?>">
                                                    <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
                                                    <input type="hidden" name="token_id" value="<?= htmlspecialchars($tok['id'] ?? '') ?>">
                                                    <button type="submit" class="btn btn-secondary btn-sm">Skip</button>
                                                </form>
                                            <?php elseif ($st === 'Skipped'): ?>
                                                <a href="token.php?patient_id=<?= urlencode($tok['patient_id'] ?? '') ?>&department_key=<?= urlencode($selectedDepartment) ?>" class="btn btn-secondary btn-sm">
                                                    + Re-issue
                                                </a>
                                            <?php else: ?>
                                                <span style="color:var(--text-light); font-size:12px;">—</span>
                                            <?php endif; ?>
                                        </div>
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