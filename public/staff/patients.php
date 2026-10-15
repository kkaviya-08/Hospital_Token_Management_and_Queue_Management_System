<?php
require_once '../../config/auth.php';
requireStaffLogin();
require_once '../../config/redis.php';

$staffName = $_SESSION['staff_name'] ?? 'Staff Member';
$staffRole = $_SESSION['staff_role'] ?? 'Receptionist';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? 'add_patient');

    if ($action === 'edit_patient') {
        $patientId = trim($_POST['patient_id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $age = trim($_POST['age'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));

        $patientKey = 'patient:' . $patientId;

        if (!$redis->exists($patientKey) || safeRedisType($redis, $patientKey) !== 'hash') {
            $error = 'Patient record not found.';
        } elseif ($name === '' || $phone === '' || $age === '' || $gender === '') {
            $error = 'Please fill in all mandatory patient details (Name, Phone, Age, Gender).';
        } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
            $error = 'Please enter a valid 10-digit phone number.';
        } elseif ((int)$age < 1 || (int)$age > 120) {
            $error = 'Please enter a valid age between 1 and 120.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $currentPatient = $redis->hgetall($patientKey);
            $currentEmail = strtolower(trim($currentPatient['email'] ?? ''));

            // Check if email changed and is taken by another patient
            if ($email !== '' && $email !== $currentEmail) {
                $existingId = safeRedisGet($redis, 'patient:email:' . $email);
                if ($existingId && $existingId !== $patientId) {
                    $error = 'A patient with this email address already exists.';
                }
            }

            if ($error === '') {
                // If email changed, update pointer
                if ($email !== $currentEmail) {
                    if (!empty($currentEmail)) {
                        $redis->del('patient:email:' . $currentEmail);
                    }
                    if ($email !== '') {
                        $redis->set('patient:email:' . $email, $patientId);
                    }
                }

                $updates = [
                    'name'   => $name,
                    'phone'  => $phone,
                    'age'    => $age,
                    'gender' => $gender,
                    'email'  => $email
                ];

                $redis->hmset($patientKey, $updates);

                // If patient has an active token, update token's patient_name
                $activeTokId = safeRedisGet($redis, 'patient:active-token:' . $patientId);
                if ($activeTokId && $redis->exists('token:' . $activeTokId)) {
                    $redis->hset('token:' . $activeTokId, 'patient_name', $name);
                }

                $message = 'Patient details updated successfully.';
            }
        }
    } elseif ($action === 'delete_patient') {
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
    } else {
        // Register walk-in patient
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $age = trim($_POST['age'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));

        if ($name === '' || $phone === '' || $age === '' || $gender === '') {
            $error = 'Please fill in all mandatory patient details (Name, Phone, Age, Gender).';
        } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
            $error = 'Please enter a valid 10-digit phone number.';
        } elseif ((int)$age < 1 || (int)$age > 120) {
            $error = 'Please enter a valid age between 1 and 120.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif ($email !== '' && $redis->exists('patient:email:' . $email)) {
            $error = 'A patient with this email address already exists.';
        } else {
            // Synchronized next patient ID
            $patientId = getNextPatientId($redis);

            $patientData = [
                'id'         => $patientId,
                'name'       => $name,
                'phone'      => $phone,
                'age'        => $age,
                'gender'     => $gender,
                'email'      => $email,
                'status'     => 'Active',
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => $_SESSION['staff_id']
            ];

            $redis->hmset('patient:' . $patientId, $patientData);
            if ($email !== '') {
                $redis->set('patient:email:' . $email, $patientId);
            }

            $message = "Patient {$name} registered successfully. Assigned Patient ID: {$patientId}.";
        }
    }
}

$editPatientId = trim($_GET['edit'] ?? '');
$editingPatient = null;
if ($editPatientId !== '') {
    $pKey = 'patient:' . $editPatientId;
    if ($redis->exists($pKey) && safeRedisType($redis, $pKey) === 'hash') {
        $editingPatient = $redis->hgetall($pKey);
    }
}

// Fetch all registered patients safely
$keys = $redis->keys('patient:P*');
$patients = [];

foreach ($keys as $key) {
    if (safeRedisType($redis, $key) === 'hash') {
        $patient = $redis->hgetall($key);
        if (!empty($patient) && isset($patient['id'])) {
            $patients[] = $patient;
        }
    }
}

// Search query filter
$searchQuery = trim($_GET['search'] ?? '');
if ($searchQuery !== '') {
    $qLower = strtolower($searchQuery);
    $patients = array_filter($patients, function ($p) use ($qLower) {
        $idMatch = strpos(strtolower($p['id'] ?? ''), $qLower) !== false;
        $nameMatch = strpos(strtolower($p['name'] ?? ''), $qLower) !== false;
        $phoneMatch = strpos(strtolower($p['phone'] ?? ''), $qLower) !== false;
        $emailMatch = strpos(strtolower($p['email'] ?? ''), $qLower) !== false;
        return $idMatch || $nameMatch || $phoneMatch || $emailMatch;
    });
}

