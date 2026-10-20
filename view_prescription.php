<?php
session_start();
require 'db_connect.php';

// ১. সিকিউরিটি চেক
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$prescription_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$prescription_id) {
    die("<div style='padding:20px; font-family:sans-serif;'>Invalid Prescription ID. <a href='dashboard.php'>Go Back</a></div>");
}

// ২. প্রেসক্রিপশন, রোগী ও ডাক্তারের বিস্তারিত ডাটা লোড
try {
    $sql = "SELECT pr.*, 
                   p.full_name AS patient_name, p.dob, p.gender, p.phone AS patient_phone, p.blood_group,
                   d.department_name, d.specialization, d.qualification, d.signature_image, d.status AS doc_status,
                   u_doc.full_name AS doctor_name
            FROM prescriptions pr
            JOIN patients p ON pr.patient_id = p.patient_id
            JOIN doctors d ON pr.doctor_id = d.doctor_id
            JOIN users u_doc ON d.user_id = u_doc.user_id
            WHERE pr.prescription_id = ?";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$prescription_id]);
    $rx = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rx) {
        die("<div style='padding:20px; font-family:sans-serif;'>Prescription not found. <a href='dashboard.php'>Go Back</a></div>");
    }

    // রোগীর জন্মতারিখ থেকে বয়স হিসাব করা
    $patient_age = 'N/A';
    if (!empty($rx['dob'])) {
        $dob_date = new DateTime($rx['dob']);
        $today    = new DateTime();
        $calc_age = $today->diff($dob_date)->y;
        $patient_age = $calc_age > 0 ? $calc_age . " Years" : "1 Year";
    }

    // JSON ডাটা থেকে ঔষধের লিস্ট অ্যারেতে কনভার্ট করা
    $medicines = json_decode($rx['medicines_json'], true) ?: [];

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescription #<?= $rx['prescription_id']; ?> - Smart Hospital ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .rx-card { max-width: 850px; margin: 30px auto; background: #ffffff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.06); padding: 40px; }
        .rx-header { border-bottom: 2px solid #0284c7; padding-bottom: 15px; margin-bottom: 20px; }
        .patient-bar { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 20px; margin-bottom: 25px; }
        .rx-symbol { font-size: 2.5rem; font-weight: bold; font-family: serif; color: #0284c7; line-height: 1; }
        .medicine-table th { background-color: #f1f5f9; }
        .doctor-sign { border-top: 1px solid #000000; display: inline-block; padding-top: 5px; width: 220px; text-align: center; }
        
        @media print {
            body { background-color: #ffffff; margin: 0; padding: 0; }
            .no-print { display: none !important; }
            .rx-card { box-shadow: none; border: none; margin: 0; max-width: 100%; padding: 20px; }
        }
    </style>
</head>
<body>

<div class="container py-3">

    <div class="d-flex justify-content-between align-items-center mb-3 no-print mx-auto" style="max-width: 850px;">
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Dashboard</a>
        <div>
            <button onclick="window.print()" class="btn btn-primary btn-sm me-2"><i class="bi bi-printer me-1"></i> Print / Save PDF</button>
            <a href="add_prescription.php" class="btn btn-success btn-sm"><i class="bi bi-plus-lg me-1"></i> New Prescription</a>
        </div>
    </div>

    <div class="rx-card">
        
        <!-- হাসপাতাল ও ডাক্তার হেডার -->
        <div class="rx-header d-flex justify-content-between align-items-start">
            <div>
                <h3 class="fw-bold text-primary mb-1"><i class="bi bi-hospital me-1"></i> Smart Hospital & Lab</h3>
                <p class="text-muted small mb-0">House #12, Road #05, Dhanmondi, Dhaka-1205</p>
                <p class="text-muted small mb-0">Emergency Hotline: +880 1700-000000</p>
            </div>
            <div class="text-end">
                <h5 class="fw-bold text-dark mb-0">Dr. <?= htmlspecialchars($rx['doctor_name']); ?></h5>
                <small class="text-secondary d-block"><?= htmlspecialchars($rx['qualification'] ?: 'MBBS'); ?></small>
                <span class="badge bg-info text-dark my-1"><?= htmlspecialchars($rx['department_name'] ?: 'General Physician'); ?></span>
            </div>
        </div>

        <!-- রোগীর প্রাথমিক তথ্য বার -->
        <div class="patient-bar">
            <div class="row g-2 text-dark small">
                <div class="col-md-4"><strong>Rx No:</strong> #RX-<?= htmlspecialchars($rx['prescription_id']); ?></div>
                <div class="col-md-5"><strong>Patient Name:</strong> <?= htmlspecialchars($rx['patient_name']); ?></div>
                <div class="col-md-3"><strong>Date:</strong> <?= date('d M, Y', strtotime($rx['created_at'])); ?></div>
                <div class="col-md-4"><strong>Age:</strong> <?= htmlspecialchars($patient_age); ?></div>
                <div class="col-md-5"><strong>Gender / Phone:</strong> <?= htmlspecialchars($rx['gender'] ?: 'N/A'); ?> | <?= htmlspecialchars($rx['patient_phone'] ?: 'N/A'); ?></div>
                <div class="col-md-3"><strong>Blood Group:</strong> <span class="badge bg-danger"><?= htmlspecialchars($rx['blood_group'] ?: 'N/A'); ?></span></div>
            </div>
        </div>

        <div class="row">
            <div class="col-4 border-end pe-3">
                <?php if (!empty($rx['diagnosis'])): ?>
                    <div class="mb-4">
                        <h6 class="fw-bold text-dark border-bottom pb-1"><i class="bi bi-search text-primary me-1"></i> Diagnosis</h6>
                        <p class="small text-secondary mb-0"><?= nl2br(htmlspecialchars($rx['diagnosis'])); ?></p>
                    </div>
                <?php endif; ?>

                <!-- Advised Diagnostic Tests Display -->
                <?php if (!empty($rx['tests_prescribed'])): ?>
                    <div class="mb-4">
                        <h6 class="fw-bold text-dark border-bottom pb-1"><i class="bi bi-ui-checks text-primary me-1"></i> Advised Tests</h6>
                        <ul class="ps-3 small text-secondary mb-0">
                            <?php 
                                $tests_list = explode(", ", $rx['tests_prescribed']);
                                foreach ($tests_list as $t_item): 
                            ?>
                                <li><?= htmlspecialchars($t_item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!empty($rx['advice'])): ?>
                    <div class="mb-3">
                        <h6 class="fw-bold text-dark border-bottom pb-1"><i class="bi bi-info-circle text-warning me-1"></i> Advice</h6>
                        <p class="small text-secondary mb-0"><?= nl2br(htmlspecialchars($rx['advice'])); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-8 ps-4">
                <div class="d-flex align-items-center mb-2">
                    <span class="rx-symbol me-2">Rx</span>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle medicine-table">
                        <thead>
                            <tr class="small text-secondary">
                                <th style="width: 5%;">#</th>
                                <th style="width: 40%;">Medicine Name</th>
                                <th style="width: 20%;">Dosage</th>
                                <th style="width: 20%;">Timing</th>
                                <th style="width: 15%;">Duration</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($medicines)): $sl = 1; ?>
                                <?php foreach ($medicines as $med): ?>
                                    <tr>
                                        <td><?= $sl++; ?></td>
                                        <td><strong><?= htmlspecialchars($med['name']); ?></strong></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($med['dosage']); ?></span></td>
                                        <td><small><?= htmlspecialchars($med['timing']); ?></small></td>
                                        <td><small class="text-muted"><?= htmlspecialchars($med['duration']); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted">No medicines prescribed.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 🆕 Auto Doctor Digital Signature Block -->
        <div class="d-flex justify-content-between align-items-end mt-5 pt-4 border-top">
            <div class="small text-muted">
                <p class="mb-0"><em>System Generated Prescription - Smart Hospital ERP</em></p>
                <small>Printed on: <?= date('d M, Y h:i A'); ?></small>
            </div>
            <div class="text-center">
                
                <!-- Signature Image Display Logic -->
                <?php 
                    $sig_img = $rx['signature_image'] ?? '';
                    $sig_path = "uploads/signatures/" . $sig_img;
                    if (!empty($sig_img) && file_exists($sig_path)): 
                ?>
                    <div style="height: 55px;" class="d-flex align-items-end justify-content-center">
                        <img src="<?= htmlspecialchars($sig_path); ?>" 
                             alt="Doctor Signature" 
                             style="max-height: 50px; max-width: 180px; object-fit: contain; margin-bottom: -5px;">
                    </div>
                <?php else: ?>
                    <div style="height: 40px; border-bottom: 1px dashed #94a3b8; margin-bottom: 5px; width: 220px;"></div>
                <?php endif; ?>

                <div class="doctor-sign">
                    <strong class="d-block text-dark">Dr. <?= htmlspecialchars($rx['doctor_name']); ?></strong>
                    <small class="text-muted">Signature & Seal</small>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>