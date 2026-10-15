<?php
require_once '../../config/auth.php';
requireAdminLogin();
require_once '../../config/redis.php';

$adminName = $_SESSION['admin_name'] ?? 'Administrator';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $key = strtolower($name);
        $key = preg_replace('/[^a-z0-9]+/', '-', $key);
        $key = trim($key, '-');

        if ($name === '') {
            $error = 'Department name is required.';
        } elseif ($key === '') {
            $error = 'Invalid department name.';
        } elseif ($redis->exists('department:' . $key)) {
            $error = 'A department with this name or slug already exists.';
        } else {
            // Find next department ID
            $deptKeys = $redis->keys('department:*');
            $deptCount = 0;
            foreach ($deptKeys as $dk) {
                if (strpos($dk, ':doctors') === false && safeRedisType($redis, $dk) === 'hash') {
                    $deptCount++;
                }
            }
            $departmentId = 'D' . str_pad($deptCount + 1, 3, '0', STR_PAD_LEFT);

            $data = [
                'id'          => $departmentId,
                'name'        => $name,
                'key'         => $key,
                'description' => $description !== '' ? $description : ($name . ' department'),
                'status'      => 'Active',
                'created_at'  => date('Y-m-d H:i:s')
            ];

            $redis->hmset('department:' . $key, $data);
            $message = "Department '{$name}' created successfully (ID: {$departmentId}).";
        }
    }

    if ($action === 'toggle') {
        $key = trim($_POST['department_key'] ?? '');
        $deptRedisKey = 'department:' . $key;

        if ($redis->exists($deptRedisKey) && safeRedisType($redis, $deptRedisKey) === 'hash') {
            $currentStatus = $redis->hget($deptRedisKey, 'status') ?? 'Active';
            $newStatus = (strcasecmp($currentStatus, 'Active') === 0) ? 'Inactive' : 'Active';
            $redis->hset($deptRedisKey, 'status', $newStatus);
            $message = "Department status changed to {$newStatus}.";
        } else {
            $error = 'Department not found.';
        }
    }

    if ($action === 'delete') {
        $key = trim($_POST['department_key'] ?? '');
        $deptRedisKey = 'department:' . $key;

        if ($redis->exists($deptRedisKey) && safeRedisType($redis, $deptRedisKey) === 'hash') {
            $redis->del($deptRedisKey);
            // Also clean up doctor relations set if exists
            $doctorsSetKey = 'department:' . $key . ':doctors';
            if ($redis->exists($doctorsSetKey)) {
                $redis->del($doctorsSetKey);
            }
            $message = 'Department deleted successfully.';
        } else {
            $error = 'Department not found.';
        }
    }
}

// Read all departments safely
$departmentKeys = $redis->keys('department:*');
$departments = [];

foreach ($departmentKeys as $key) {
    if (strpos($key, ':doctors') !== false) {
        continue;
    }
    if (safeRedisType($redis, $key) !== 'hash') {
        continue;
    }
    $department = safeRedisHGetAll($redis, $key);
    if (!empty($department) && isset($department['name'])) {
        $departments[] = $department;
    }
}

// Sort alphabetically
usort($departments, function ($a, $b) {
    return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Departments | Admin</title>
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
            <a href="dashboard.php" class="nav-link">Dashboard</a>
            <a href="doctors.php" class="nav-link">Doctors</a>
            <a href="staff.php" class="nav-link">Hospital Staff</a>
            <a href="departments.php" class="nav-link active">Departments</a>
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
                <div class="eyebrow">Hospital Configuration</div>
                <h1>Clinical Departments</h1>
                <p>Manage clinical departments. Departments created here automatically appear across staff token generation and doctor queues.</p>
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

        <!-- Create Department Card -->
        <div class="content-card">
            <div class="card-header">
                <div>
                    <h2>Create New Department</h2>
                    <p>Enter the department name and clinical scope</p>
                </div>
            </div>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="action" value="add">

                <div class="form-grid">
                    <div class="form-group">
                        <label>Department Name *</label>
                        <input type="text" name="name" placeholder="e.g. Cardiology, Neurology, Orthopedics" required>
                    </div>

                    <div class="form-group">
                        <label>Description / Clinical Notes</label>
                        <input type="text" name="description" placeholder="e.g. Heart health, ECG, cardiovascular care">
                    </div>

                    <div class="form-actions full">
                        <button type="submit" class="btn btn-primary">
                            + Add Department
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Registered Departments Table -->
        <div class="content-card">
            <div class="card-header">
                <div>
                    <h2>Registered Clinical Departments</h2>
                    <p>Current active departments in the hospital system</p>
                </div>
                <span class="record-count"><?= count($departments) ?> Departments</span>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Dept ID</th>
                            <th>Department Name</th>
                            <th>Redis Key Slug</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($departments)): ?>
                            <tr>
                                <td colspan="6" class="empty-state">No departments registered yet. Use the form above to add one.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($departments as $dept): ?>
                                <?php $isActive = (strcasecmp($dept['status'] ?? 'Active', 'Active') === 0); ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($dept['id'] ?? '—') ?></strong></td>
                                    <td><strong><?= htmlspecialchars($dept['name'] ?? '') ?></strong></td>
                                    <td><code>department:<?= htmlspecialchars($dept['key'] ?? '') ?></code></td>
                                    <td><?= htmlspecialchars($dept['description'] ?? '—') ?></td>
                                    <td>
                                        <span class="badge <?= $isActive ? 'badge-completed' : 'badge-skipped' ?>">
                                            <?= htmlspecialchars($dept['status'] ?? 'Active') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="department_key" value="<?= htmlspecialchars($dept['key'] ?? '') ?>">
                                                <button type="submit" class="btn btn-secondary btn-sm" title="Toggle Active/Inactive">
                                                    <?= $isActive ? 'Deactivate' : 'Activate' ?>
                                                </button>
                                            </form>

                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this department?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="department_key" value="<?= htmlspecialchars($dept['key'] ?? '') ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" title="Delete department">
                                                    Delete
                                                </button>
                                            </form>
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