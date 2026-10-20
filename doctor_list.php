<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$role = $_SESSION['role'];
$full_name = $_SESSION['full_name'];

if ($role != 'Admin' && $role != 'Receptionist' && $role != 'Doctor') {
    header("Location: dashboard.php");
    exit();
}

// ১. অ্যাডমিনের জন্য Approve বা Reject করার ব্যবস্থা
if (isset($_GET['action']) && isset($_GET['id']) && $role == 'Admin') {
    $doc_id = $_GET['id'];
    $action = $_GET['action'];
    
    $doc_status = ($action == 'approve') ? 'Approved' : 'Rejected';
    $user_status = ($action == 'approve') ? 'Active' : 'Rejected';
    
    // doctors টেবিলের স্ট্যাটাস আপডেট
    $upd = $pdo->prepare("UPDATE doctors SET status = ? WHERE doctor_id = ?");
    $upd->execute([$doc_status, $doc_id]);

    // সংশ্লিষ্ট user_id খুঁজে users টেবিলের status আপডেট করা
    $get_uid = $pdo->prepare("SELECT user_id FROM doctors WHERE doctor_id = ?");
    $get_uid->execute([$doc_id]);
    $u_id = $get_uid->fetchColumn();

    if ($u_id) {
        $upd_usr = $pdo->prepare("UPDATE users SET status = ? WHERE user_id = ?");
        $upd_usr->execute([$user_status, $u_id]);
    }

    header("Location: doctor_list.php");
    exit();
}

// 🆕 ২. অ্যাডমিনের মাধ্যমে ডাক্তারদের স্যালারি সেট/আপডেট করার পোস্ট লজিক
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_salary_action']) && $role == 'Admin') {
    $target_user_id = intval($_POST['target_user_id'] ?? 0);
    $new_salary     = floatval($_POST['new_monthly_salary'] ?? 0);

    if ($target_user_id > 0) {
        try {
            $upd_sal = $pdo->prepare("UPDATE users SET monthly_salary = ? WHERE user_id = ?");
            $upd_sal->execute([$new_salary, $target_user_id]);
            $success_message = "Fixed Monthly Salary updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Salary Update Error: " . $e->getMessage();
        }
    }
}

