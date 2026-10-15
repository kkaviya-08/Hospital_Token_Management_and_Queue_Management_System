<?php
session_start();
require_once __DIR__ . '/../config/redis.php';

// Fetch the 4 authorized active departments from Redis
$departments = getAllActiveDepartments($redis);

// Get real-time stats safely
$today = date('Y-m-d');
$totalWaiting = 0;
$totalServing = 0;
$currentServingToken = null;

try {
    $queueKeys = $redis->keys('queue:*:*');
    foreach ($queueKeys as $qk) {
        if (strpos($qk, ':' . $today) !== false && safeRedisType($redis, $qk) === 'list') {
            $tokenIds = $redis->lrange($qk, 0, -1);
            foreach ($tokenIds as $tid) {
                $tData = safeRedisHGetAll($redis, 'token:' . $tid);
                $st = $tData['status'] ?? '';
                if ($st === 'Waiting') {
                    $totalWaiting++;
                } elseif ($st === 'Serving') {
                    $totalServing++;
                    if (!$currentServingToken) {
                        $currentServingToken = $tData['token_label'] ?? $tData['token_number'] ?? $tid;
                    }
                }
            }
        }
    }
} catch (Exception $e) {
    error_log("Index stats error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HospitalCare - Hospital Token &amp; Queue Management System</title>
    <link rel="stylesheet" href="/hospital-queue-system/assets/css/style.css?v=202">
</head>
<body>

<header class="public-navbar">
    <a href="index.php" class="public-logo">
        <div class="brand-logo small">H</div>
        <span>HospitalCare</span>
    </a>

    <nav class="public-nav-links">
        <a href="#portals">Portals</a>
        <a href="#departments">Departments</a>
        <a href="patient/login.php" class="btn btn-secondary btn-sm">Patient Sign In</a>
        <a href="patient/register.php" class="btn btn-primary btn-sm">New Patient</a>
    </nav>
</header>

<main class="standalone-page">

    <!-- System Banner -->
    <div class="content-card" style="margin-top:10px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
            <div>
                <div class="eyebrow">Outpatient Department (OPD) System</div>
                <h1 style="font-size:22px; color:var(--text-navy); font-weight:700;">Hospital Token &amp; Queue Management System</h1>
                <p style="color:var(--text-muted); font-size:13.5px; margin-top:4px;">
                    PHP 8.2 &amp; Redis in-memory token issuance, priority triage ranking, and clinical consultation management.
                </p>
            </div>
            <div style="display:flex; gap:10px; align-items:center;">
                <div style="padding:10px 14px; background:var(--bg-subtle); border:1px solid var(--border-color); border-radius:var(--radius-sm); text-align:center;">
                    <span style="font-size:11px; text-transform:uppercase; color:var(--text-muted); font-weight:600; display:block;">Waiting Patients</span>
                    <strong style="font-size:18px; color:var(--warning);"><?= $totalWaiting ?></strong>
                </div>
                <div style="padding:10px 14px; background:var(--bg-subtle); border:1px solid var(--border-color); border-radius:var(--radius-sm); text-align:center;">
                    <span style="font-size:11px; text-transform:uppercase; color:var(--text-muted); font-weight:600; display:block;">Now Serving</span>
                    <strong style="font-size:18px; color:var(--primary);"><?= htmlspecialchars($currentServingToken ?? 'None') ?></strong>
                </div>
            </div>
        </div>
    </div>

    <!-- Authorized Hospital Portals -->
    <div id="portals" style="margin-bottom:20px;">
        <div class="card-header" style="margin-bottom:12px; padding-bottom:8px;">
            <div>
                <h2 style="font-size:17px; color:var(--text-navy);">Hospital Access Portals</h2>
                <p style="font-size:12.5px; color:var(--text-muted);">Select the appropriate module for your role</p>
            </div>
        </div>

        <div class="portals-grid">
            <div class="portal-card">
                <div class="portal-icon">Staff</div>
                <h3>Hospital Staff Portal</h3>
                <p>Register walk-in patients, issue priority tokens, filter queues by date and department, and call patients.</p>
                <div style="margin-top:auto;">
                    <a href="staff/login.php" class="btn btn-primary" style="width:100%;">Staff Login</a>
                </div>
            </div>

            <div class="portal-card">
                <div class="portal-icon">Doctor</div>
                <h3>Doctor Consultation Portal</h3>
                <p>View department-assigned patient queues, review clinical priority levels, call next patient, and complete visits.</p>
                <div style="margin-top:auto;">
                    <a href="doctor/login.php" class="btn btn-primary" style="width:100%;">Doctor Login</a>
                </div>
            </div>

            <div class="portal-card">
                <div class="portal-icon">Admin</div>
                <h3>Administrator Portal</h3>
                <p>Manage hospital departments, doctor and staff credentials, and audit the complete registered patient directory.</p>
                <div style="margin-top:auto;">
                    <a href="admin/login.php" class="btn btn-secondary" style="width:100%;">Admin Login</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Patient Self-Service Actions -->
    <div class="content-card" style="margin-bottom:20px;">
        <div class="card-header" style="margin-bottom:12px;">
            <div>
                <h2>Patient Self-Service Queue Access</h2>
                <p>Patients can register an account, obtain tokens online, and monitor their live position in the queue.</p>
            </div>
        </div>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <a href="patient/register.php" class="btn btn-primary">Register New Patient</a>
            <a href="patient/login.php" class="btn btn-secondary">Patient Account Sign In</a>
            <a href="patient/get-token.php" class="btn btn-secondary">Get Department Token</a>
        </div>
    </div>

    <!-- Clinical Departments Directory (Strictly 4 Departments) -->
    <div id="departments" class="content-card">
        <div class="card-header" style="margin-bottom:12px;">
            <div>
                <h2>Clinical Outpatient Departments</h2>
                <p>Active clinical consultation departments configured in the hospital system</p>
            </div>
            <span class="record-count"><?= count($departments) ?> Departments</span>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px;">
            <?php foreach ($departments as $dept): ?>
                <div style="border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:12px 14px; background:#ffffff;">
                    <div style="font-weight:700; color:var(--text-navy); font-size:14px;">
                        <?= htmlspecialchars($dept['name']) ?>
                    </div>
                    <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">
                        <?= htmlspecialchars($dept['description'] ?? 'Clinical department') ?>
                    </div>
                    <div style="margin-top:8px;">
                        <span class="badge badge-completed">Active</span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</main>

<footer class="public-footer">
    <div>
        <strong>HospitalCare</strong> &mdash; Hospital Token &amp; Queue Management System
    </div>
    <div>
        PHP 8.2 &bull; Redis Fast NoSQL Store
    </div>
</footer>

</body>
</html>