usort($patients, function ($a, $b) {
    return strcasecmp($b['id'] ?? '', $a['id'] ?? '');
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Management | HospitalCare</title>
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
            <a href="patients.php" class="nav-link active">Patients</a>
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
                <div class="eyebrow">Outpatient Admissions</div>
                <h1>Patient Registration &amp; Directory</h1>
                <p>Register new walk-in patients and review existing patient medical records.</p>
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

        <!-- Patient Form Card (Edit or Register) -->
        <?php if ($editingPatient): ?>
            <div class="content-card" style="border:2px solid var(--primary-border);">
                <div class="card-header">
                    <div>
                        <h2>Edit Patient Details (<?= htmlspecialchars($editingPatient['id'] ?? '') ?>)</h2>
                        <p>Update patient demographic information and contact records</p>
                    </div>
                    <a href="patients.php" class="btn btn-secondary btn-sm">Cancel Edit</a>
                </div>

                <form method="POST" autocomplete="off">
                    <input type="hidden" name="action" value="edit_patient">
                    <input type="hidden" name="patient_id" value="<?= htmlspecialchars($editingPatient['id'] ?? '') ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Patient Full Name *</label>
                            <input type="text" name="name" value="<?= htmlspecialchars($editingPatient['name'] ?? '') ?>" required>
                        </div>

                        <div class="form-group">
                            <label>10-Digit Mobile Phone Number *</label>
                            <input type="text" name="phone" value="<?= htmlspecialchars($editingPatient['phone'] ?? '') ?>" pattern="[0-9]{10}" maxlength="10" required>
                        </div>

                        <div class="form-group">
                            <label>Age (Years) *</label>
                            <input type="number" name="age" min="1" max="120" value="<?= htmlspecialchars($editingPatient['age'] ?? '') ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Gender *</label>
                            <select name="gender" required>
                                <option value="">-- Select Gender --</option>
                                <option value="Male" <?= (($editingPatient['gender'] ?? '') === 'Male') ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= (($editingPatient['gender'] ?? '') === 'Female') ? 'selected' : '' ?>>Female</option>
                                <option value="Other" <?= (($editingPatient['gender'] ?? '') === 'Other') ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>

                        <div class="form-group full">
                            <label>Email Address (Optional)</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($editingPatient['email'] ?? '') ?>" placeholder="e.g. patient@example.com (optional)">
                        </div>

                        <div class="form-actions full">
                            <button type="submit" class="btn btn-primary">
                                ✓ Update Patient Details
                            </button>
                            <a href="patients.php" class="btn btn-secondary">
                                Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <!-- Patient Registration Card -->
            <div class="content-card">
                <div class="card-header">
                    <div>
                        <h2>Register Walk-in Patient</h2>
                        <p>Enter patient demographic information to assign hospital ID</p>
                    </div>
                </div>

                <form method="POST" autocomplete="off">
                    <input type="hidden" name="action" value="add_patient">

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Patient Full Name *</label>
                            <input type="text" name="name" placeholder="e.g. Keerthana R" required>
                        </div>

                        <div class="form-group">
                            <label>10-Digit Mobile Phone Number *</label>
                            <input type="text" name="phone" placeholder="e.g. 9876543210" pattern="[0-9]{10}" maxlength="10" required>
                        </div>

                        <div class="form-group">
                            <label>Age (Years) *</label>
                            <input type="number" name="age" min="1" max="120" placeholder="e.g. 35" required>
                        </div>

                        <div class="form-group">
                            <label>Gender *</label>
                            <select name="gender" required>
                                <option value="">-- Select Gender --</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="form-group full">
                            <label>Email Address (Optional)</label>
                            <input type="email" name="email" placeholder="e.g. patient@example.com (optional)">
                        </div>

                        <div class="form-actions full">
                            <button type="submit" class="btn btn-primary">
                                + Register Patient
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Registered Patients Table -->
        <div class="content-card">
            <div class="card-header">
                <div>
                    <h2>Registered Patients</h2>
                    <p>Select any patient when creating queue tokens</p>
                </div>
                <div style="display:flex; align-items:center; gap:12px;">
                    <form method="GET" action="patients.php" style="display:flex; gap:8px;">
                        <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Search name, ID, phone..." style="height:34px; min-width:200px; padding:4px 8px;">
                        <button type="submit" class="btn btn-primary btn-sm">Search</button>
                        <?php if ($searchQuery !== ''): ?>
                            <a href="patients.php" class="btn btn-secondary btn-sm">Clear</a>
                        <?php endif; ?>
                    </form>
                    <span class="record-count"><?= count($patients) ?> Patients</span>
                </div>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Patient ID</th>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Age</th>
                            <th>Gender</th>
                            <th>Email</th>
                            <th>Registered Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($patients)): ?>
                            <tr>
                                <td colspan="8" class="empty-state">No patients registered yet. Use the form above to add the first patient.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($patients as $p): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($p['id'] ?? '') ?></strong></td>
                                    <td><strong><?= htmlspecialchars($p['name'] ?? '') ?></strong></td>
                                    <td><?= htmlspecialchars($p['phone'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($p['age'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($p['gender'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($p['email'] ?? '—') ?></td>
                                    <td style="color:var(--text-muted); font-size:12.5px;">
                                        <?= htmlspecialchars($p['created_at'] ?? '—') ?>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <a href="token.php?patient_id=<?= urlencode($p['id'] ?? '') ?>" class="btn btn-secondary btn-sm">
                                                Issue Token →
                                            </a>
                                            <a href="patients.php?edit=<?= urlencode($p['id'] ?? '') ?>" class="btn btn-secondary btn-sm">
                                                Edit
                                            </a>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this patient record?');">
                                                <input type="hidden" name="action" value="delete_patient">
                                                <input type="hidden" name="patient_id" value="<?= htmlspecialchars($p['id'] ?? '') ?>">
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