try {
    // স্কিমা অনুযায়ী ডাটা ফেচ করা হচ্ছে (users টেবিল থেকে ব্যাংকিং ও স্যালারি ডাটা সহ)
    $sql = "SELECT d.*, u.full_name, u.email, u.phone_number, u.user_id AS doc_user_id,
                   u.bank_name, u.account_number, u.account_holder, u.branch_name, u.monthly_salary 
            FROM doctors d 
            JOIN users u ON d.user_id = u.user_id 
            ORDER BY d.doctor_id DESC";
    $stmt = $pdo->query($sql);
    $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctors List - Smart Hospital ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { min-height: 100vh; background-color: #2c3e50; color: white; }
        .sidebar a { color: #adb5bd; text-decoration: none; padding: 10px 15px; display: block; border-radius: 5px; margin-bottom: 5px; }
        .sidebar a:hover, .sidebar a.active { background-color: #34495e; color: #ffffff; }
        .sidebar i { margin-right: 10px; }
    </style>
</head>
<body>

<div class="d-flex">
    <div class="sidebar p-3" style="width: 260px;">
        <h4 class="text-center text-white mb-4"><i class="bi bi-hospital"></i> Hospital ERP</h4>
        <hr class="text-secondary">
        <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="patient_list.php"><i class="bi bi-people"></i> Patient List</a>
        <a href="doctor_list.php" class="active"><i class="bi bi-heart-pulse"></i> Doctors Management</a>
        <hr class="text-secondary mt-4">
        <a href="logout.php" class="text-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>

    <div class="w-100">
        <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm px-4 py-3 mb-4">
            <div class="container-fluid">
                <span class="navbar-brand mb-0 h1">Doctors Management & Verification</span>
                <div class="d-flex align-items-center">
                    <span class="me-3 text-muted">Welcome, <strong><?= htmlspecialchars($full_name); ?></strong></span>
                    <a href="logout.php" class="btn btn-sm btn-outline-danger">Logout</a>
                </div>
            </div>
        </nav>

        <div class="container-fluid px-4">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0 text-primary"><i class="bi bi-heart-pulse"></i> Hospital Doctors Verification Directory</h5>
                    <?php if ($role == 'Admin'): ?>
                        <a href="add_doctor.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle"></i> Add Doctor Profile</a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (isset($error_message)): ?>
                        <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($error_message); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    <?php endif; ?>
                    <?php if (isset($success_message)): ?>
                        <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($success_message); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Doctor Name</th>
                                    <th>Department & Degree</th>
                                    <th>License No</th>
                                    <th>Fee</th>
                                    <th>Monthly Salary</th>
                                    <th>Bank Account Details</th>
                                    <th>Verification Status</th>
                                    <?php if ($role == 'Admin'): ?>
                                        <th>Action (Accept/Reject)</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($doctors)): ?>
                                    <?php foreach ($doctors as $doc): ?>
                                        <tr>
                                            <td>#<?= htmlspecialchars($doc['doctor_id']); ?></td>
                                            <td>
                                                <strong>Dr. <?= htmlspecialchars($doc['full_name']); ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($doc['email']); ?></small>
                                            </td>
                                            <td>
                                                <?php 
                                                    $dept_name = $doc['department_name'] ?? 'General Department';
                                                ?>
                                                <span class="badge bg-info text-dark mb-1"><?= htmlspecialchars($dept_name); ?></span><br>
                                                <small class="text-muted"><?= htmlspecialchars($doc['qualification'] ?? 'MBBS'); ?></small>
                                            </td>
                                            <td><code><?= htmlspecialchars($doc['license_number'] ?? 'N/A'); ?></code></td>
                                            <td>$<?= number_format($doc['consultation_fee'] ?? 500, 2); ?></td>
                                            
                                            <!-- 🆕 Monthly Salary Display and Edit Modal Button for Admin -->
                                            <td>
                                                <span class="fw-bold text-primary d-block">
                                                    ৳<?= number_format($doc['monthly_salary'] ?? 0, 2); ?>
                                                </span>
                                                <?php if ($role == 'Admin'): ?>
                                                    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-bs-toggle="modal" data-bs-target="#salaryModal_<?= $doc['doctor_id']; ?>">
                                                        <i class="bi bi-pencil-square me-1"></i>Edit Salary
                                                    </button>

                                                    <!-- 🆕 Salary Edit Modal -->
                                                    <div class="modal fade" id="salaryModal_<?= $doc['doctor_id']; ?>" tabindex="-1" aria-hidden="true">
                                                        <div class="modal-dialog modal-dialog-centered modal-sm">
                                                            <div class="modal-content border-0 shadow">
                                                                <div class="modal-header bg-primary text-white py-2">
                                                                    <h6 class="modal-title mb-0 fw-bold"><i class="bi bi-cash-stack me-1"></i>Fix Monthly Salary</h6>
                                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                                </div>
                                                                <form method="POST" action="doctor_list.php">
                                                                    <div class="modal-body p-3 text-start">
                                                                        <input type="hidden" name="update_salary_action" value="1">
                                                                        <input type="hidden" name="target_user_id" value="<?= $doc['doc_user_id']; ?>">
                                                                        <div class="mb-2">
                                                                            <label class="form-label small fw-semibold text-secondary">Doctor Name</label>
                                                                            <input type="text" class="form-control form-control-sm bg-light" value="Dr. <?= htmlspecialchars($doc['full_name']); ?>" readonly>
                                                                        </div>
                                                                        <div class="mb-2">
                                                                            <label class="form-label small fw-semibold">Set Monthly Salary (BDT)</label>
                                                                            <input type="number" step="0.01" name="new_monthly_salary" class="form-control form-control-sm" value="<?= $doc['monthly_salary'] ?? '0.00'; ?>" required>
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer bg-light py-2">
                                                                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                        <button type="submit" class="btn btn-sm btn-primary fw-bold"><i class="bi bi-check-lg"></i> Save Salary</button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Bank Account Details Display -->
                                            <td>
                                                <?php if (!empty($doc['bank_name'])): ?>
                                                    <small class="d-block"><strong>Bank:</strong> <?= htmlspecialchars($doc['bank_name']); ?></small>
                                                    <small class="d-block"><strong>A/C:</strong> <?= htmlspecialchars($doc['account_number']); ?></small>
                                                    <small class="text-muted"><strong>Branch:</strong> <?= htmlspecialchars($doc['branch_name'] ?: 'N/A'); ?></small>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-dark border">Not Provided</span>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <?php 
                                                    $st = $doc['status'] ?? 'Pending'; 
                                                    $bg = ($st == 'Approved') ? 'bg-success' : (($st == 'Rejected') ? 'bg-danger' : 'bg-warning text-dark');
                                                ?>
                                                <span class="badge <?= $bg; ?>"><?= htmlspecialchars($st); ?></span>
                                            </td>
                                            <?php if ($role == 'Admin'): ?>
                                                <td>
                                                    <?php if ($st != 'Approved'): ?>
                                                        <a href="doctor_list.php?action=approve&id=<?= $doc['doctor_id']; ?>" class="btn btn-success btn-sm mb-1"><i class="bi bi-check"></i> Accept</a>
                                                    <?php endif; ?>
                                                    <?php if ($st != 'Rejected'): ?>
                                                        <a href="doctor_list.php?action=reject&id=<?= $doc['doctor_id']; ?>" class="btn btn-danger btn-sm mb-1"><i class="bi bi-x"></i> Reject</a>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-4">No doctors registered yet.</td>
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