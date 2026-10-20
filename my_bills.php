<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'Patient';
$full_name = $_SESSION['full_name'] ?? 'User';
$message   = "";
$message_type = "";

// ১. কলাম চেক ও পুরনো NULL ডাটা অটো-ফিক্স
try {
    $check_pay = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'payment_status'");
    if ($check_pay && $check_pay->rowCount() == 0) {
        $pdo->exec("ALTER TABLE appointments ADD payment_status ENUM('Unpaid', 'Paid') NOT NULL DEFAULT 'Unpaid' AFTER consultation_fee");
    }
    // খালি বা NULL থাকা রেকর্ডগুলোকে Unpaid করা
    $pdo->exec("UPDATE appointments SET payment_status = 'Unpaid' WHERE payment_status IS NULL OR payment_status = '' OR payment_status = '0'");
} catch (PDOException $e) {}

// ২. রোগী ফি প্রদান করার অ্যাকশন (Pay Now)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['pay_appointment_id'])) {
    $pay_app_id = intval($_POST['pay_appointment_id']);
    try {
        $upd_pay = $pdo->prepare("UPDATE appointments SET payment_status = 'Paid' WHERE appointment_id = ?");
        $upd_pay->execute([$pay_app_id]);
        $message = "Payment of consultation fee completed successfully! Prescription is now unlocked.";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Payment Error: " . $e->getMessage();
        $message_type = "danger";
    }
}

// ৩. বিল তালিকা লোড
$bills_list = [];
try {
    if ($role == 'Patient') {
        $sql = "SELECT a.*, u_doc.full_name AS doctor_name, d.department_name, pr.prescription_id
                FROM appointments a
                JOIN patients p ON a.patient_id = p.patient_id
                JOIN doctors d ON a.doctor_id = d.doctor_id
                JOIN users u_doc ON d.user_id = u_doc.user_id
                LEFT JOIN prescriptions pr ON a.appointment_id = pr.appointment_id
                WHERE p.user_id = ?
                ORDER BY a.appointment_id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id]);
        $bills_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sql = "SELECT a.*, p.full_name AS patient_name, u_doc.full_name AS doctor_name, d.department_name, pr.prescription_id
                FROM appointments a
                JOIN patients p ON a.patient_id = p.patient_id
                JOIN doctors d ON a.doctor_id = d.doctor_id
                JOIN users u_doc ON d.user_id = u_doc.user_id
                LEFT JOIN prescriptions pr ON a.appointment_id = pr.appointment_id
                ORDER BY a.appointment_id DESC";
        $stmt = $pdo->query($sql);
        $bills_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $message = "Database Error: " . $e->getMessage();
    $message_type = "danger";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bills & Payments - Smart Hospital ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-card { border: none; border-radius: 12px; margin-top: 30px; }
        .card-header { background-color: #1e293b; color: #ffffff; border-top-left-radius: 12px !important; border-top-right-radius: 12px !important; }
    </style>
</head>
<body>

<div class="container py-4">
    <div class="card main-card shadow-sm">
        <div class="card-header p-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-receipt-cutoff me-2"></i>My Bills & Invoices</h5>
            <a href="dashboard.php" class="btn btn-sm btn-outline-light"><i class="bi bi-arrow-left"></i> Dashboard</a>
        </div>
        <div class="card-body p-4">

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $message_type; ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Appt #</th>
                            <th>Doctor & Dept</th>
                            <th>Date</th>
                            <th>Consultation Fee</th>
                            <th>Payment Status</th>
                            <th class="text-center">Action / Prescription</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($bills_list)): ?>
                            <?php foreach ($bills_list as $b): ?>
                                <?php 
                                    $pay_status = strtoupper(trim($b['payment_status'] ?? 'UNPAID'));
                                    $is_paid = ($pay_status === 'PAID');
                                ?>
                                <tr>
                                    <td>#APP-<?= $b['appointment_id']; ?></td>
                                    <td>
                                        <strong>Dr. <?= htmlspecialchars($b['doctor_name']); ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($b['department_name']); ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($b['appointment_date']); ?></td>
                                    <td class="fw-bold text-primary">৳<?= number_format(floatval($b['consultation_fee']), 2); ?></td>
                                    <td>
                                        <?php if ($is_paid): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Paid</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i> Unpaid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!$is_paid): ?>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="pay_appointment_id" value="<?= $b['appointment_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger fw-bold" onclick="return confirm('Confirm payment of ৳<?= number_format(floatval($b['consultation_fee']), 0); ?>?');">
                                                    <i class="bi bi-credit-card me-1"></i> Pay Now (৳<?= number_format(floatval($b['consultation_fee']), 0); ?>)
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <?php if (!empty($b['prescription_id'])): ?>
                                                <a href="view_prescription.php?id=<?= $b['prescription_id']; ?>" class="btn btn-sm btn-success" target="_blank">
                                                    <i class="bi bi-file-earmark-medical me-1"></i> View Rx
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small">Rx Pending</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No billing records found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>