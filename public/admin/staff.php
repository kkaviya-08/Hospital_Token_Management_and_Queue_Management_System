<?php
require_once '../../config/auth.php';
requireAdminLogin();
require_once '../../config/redis.php';

$adminName = $_SESSION['admin_name'] ?? 'Administrator';
$message = '';

$error = '';

function isValidStaffPersonName(string $name): bool {
    return (bool) preg_match("/^[\\p{L}][\\p{L}\\s.'-]{1,79}$/u", $name)
        && preg_match('/\\p{L}/u', $name);
}

function isValidStaffIndianMobile(string $phone): bool {
    return (bool) preg_match('/^[6-9][0-9]{9}$/', $phone);
}

function isValidStaffEmailAddress(string $email): bool {
    return strlen($email) <= 254
        && !preg_match('/\\s/', $email)
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add_staff') {
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $role = trim($_POST['role'] ?? '');
        $password = $_POST['password'] ?? '';

        if (
            $name === '' ||
            $email === '' ||
            $phone === '' ||
            $role === '' ||
            $password === ''
        ) {
            $error = 'Please fill in all required fields.';
        } elseif (!isValidStaffPersonName($name)) {
            $error = 'Please enter a valid name using 2–80 letters, spaces, periods, apostrophes, or hyphens.';
        } elseif (!isValidStaffEmailAddress($email)) {
            $error = 'Please enter a valid email address.';
        } elseif (!isValidStaffIndianMobile($phone)) {
            $error = 'Please enter a valid 10-digit Indian mobile number starting with 6, 7, 8, or 9.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must contain at least 6 characters.';
        } elseif ($redis->exists('staff:email:' . $email)) {
            $error = 'A staff account with this email address already exists.';
        } else {
            $counter = (int)$redis->incr('staff:counter');
            $staffId = 'S' . str_pad($counter, 4, '0', STR_PAD_LEFT);

            $staffData = [
                'id'         => $staffId,
                'name'       => $name,
                'email'      => $email,
                'phone'      => $phone,
                'role'       => $role,
                'password'   => password_hash($password, PASSWORD_DEFAULT),
                'status'     => 'Active',
                'created_at' => date('Y-m-d H:i:s')
            ];

            $redis->hmset('staff:' . $staffId, $staffData);
            $redis->set('staff:email:' . $email, $staffId);

            $message = "Staff account for {$name} created successfully (Staff ID: {$staffId}).";
        }
    }

    if ($action === 'edit_staff') {
        $staffId = trim($_POST['staff_id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $role = trim($_POST['role'] ?? '');
        $newPassword = $_POST['password'] ?? '';
        $status = trim($_POST['status'] ?? 'Active');
        if (!in_array($status, ['Active', 'Inactive'], true)) {
            $status = 'Active';
        }

        $staffKey = 'staff:' . $staffId;
        if (!$redis->exists($staffKey) || safeRedisType($redis, $staffKey) !== 'hash') {
            $error = 'Staff account not found.';
        } elseif (
            $name === '' ||
            $email === '' ||
            $phone === '' ||
            $role === ''
        ) {
            $error = 'Please fill in all required fields.';
        } elseif (!isValidStaffPersonName($name)) {
            $error = 'Please enter a valid name using 2–80 letters, spaces, periods, apostrophes, or hyphens.';
        } elseif (!isValidStaffEmailAddress($email)) {
            $error = 'Please enter a valid email address.';
        } elseif (!isValidStaffIndianMobile($phone)) {
            $error = 'Please enter a valid 10-digit Indian mobile number starting with 6, 7, 8, or 9.';
        } else {
            $currentStaff = $redis->hgetall($staffKey);
            $currentEmail = strtolower(trim($currentStaff['email'] ?? ''));

            // Check if email changed and is taken by another staff
            if ($email !== $currentEmail) {
                $existingStaffId = safeRedisGet($redis, 'staff:email:' . $email);
                if ($existingStaffId && $existingStaffId !== $staffId) {
                    $error = 'A staff account with this email address already exists.';
                }
            }

            if ($error === '' && $newPassword !== '' && strlen($newPassword) < 6) {
                $error = 'Password must contain at least 6 characters.';
            }

            if ($error === '') {
                // If email changed, update email pointer
                if ($email !== $currentEmail) {
                    if (!empty($currentEmail)) {
                        $redis->del('staff:email:' . $currentEmail);
                    }
                    $redis->set('staff:email:' . $email, $staffId);
                }

                $updates = [
                    'name'   => $name,
                    'email'  => $email,
                    'phone'  => $phone,
                    'role'   => $role,
                    'status' => $status
                ];

                if ($newPassword !== '') {
                    $updates['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
                }

                $redis->hmset($staffKey, $updates);
                $message = 'Staff details updated successfully.';
            }
        }
    }

    if ($action === 'toggle_staff') {
        $staffId = trim($_POST['staff_id'] ?? '');
        $staffKey = 'staff:' . $staffId;

        if ($redis->exists($staffKey) && safeRedisType($redis, $staffKey) === 'hash') {
            $currentStatus = $redis->hget($staffKey, 'status') ?? 'Active';
            $newStatus = (strcasecmp($currentStatus, 'Active') === 0) ? 'Inactive' : 'Active';
            $redis->hset($staffKey, 'status', $newStatus);
            $message = "Staff account status changed to {$newStatus}.";
        } else {
            $error = 'Staff account not found.';
        }
    }

    if ($action === 'delete_staff') {
        $staffId = trim($_POST['staff_id'] ?? '');
        $staffKey = 'staff:' . $staffId;

        if ($redis->exists($staffKey) && safeRedisType($redis, $staffKey) === 'hash') {
            $st = $redis->hgetall($staffKey);
            $redis->del($staffKey);
            if (!empty($st['email'])) {
                $redis->del('staff:email:' . $st['email']);
            }
            $message = 'Staff deleted successfully.';
        } else {
            $error = 'Staff account not found.';
        }
    }
}

$editStaffId = trim($_GET['edit'] ?? '');
$editingStaff = null;
if ($editStaffId !== '') {
    $sKey = 'staff:' . $editStaffId;
    if ($redis->exists($sKey) && safeRedisType($redis, $sKey) === 'hash') {
        $editingStaff = $redis->hgetall($sKey);
    }
}

// Fetch all staff members safely
$staffKeys = $redis->keys('staff:S*');
$staffList = [];

foreach ($staffKeys as $key) {
    if (safeRedisType($redis, $key) === 'hash') {
        $staff = $redis->hgetall($key);
        if (!empty($staff) && isset($staff['id'])) {
            $staffList[] = $staff;
        }
    }
}

usort($staffList, function ($a, $b) {
    return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Hospital Staff | Admin</title>
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
            <a href="staff.php" class="nav-link active">Hospital Staff</a>
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
                <div class="eyebrow">Hospital Personnel Administration</div>
                <h1>Hospital Staff Management</h1>
                <p>Register receptionists, token officers, and queue operators who manage daily outpatient flows.</p>
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

        <!-- Staff Form Card (Edit or Create) -->
        <?php if ($editingStaff): ?>
            <div class="content-card" style="border:2px solid var(--primary-border);">
                <div class="card-header">
                    <div>
                        <h2>Edit Staff Account (<?= htmlspecialchars($editingStaff['id'] ?? '') ?>)</h2>
                        <p>Update hospital staff profile, role assignment, or credentials</p>
                    </div>
                    <a href="staff.php" class="btn btn-secondary btn-sm">Cancel Edit</a>
                </div>

                <form method="POST" autocomplete="off">
                    <input type="hidden" name="action" value="edit_staff">
                    <input type="hidden" name="staff_id" value="<?= htmlspecialchars($editingStaff['id'] ?? '') ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Staff Full Name *</label>
                            <input type="text" name="name" value="<?= htmlspecialchars($editingStaff['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" minlength="2" maxlength="80" autocomplete="name" required>
                        </div>

                        <div class="form-group">
                            <label>Staff Email (Login ID) *</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($editingStaff['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" maxlength="254" autocomplete="email" required>
                        </div>

                        <div class="form-group">
                            <label>Phone Number *</label>
                            <input type="tel" name="phone" value="<?= htmlspecialchars($editingStaff['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>" pattern="[6-9][0-9]{9}" minlength="10" maxlength="10" inputmode="numeric" autocomplete="tel" title="Enter a 10-digit Indian mobile number starting with 6, 7, 8, or 9" required>
                        </div>

                        <div class="form-group">
                            <label>Hospital Role / Designation *</label>
                            <select name="role" required>
                                <option value="">-- Select Staff Role --</option>
                                <?php
                                $roles = ['Receptionist', 'Token Officer', 'Triage Staff', 'Queue Manager', 'Billing Staff', 'Pharmacy Staff'];
                                foreach ($roles as $r):
                                ?>
                                    <option value="<?= htmlspecialchars($r) ?>" <?= (($editingStaff['role'] ?? '') === $r) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($r) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Account Status *</label>
                            <select name="status" required>
                                <option value="Active" <?= (strcasecmp($editingStaff['status'] ?? 'Active', 'Active') === 0) ? 'selected' : '' ?>>Active</option>
                                <option value="Inactive" <?= (strcasecmp($editingStaff['status'] ?? '', 'Inactive') === 0) ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>

                        <div class="form-group full">
                            <label>Change Password (Optional)</label>
                            <input type="password" name="password" placeholder="Leave blank to keep existing password (min 6 characters if changing)">
                        </div>

                        <div class="form-actions full">
                            <button type="submit" class="btn btn-primary">
                                ✓ Update Staff Details
                            </button>
                            <a href="staff.php" class="btn btn-secondary">
                                Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <!-- Create Staff Card -->
            <div class="content-card">
                <div class="card-header">
                    <div>
                        <h2>Create Staff Account</h2>
                        <p>Provide credentials for front desk, registration, or billing personnel</p>
                    </div>
                </div>

                <form method="POST" autocomplete="off">
                    <input type="hidden" name="action" value="add_staff">

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Staff Full Name *</label>
                            <input type="text" name="name" placeholder="e.g. Madhumitha R" minlength="2" maxlength="80" autocomplete="name" required>
                        </div>

                        <div class="form-group">
                            <label>Staff Email (Login ID) *</label>
                            <input type="email" name="email" placeholder="e.g. madhu@hospital.com" maxlength="254" autocomplete="email" required>
                        </div>

                        <div class="form-group">
                            <label>Phone Number *</label>
                            <input type="tel" name="phone" placeholder="e.g. 9876543210" pattern="[6-9][0-9]{9}" minlength="10" maxlength="10" inputmode="numeric" autocomplete="tel" title="Enter a 10-digit Indian mobile number starting with 6, 7, 8, or 9" required>
                        </div>

                        <div class="form-group">
                            <label>Hospital Role / Designation *</label>
                            <select name="role" required>
                                <option value="">-- Select Staff Role --</option>
                                <option value="Receptionist">Receptionist / Front Desk</option>
                                <option value="Token Officer">Token Officer</option>
                                <option value="Triage Staff">Triage &amp; Risk Evaluator</option>
                                <option value="Queue Manager">Queue Manager</option>
                                <option value="Billing Staff">Billing Staff</option>
                                <option value="Pharmacy Staff">Pharmacy Staff</option>
                            </select>
                        </div>

                        <div class="form-group full">
                            <label>Account Password *</label>
                            <input type="password" name="password" placeholder="Minimum 6 characters" required>
                        </div>

                        <div class="form-actions full">
                            <button type="submit" class="btn btn-primary">
                                + Create Staff Account
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Registered Staff Members Table -->
        <div class="content-card">
            <div class="card-header">
                <div>
                    <h2>Registered Hospital Staff</h2>
                    <p>Authorized staff with access to the Staff Portal</p>
                </div>
                <span class="record-count"><?= count($staffList) ?> Staff Members</span>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Staff ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($staffList)): ?>
                            <tr>
                                <td colspan="7" class="empty-state">No staff accounts registered yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($staffList as $staff): ?>
                                <?php $isActive = (strcasecmp($staff['status'] ?? 'Active', 'Active') === 0); ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($staff['id'] ?? '') ?></strong></td>
                                    <td><strong><?= htmlspecialchars($staff['name'] ?? '') ?></strong></td>
                                    <td><?= htmlspecialchars($staff['email'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($staff['phone'] ?? '—') ?></td>
                                    <td>
                                        <span class="badge badge-normal">
                                            <?= htmlspecialchars($staff['role'] ?? 'Hospital Staff') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $isActive ? 'badge-completed' : 'badge-skipped' ?>">
                                            <?= htmlspecialchars($staff['status'] ?? 'Active') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <a href="staff.php?edit=<?= urlencode($staff['id'] ?? '') ?>" class="btn btn-secondary btn-sm">
                                                Edit
                                            </a>

                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="toggle_staff">
                                                <input type="hidden" name="staff_id" value="<?= htmlspecialchars($staff['id'] ?? '') ?>">
                                                <button type="submit" class="btn btn-secondary btn-sm">
                                                    <?= $isActive ? 'Deactivate' : 'Activate' ?>
                                                </button>
                                            </form>

                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this staff account?');">
                                                <input type="hidden" name="action" value="delete_staff">
                                                <input type="hidden" name="staff_id" value="<?= htmlspecialchars($staff['id'] ?? '') ?>">
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