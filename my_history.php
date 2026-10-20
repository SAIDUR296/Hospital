<?php
session_start();
require 'db_connect.php';

// সিকিউরিটি চেক
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'Patient';
$full_name = $_SESSION['full_name'] ?? 'User';

// পেশেন্টের patient_id বের করা
$patient_id = null;
if ($role == 'Patient') {
    $stmt_p = $pdo->prepare("SELECT patient_id FROM patients WHERE user_id = ?");
    $stmt_p->execute([$user_id]);
    $patient_id = $stmt_p->fetchColumn();
} elseif (isset($_GET['patient_id'])) {
    $patient_id = intval($_GET['patient_id']);
}

$prescriptions_list = [];

if ($patient_id) {
    try {
        $sql = "SELECT pr.*, 
                       u_doc.full_name AS doctor_name, d.department_name, d.specialization
                FROM prescriptions pr
                JOIN doctors d ON pr.doctor_id = d.doctor_id
                JOIN users u_doc ON d.user_id = u_doc.user_id
                WHERE pr.patient_id = ?
                ORDER BY pr.prescription_id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$patient_id]);
        $prescriptions_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Medical History - Smart Hospital ERP</title>
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
            <h5 class="mb-0"><i class="bi bi-file-medical me-2"></i>My Prescriptions & Medical History</h5>
            <a href="dashboard.php" class="btn btn-sm btn-outline-light"><i class="bi bi-arrow-left"></i> Dashboard</a>
        </div>
        <div class="card-body p-4">

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Rx ID</th>
                            <th>Prescription Date</th>
                            <th>Doctor Name</th>
                            <th>Department</th>
                            <th>Diagnosis / Findings</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($prescriptions_list)): ?>
                            <?php foreach ($prescriptions_list as $rx): ?>
                                <tr>
                                    <td><span class="badge bg-secondary">#RX-<?= $rx['prescription_id']; ?></span></td>
                                    <td><i class="bi bi-calendar3 me-1 text-primary"></i> <?= date('d M, Y', strtotime($rx['created_at'])); ?></td>
                                    <td><strong>Dr. <?= htmlspecialchars($rx['doctor_name']); ?></strong></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($rx['department_name'] ?: 'General'); ?></span></td>
                                    <td><small class="text-secondary"><?= htmlspecialchars($rx['diagnosis'] ?: 'N/A'); ?></small></td>
                                    <td class="text-center">
                                        <a href="view_prescription.php?id=<?= $rx['prescription_id']; ?>" class="btn btn-sm btn-primary" target="_blank">
                                            <i class="bi bi-eye me-1"></i> View / Print Prescription
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No prescription records found in your account history.</td>
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