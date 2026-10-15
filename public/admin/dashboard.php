<?php
require_once '../../config/auth.php';
requireAdminLogin();
require_once '../../config/redis.php';

$adminName = $_SESSION['admin_name'] ?? 'Administrator';

// Safely count doctors
$doctorKeys = $redis->keys('doctor:D*');
$doctorCount = 0;
foreach ($doctorKeys as $k) {
    if (safeRedisType($redis, $k) === 'hash') {
        $doctorCount++;
    }
}

// Safely count staff
$staffKeys = $redis->keys('staff:S*');
$staffCount = 0;
foreach ($staffKeys as $k) {
    if (safeRedisType($redis, $k) === 'hash') {
        $staffCount++;
    }
}

// Safely count patients
$patientKeys = $redis->keys('patient:P*');
$patientCount = 0;
foreach ($patientKeys as $k) {
    if (safeRedisType($redis, $k) === 'hash') {
        $patientCount++;
    }
}

// Safely count departments
$allDepts = getAllActiveDepartments($redis);
$departmentCount = count($allDepts);

// Count today's token statistics across all departments
$today = date('Y-m-d');
$todayWaiting = 0;
$todayServing = 0;
$todayCompleted = 0;
$todaySkipped = 0;

try {
    $queueKeys = $redis->keys('queue:*:*');
    foreach ($queueKeys as $qk) {
        if (strpos($qk, ':' . $today) !== false && safeRedisType($redis, $qk) === 'list') {
            $tids = $redis->lrange($qk, 0, -1);
            foreach ($tids as $tid) {
                $token = safeRedisHGetAll($redis, 'token:' . $tid);
                $st = $token['status'] ?? '';
                if ($st === 'Waiting') {
                    $todayWaiting++;
                } elseif ($st === 'Serving') {
                    $todayServing++;
                } elseif ($st === 'Completed') {
                    $todayCompleted++;
                } elseif ($st === 'Skipped') {
                    $todaySkipped++;
                }
            }
        }
    }
} catch (Exception $e) {
    error_log("Admin dashboard queue stats error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | HospitalCare</title>
    <link rel="stylesheet" href="/hospital-queue-system/assets/css/style.css?v=201">
</head>
<body>

<div class="admin-layout">

    <!-- Unified Admin Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="brand-logo small">H</div>
            <div>
                <strong>HospitalCare</strong>
                <span>Admin Portal</span>
            </div>
        </div>

        <nav class="sidebar-nav">
            <a href="dashboard.php" class="nav-link active">Dashboard</a>
            <a href="doctors.php" class="nav-link">Doctors</a>
            <a href="staff.php" class="nav-link">Hospital Staff</a>
            <a href="departments.php" class="nav-link">Departments</a>
            <a href="patients.php" class="nav-link">Patients</a>
        </nav>

        <div class="sidebar-bottom">
            <div class="user-profile">
                <div class="profile-avatar">A</div>
                <div>
                    <strong><?= htmlspecialchars($adminName) ?></strong>
                    <span>Administrator</span>
                </div>
            </div>
            <a href="logout.php" class="logout-link">Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-header">
            <div>
                <div class="eyebrow">Hospital Administration</div>
                <h1>System Overview</h1>
                <p>Welcome back, <strong><?= htmlspecialchars($adminName) ?></strong>. Manage hospital configurations and users.</p>
            </div>
            <div style="font-size:13px; color:var(--text-muted); background:#ffffff; border:1px solid var(--border-color); padding:8px 14px; border-radius:var(--radius-sm);">
                📅 Today: <strong><?= date('d M Y') ?></strong>
            </div>
        </header>

        <!-- System Totals Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Doctors</div>
                <div class="stat-value"><?= $doctorCount ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Hospital Staff</div>
                <div class="stat-value"><?= $staffCount ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Registered Patients</div>
                <div class="stat-value"><?= $patientCount ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active Departments</div>
                <div class="stat-value"><?= $departmentCount ?></div>
            </div>
        </div>

        <!-- Today's Hospital Queue Pulse -->
        <div class="content-card">
            <div class="card-header">
                <div>
                    <h2>Today's Real-Time Queue Activity</h2>
                    <p>Aggregated metrics across all clinical departments</p>
                </div>
                <span class="record-count">Date: <?= htmlspecialchars($today) ?></span>
            </div>

            <div class="stats-grid" style="margin-bottom:0;">
                <div class="stat-card waiting">
                    <div class="stat-label">Waiting Patients</div>
                    <div class="stat-value" style="color:#b45309;"><?= $todayWaiting ?></div>
                </div>
                <div class="stat-card serving">
                    <div class="stat-label">Currently Serving</div>
                    <div class="stat-value" style="color:var(--primary);"><?= $todayServing ?></div>
                </div>
                <div class="stat-card completed">
                    <div class="stat-label">Completed Consultations</div>
                    <div class="stat-value" style="color:var(--success);"><?= $todayCompleted ?></div>
                </div>
                <div class="stat-card skipped">
                    <div class="stat-label">Skipped Tokens</div>
                    <div class="stat-value" style="color:var(--danger);"><?= $todaySkipped ?></div>
                </div>
            </div>
        </div>

        <!-- Quick Administration Actions -->
        <div class="content-card">
            <div class="card-header">
                <div>
                    <h2>Quick Management Portals</h2>
                    <p>Direct shortcuts to administration modules</p>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px;">
                <a href="doctors.php" class="btn btn-secondary" style="justify-content:flex-start; height:auto; padding:16px; border-radius:var(--radius-md);">
                    <div>
                        <div style="font-size:15px; font-weight:700; color:var(--text-navy); margin-bottom:4px;">🩺 Manage Doctors</div>
                        <div style="font-size:12px; color:var(--text-muted);">Add new doctors and assign clinical departments</div>
                    </div>
                </a>
                <a href="staff.php" class="btn btn-secondary" style="justify-content:flex-start; height:auto; padding:16px; border-radius:var(--radius-md);">
                    <div>
                        <div style="font-size:15px; font-weight:700; color:var(--text-navy); margin-bottom:4px;">👥 Manage Hospital Staff</div>
                        <div style="font-size:12px; color:var(--text-muted);">Create reception and billing staff accounts</div>
                    </div>
                </a>
                <a href="departments.php" class="btn btn-secondary" style="justify-content:flex-start; height:auto; padding:16px; border-radius:var(--radius-md);">
                    <div>
                        <div style="font-size:15px; font-weight:700; color:var(--text-navy); margin-bottom:4px;">🏥 Clinical Departments</div>
                        <div style="font-size:12px; color:var(--text-muted);">Configure hospital departments and queues</div>
                    </div>
                </a>
                <a href="patients.php" class="btn btn-secondary" style="justify-content:flex-start; height:auto; padding:16px; border-radius:var(--radius-md);">
                    <div>
                        <div style="font-size:15px; font-weight:700; color:var(--text-navy); margin-bottom:4px;">👤 Patient Directory</div>
                        <div style="font-size:12px; color:var(--text-muted);">Audit registered hospital patients</div>
                    </div>
                </a>
            </div>
        </div>

    </main>

</div>

</body>
</html>