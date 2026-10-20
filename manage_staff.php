<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'Admin') {
    header("Location: login.php");
    exit();
}

$full_name = $_SESSION['full_name'];
$message = "";
$message_type = "";

// 🆕 Auto-fix: users টেবিলে monthly_salary কলাম চেক/এড করা
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN monthly_salary DECIMAL(10,2) DEFAULT 0.00");
} catch (PDOException $e) {}

// 🆕 স্টাফের মাসিক স্যালারি আপডেট করার পোস্ট হ্যান্ডেলিং
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_staff_salary'])) {
    $st_id  = intval($_POST['salary_staff_id'] ?? 0);
    $st_sal = floatval($_POST['monthly_salary'] ?? 0);

    if ($st_id > 0) {
        try {
            $upd_s = $pdo->prepare("UPDATE users SET monthly_salary = ? WHERE user_id = ?");
            $upd_s->execute([$st_sal, $st_id]);

            $message = "Staff monthly salary updated to ৳" . number_format($st_sal, 2) . " successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Error updating salary: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// স্টাফের স্ট্যাটাস পরিবর্তন বা রিভোক (Revoke/Suspend) করার হ্যান্ডেলিং
if (isset($_GET['action']) && isset($_GET['id'])) {
    $staff_id = intval($_GET['id']);
    $action   = $_GET['action'];
    
    // রিভোক করলে Inactive বা Suspended করা যাবে
    $new_status = ($action == 'revoke') ? 'Inactive' : 'Active';
    
    try {
        $upd = $pdo->prepare("UPDATE users SET status = ? WHERE user_id = ?");
        $upd->execute([$new_status, $staff_id]);
        
        header("Location: manage_staff.php");
        exit();
    } catch (PDOException $e) {
        $error_message = $e->getMessage();
    }
}

try {
    // শুধুমাত্র এপ্রুভড (Active) স্টাফদের তালিকা ফেচ করা (monthly_salary সহ)
    $sql = "SELECT user_id, username, full_name, email, phone_number, role, degree, education_qualification, registration_number, monthly_salary, status 
            FROM users 
            WHERE role NOT IN ('Patient', 'Doctor', 'Admin') AND status = 'Active'
            ORDER BY user_id DESC";
    $stmt = $pdo->query($sql);
    $staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approved Staff Directory - Smart Hospital ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { min-height: 100vh; background-color: #2c3e50; color: white; width: 260px; flex-shrink: 0; }
        .sidebar a { color: #adb5bd; text-decoration: none; padding: 10px 15px; display: block; border-radius: 5px; margin-bottom: 5px; }
        .sidebar a:hover, .sidebar a.active { background-color: #34495e; color: #ffffff; }
        .sidebar i { margin-right: 10px; }
    </style>
</head>
<body>

<div class="d-flex">
    <div class="sidebar p-3">
        <h4 class="text-center text-white mb-4"><i class="bi bi-hospital"></i> Hospital ERP</h4>
        <hr class="text-secondary">
        <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="patient_list.php"><i class="bi bi-people"></i> Patient List</a>
        <a href="doctor_list.php"><i class="bi bi-heart-pulse"></i> Doctors Management</a>
        <a href="manage_staff.php" class="active"><i class="bi bi-people-fill"></i> Staff Management</a>
        <hr class="text-secondary mt-4">
        <a href="logout.php" class="text-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>

    <div class="w-100">
        <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm px-4 py-3 mb-4">
            <div class="container-fluid">
                <span class="navbar-brand mb-0 h1">Approved Hospital Staff Directory</span>
                <div class="d-flex align-items-center">
                    <span class="me-3 text-muted">Welcome, <strong><?= htmlspecialchars($full_name); ?></strong></span>
                    <a href="logout.php" class="btn btn-sm btn-outline-danger">Logout</a>
                </div>
            </div>
        </nav>

        <div class="container-fluid px-4">
            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $message_type; ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0 text-success fw-bold"><i class="bi bi-people-fill me-2"></i>Active & Approved Staff List</h5>
                    <span class="badge bg-success fs-6"><?= count($staff_list); ?> Active Staff</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Staff Name</th>
                                    <th>Role & Qualification</th>
                                    <th>License / Reg No</th>
                                    <th>Monthly Salary</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($staff_list)): ?>
                                    <?php foreach ($staff_list as $st): ?>
                                        <tr>
                                            <td>#<?= htmlspecialchars($st['user_id']); ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($st['full_name']); ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($st['email']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-info text-dark mb-1"><?= htmlspecialchars($st['role']); ?></span><br>
                                                <small class="text-muted"><?= htmlspecialchars($st['degree'] ?: 'N/A'); ?></small>
                                            </td>
                                            <td><code><?= htmlspecialchars($st['registration_number'] ?: 'N/A'); ?></code></td>
                                            <td>
                                                <strong class="text-success fs-6">৳<?= number_format($st['monthly_salary'] ?? 0, 2); ?></strong>
                                            </td>
                                            <td><?= htmlspecialchars($st['phone_number'] ?: 'N/A'); ?></td>
                                            <td>
                                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Active</span>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-outline-primary btn-sm me-1 fw-bold" data-bs-toggle="modal" data-bs-target="#editSalaryModal_<?= $st['user_id']; ?>">
                                                    <i class="bi bi-pencil-square me-1"></i> Edit Salary
                                                </button>
                                                <a href="manage_staff.php?action=revoke&id=<?= $st['user_id']; ?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('Revoke approval for this staff member?');">
                                                    <i class="bi bi-slash-circle me-1"></i> Revoke Approval
                                                </a>

                                                <!-- 🆕 Modal for Updating Staff Monthly Salary -->
                                                <div class="modal fade text-start" id="editSalaryModal_<?= $st['user_id']; ?>" tabindex="-1" aria-hidden="true">
                                                    <div class="modal-dialog modal-dialog-centered modal-sm">
                                                        <div class="modal-content border-0 shadow">
                                                            <div class="modal-header bg-primary text-white py-2">
                                                                <h6 class="modal-title mb-0 fw-bold"><i class="bi bi-cash-stack me-1"></i>Update Staff Salary</h6>
                                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <form method="POST" action="manage_staff.php">
                                                                <div class="modal-body p-3">
                                                                    <input type="hidden" name="salary_staff_id" value="<?= $st['user_id']; ?>">
                                                                    <div class="mb-2">
                                                                        <label class="form-label small fw-semibold text-muted">Staff Name:</label>
                                                                        <div class="fw-bold"><?= htmlspecialchars($st['full_name']); ?> (<?= htmlspecialchars($st['role']); ?>)</div>
                                                                    </div>
                                                                    <div class="mb-2">
                                                                        <label class="form-label small fw-semibold">Monthly Salary (BDT) <span class="text-danger">*</span></label>
                                                                        <input type="number" step="0.01" name="monthly_salary" class="form-control form-control-sm fw-bold text-success" value="<?= $st['monthly_salary'] ?? '0.00'; ?>" required>
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer bg-light py-2">
                                                                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                    <button type="submit" name="update_staff_salary" class="btn btn-sm btn-primary fw-bold"><i class="bi bi-check-lg me-1"></i> Save Changes</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">No active approved staff found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>