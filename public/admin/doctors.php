<?php
require_once '../../config/auth.php';
requireAdminLogin();
require_once '../../config/redis.php';

$adminName = $_SESSION['admin_name'] ?? 'Administrator';
$message = '';

$error = '';

function isValidPersonName(string $name): bool {
    return (bool) preg_match("/^[\\p{L}][\\p{L}\\s.'-]{1,79}$/u", $name)
        && preg_match('/\\p{L}/u', $name);
}

function isValidIndianMobile(string $phone): bool {
    return (bool) preg_match('/^[6-9][0-9]{9}$/', $phone);
}

function isValidEmailAddress(string $email): bool {
    return strlen($email) <= 254
        && !preg_match('/\\s/', $email)
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Load active departments from Redis for the dropdown
$activeDepartments = getAllActiveDepartments($redis);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $deptKey = trim($_POST['department_key'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $password = $_POST['password'] ?? '';

        if (
            $name === '' ||
            $email === '' ||
            $phone === '' ||
            $deptKey === '' ||
            $specialization === '' ||
            $password === ''
        ) {
            $error = 'Please fill all required fields.';
        } elseif (!isValidPersonName($name)) {
            $error = 'Please enter a valid name using 2–80 letters, spaces, periods, apostrophes, or hyphens.';
        } elseif (!isValidEmailAddress($email)) {
            $error = 'Please enter a valid email address.';
        } elseif (!isValidIndianMobile($phone)) {
            $error = 'Please enter a valid 10-digit Indian mobile number starting with 6, 7, 8, or 9.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($redis->exists('doctor:email:' . $email)) {
            $error = 'A doctor account with this email already exists.';
        } else {
            // Find selected department name from Redis
            $deptHash = safeRedisHGetAll($redis, 'department:' . $deptKey);
            $departmentName = $deptHash['name'] ?? ucfirst(str_replace('-', ' ', $deptKey));

            $doctorId = 'D' . strtoupper(substr(md5(uniqid('', true)), 0, 6));

            $doctorData = [
                'id'             => $doctorId,
                'name'           => $name,
                'email'          => $email,
                'phone'          => $phone,
                'department'     => $departmentName,
                'department_key' => $deptKey,
                'specialization' => $specialization,
                'password'       => password_hash($password, PASSWORD_DEFAULT),
                'status'         => 'Active',
                'created_at'     => date('Y-m-d H:i:s')
            ];

            $redis->hmset('doctor:' . $doctorId, $doctorData);
            $redis->set('doctor:email:' . $email, $doctorId);
            $redis->sadd('department:' . $deptKey . ':doctors', $doctorId);

            $message = "Doctor account for {$name} created successfully (ID: {$doctorId}).";
        }
    }

    if ($action === 'edit') {
        $doctorId = trim($_POST['doctor_id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $deptKey = trim($_POST['department_key'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $newPassword = $_POST['password'] ?? '';
        $status = trim($_POST['status'] ?? 'Active');
        if (!in_array($status, ['Active', 'Inactive'], true)) {
            $status = 'Active';
        }

        $doctorKey = 'doctor:' . $doctorId;
        if (!$redis->exists($doctorKey) || safeRedisType($redis, $doctorKey) !== 'hash') {
            $error = 'Doctor record not found.';
        } elseif (
            $name === '' ||
            $email === '' ||
            $phone === '' ||
            $deptKey === '' ||
            $specialization === ''
        ) {
            $error = 'Please fill all required fields.';
        } elseif (!isValidPersonName($name)) {
            $error = 'Please enter a valid name using 2–80 letters, spaces, periods, apostrophes, or hyphens.';
        } elseif (!isValidEmailAddress($email)) {
            $error = 'Please enter a valid email address.';
        } elseif (!isValidIndianMobile($phone)) {
            $error = 'Please enter a valid 10-digit Indian mobile number starting with 6, 7, 8, or 9.';
        } else {
            $currentDoc = $redis->hgetall($doctorKey);
            $currentEmail = strtolower(trim($currentDoc['email'] ?? ''));
            $currentDeptKey = trim($currentDoc['department_key'] ?? '');

            // Check if email changed and is taken by another doctor
            if ($email !== $currentEmail) {
                $existingDocId = safeRedisGet($redis, 'doctor:email:' . $email);
                if ($existingDocId && $existingDocId !== $doctorId) {
                    $error = 'A doctor account with this email already exists.';
                }
            }

            if ($error === '' && $newPassword !== '' && strlen($newPassword) < 6) {
                $error = 'Password must be at least 6 characters.';
            }

            if ($error === '') {
                // Find department name
                $deptHash = safeRedisHGetAll($redis, 'department:' . $deptKey);
                $departmentName = $deptHash['name'] ?? ucfirst(str_replace('-', ' ', $deptKey));

                // If department changed, update relation set
                if ($deptKey !== $currentDeptKey) {
                    if (!empty($currentDeptKey)) {
                        $redis->srem('department:' . $currentDeptKey . ':doctors', $doctorId);
                    }
                    $redis->sadd('department:' . $deptKey . ':doctors', $doctorId);
                }

                // If email changed, update email pointer
                if ($email !== $currentEmail) {
                    if (!empty($currentEmail)) {
                        $redis->del('doctor:email:' . $currentEmail);
                    }
                    $redis->set('doctor:email:' . $email, $doctorId);
                }

                $updates = [
                    'name'           => $name,
                    'email'          => $email,
                    'phone'          => $phone,
                    'department'     => $departmentName,
                    'department_key' => $deptKey,
                    'specialization' => $specialization,
                    'status'         => $status
                ];

                if ($newPassword !== '') {
                    $updates['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
                }

                $redis->hmset($doctorKey, $updates);
                $message = 'Doctor details updated successfully.';
            }
        }
    }

    if ($action === 'toggle') {
        $doctorId = trim($_POST['doctor_id'] ?? '');
        $doctorKey = 'doctor:' . $doctorId;

        if ($redis->exists($doctorKey) && safeRedisType($redis, $doctorKey) === 'hash') {
            $currentStatus = $redis->hget($doctorKey, 'status') ?? 'Active';
            $newStatus = (strcasecmp($currentStatus, 'Active') === 0) ? 'Inactive' : 'Active';
            $redis->hset($doctorKey, 'status', $newStatus);
            $message = "Doctor account status updated to {$newStatus}.";
        } else {
            $error = 'Doctor record not found.';
        }
    }

    if ($action === 'delete') {
        $doctorId = trim($_POST['doctor_id'] ?? '');
        $doctorKey = 'doctor:' . $doctorId;

        if ($redis->exists($doctorKey) && safeRedisType($redis, $doctorKey) === 'hash') {
            $doc = $redis->hgetall($doctorKey);
            $redis->del($doctorKey);
            if (!empty($doc['email'])) {
                $redis->del('doctor:email:' . $doc['email']);
            }
            if (!empty($doc['department_key'])) {
                $redis->srem('department:' . $doc['department_key'] . ':doctors', $doctorId);
            }
            $redis->del('doctor:serving:' . $doctorId);
            $message = 'Doctor deleted successfully.';
        } else {
            $error = 'Doctor record not found.';
        }
    }
}

$editDoctorId = trim($_GET['edit'] ?? '');
$editingDoctor = null;
if ($editDoctorId !== '') {
    $eKey = 'doctor:' . $editDoctorId;
    if ($redis->exists($eKey) && safeRedisType($redis, $eKey) === 'hash') {
        $editingDoctor = $redis->hgetall($eKey);
    }
}

// Fetch all doctors safely
$doctorKeys = $redis->keys('doctor:D*');
$doctors = [];

foreach ($doctorKeys as $key) {
    if (safeRedisType($redis, $key) === 'hash') {
        $doc = $redis->hgetall($key);
        if (!empty($doc) && isset($doc['id'])) {
            $doctors[] = $doc;
        }
    }
}

usort($doctors, function ($a, $b) {
    return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Management | Admin</title>
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
            <a href="doctors.php" class="nav-link active">Doctors</a>
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
                <div class="eyebrow">Medical Staff Administration</div>
                <h1>Doctor Management</h1>
                <p>Register physicians and assign clinical departments. Department options are populated dynamically from Redis.</p>
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

        <!-- Doctor Form Card (Edit or Create) -->
        <?php if ($editingDoctor): ?>
            <div class="content-card" style="border:2px solid var(--primary-border);">
                <div class="card-header">
                    <div>
                        <h2>Edit Doctor Account (<?= htmlspecialchars($editingDoctor['id'] ?? '') ?>)</h2>
                        <p>Update physician details, clinical department assignment, or credentials</p>
                    </div>
                    <a href="doctors.php" class="btn btn-secondary btn-sm">Cancel Edit</a>
                </div>

                <form method="POST" autocomplete="off">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="doctor_id" value="<?= htmlspecialchars($editingDoctor['id'] ?? '') ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Doctor Full Name *</label>
                            <input type="text" name="name" value="<?= htmlspecialchars($editingDoctor['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" minlength="2" maxlength="80" autocomplete="name" required>
                        </div>

                        <div class="form-group">
                            <label>Email Address (Login Username) *</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($editingDoctor['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" maxlength="254" autocomplete="email" required>
                        </div>

                        <div class="form-group">
                            <label>Phone Number *</label>
                            <input type="tel" name="phone" value="<?= htmlspecialchars($editingDoctor['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>" pattern="[6-9][0-9]{9}" minlength="10" maxlength="10" inputmode="numeric" autocomplete="tel" title="Enter a 10-digit Indian mobile number starting with 6, 7, 8, or 9" required>
                        </div>

                        <div class="form-group">
                            <label>Assigned Department *</label>
                            <select name="department_key" required>
                                <option value="">-- Select Active Department --</option>
                                <?php foreach ($activeDepartments as $d): ?>
                                    <option value="<?= htmlspecialchars($d['key']) ?>" <?= (($editingDoctor['department_key'] ?? '') === $d['key']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($d['name']) ?> (<?= htmlspecialchars($d['key']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Specialization / Clinical Focus *</label>
                            <input type="text" name="specialization" value="<?= htmlspecialchars($editingDoctor['specialization'] ?? '') ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Account Status *</label>
                            <select name="status" required>
                                <option value="Active" <?= (strcasecmp($editingDoctor['status'] ?? 'Active', 'Active') === 0) ? 'selected' : '' ?>>Active</option>
                                <option value="Inactive" <?= (strcasecmp($editingDoctor['status'] ?? '', 'Inactive') === 0) ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>

                        <div class="form-group full">
                            <label>Change Password (Optional)</label>
                            <input type="password" name="password" placeholder="Leave blank to keep existing password (min 6 characters if changing)">
                        </div>

                        <div class="form-actions full">
                            <button type="submit" class="btn btn-primary">
                                ✓ Update Doctor Details
                            </button>
                            <a href="doctors.php" class="btn btn-secondary">
                                Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <!-- Create Doctor Card -->
            <div class="content-card">
                <div class="card-header">
                    <div>
                        <h2>Create Doctor Account</h2>
                        <p>Provide physician credentials and assign clinical department</p>
                    </div>
                </div>

                <form method="POST" autocomplete="off">
                    <input type="hidden" name="action" value="add">

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Doctor Full Name *</label>
                            <input type="text" name="name" placeholder="e.g. Dr. Arun Kumar" minlength="2" maxlength="80" autocomplete="name" required>
                        </div>

                        <div class="form-group">
                            <label>Email Address (Login Username) *</label>
                            <input type="email" name="email" placeholder="e.g. arun.kumar@hospital.com" maxlength="254" autocomplete="email" required>
                        </div>

                        <div class="form-group">
                            <label>Phone Number *</label>
                            <input type="tel" name="phone" placeholder="e.g. 9876543210" pattern="[6-9][0-9]{9}" minlength="10" maxlength="10" inputmode="numeric" autocomplete="tel" title="Enter a 10-digit Indian mobile number starting with 6, 7, 8, or 9" required>
                        </div>

                        <div class="form-group">
                            <label>Assigned Department *</label>
                            <select name="department_key" required>
                                <option value="">-- Select Active Department --</option>
                                <?php foreach ($activeDepartments as $d): ?>
                                    <option value="<?= htmlspecialchars($d['key']) ?>">
                                        <?= htmlspecialchars($d['name']) ?> (<?= htmlspecialchars($d['key']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Specialization / Clinical Focus *</label>
                            <input type="text" name="specialization" placeholder="e.g. Senior Cardiologist, General Physician" required>
                        </div>

                        <div class="form-group">
                            <label>Login Password *</label>
                            <input type="password" name="password" placeholder="Minimum 6 characters" required>
                        </div>

                        <div class="form-actions full">
                            <button type="submit" class="btn btn-primary">
                                + Create Doctor Account
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Registered Doctors Table -->
        <div class="content-card">
            <div class="card-header">
                <div>
                    <h2>Registered Hospital Doctors</h2>
                    <p>Active and inactive physician accounts</p>
                </div>
                <span class="record-count"><?= count($doctors) ?> Doctors</span>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Doctor ID</th>
                            <th>Doctor Name</th>
                            <th>Email</th>
                            <th>Department</th>
                            <th>Specialization</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($doctors)): ?>
                            <tr>
                                <td colspan="7" class="empty-state">No doctors registered yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($doctors as $doctor): ?>
                                <?php $isActive = (strcasecmp($doctor['status'] ?? 'Active', 'Active') === 0); ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($doctor['id'] ?? '') ?></strong></td>
                                    <td>
                                        <strong><?= htmlspecialchars($doctor['name'] ?? '') ?></strong>
                                    </td>
                                    <td><?= htmlspecialchars($doctor['email'] ?? '') ?></td>
                                    <td>
                                        <span class="badge badge-normal">
                                            <?= htmlspecialchars($doctor['department'] ?? $doctor['department_key'] ?? '') ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($doctor['specialization'] ?? 'General') ?></td>
                                    <td>
                                        <span class="badge <?= $isActive ? 'badge-completed' : 'badge-skipped' ?>">
                                            <?= htmlspecialchars($doctor['status'] ?? 'Active') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <a href="doctors.php?edit=<?= urlencode($doctor['id'] ?? '') ?>" class="btn btn-secondary btn-sm">
                                                Edit
                                            </a>

                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="doctor_id" value="<?= htmlspecialchars($doctor['id'] ?? '') ?>">
                                                <button type="submit" class="btn btn-secondary btn-sm">
                                                    <?= $isActive ? 'Deactivate' : 'Activate' ?>
                                                </button>
                                            </form>

                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this doctor account?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="doctor_id" value="<?= htmlspecialchars($doctor['id'] ?? '') ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">
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