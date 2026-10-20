<?php
session_start();
require 'db_connect.php';

// ১. সিকিউরিটি চেক
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$role = $_SESSION['role'] ?? '';
$full_name = $_SESSION['full_name'] ?? 'User';

// ২. নার্সদের (Nurse) প্রোফাইল দেখার এক্সেস দেওয়া হলো
if (!in_array($role, ['Admin', 'Receptionist', 'Doctor', 'Nurse'])) {
    header("Location: dashboard.php");
    exit();
}

// আইডি চেক করা
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: patient_list.php");
    exit();
}

$patient_id = intval($_GET['id']);

// ৩. রোগীর বিস্তারিত তথ্য ফেচ করা
try {
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        die("Patient not found in the database!");
    }

    // রোগীর বর্তমান বেড/কেবিন তথ্য ফেচ করা
    $stmt_bed = $pdo->prepare("SELECT * FROM cabins_beds WHERE patient_id = ? AND availability_status = 'Occupied'");
    $stmt_bed->execute([$patient_id]);
    $cabin = $stmt_bed->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Patient Details - Hospital ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { min-height: 100vh; background-color: #1e293b; color: white; width: 260px; flex-shrink: 0; }
        .sidebar a { color: #94a3b8; text-decoration: none; padding: 10px 15px; display: block; border-radius: 6px; margin-bottom: 4px; font-weight: 500;}
        .sidebar a:hover, .sidebar a.active { background-color: #334155; color: #ffffff; }
        .sidebar i { margin-right: 10px; }
    </style>
</head>
<body>

<div class="d-flex">
    <!-- Sidebar -->
    <div class="sidebar p-3">
        <h4 class="text-center text-white mb-4"><i class="bi bi-hospital"></i> Hospital ERP</h4>
        <hr class="text-secondary">
        <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        
        <?php if ($role == 'Nurse'): ?>
            <a href="cabins.php"><i class="bi bi-door-open-fill"></i> Cabin & Bed Status</a>
            <a href="patient_list.php" class="active"><i class="bi bi-person-lines-fill"></i> Patient Directory</a>
            <a href="appointments.php"><i class="bi bi-journal-medical"></i> Doctor Visit Schedule</a>
        <?php else: ?>
            <a href="patient_registration.php"><i class="bi bi-person-plus"></i> Patient Registration</a>
            <a href="patient_list.php" class="active"><i class="bi bi-people"></i> Patient Directory</a>
            <a href="appointments.php"><i class="bi bi-calendar-check"></i> Appointments</a>
            <a href="cabins.php"><i class="bi bi-building"></i> Cabins & Beds</a>
        <?php endif; ?>
        
        <hr class="text-secondary mt-4">
        <a href="logout.php" class="text-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>

    <!-- Main Content -->
    <div class="w-100">
        <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm px-4 py-3 mb-4">
            <div class="container-fluid">
                <span class="navbar-brand mb-0 h1">Patient Information Panel</span>
                <div class="d-flex align-items-center">
                    <span class="me-3 text-muted">Welcome, <strong><?= htmlspecialchars($full_name); ?></strong> (<?= htmlspecialchars($role); ?>)</span>
                    <a href="logout.php" class="btn btn-sm btn-outline-danger">Logout</a>
                </div>
            </div>
        </nav>

        <div class="container-fluid px-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-primary text-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-person-vcard me-2"></i>Patient Full Profile</h5>
                    <a href="patient_list.php" class="btn btn-sm btn-outline-light"><i class="bi bi-arrow-left"></i> Back to Directory</a>
                </div>
                
                <div class="card-body p-4">
                    <div class="row">
                        <!-- Basic Info Section -->
                        <div class="col-md-6 mb-4">
                            <h6 class="text-secondary border-bottom pb-2 mb-3">Basic Information</h6>
                            <table class="table table-borderless table-sm">
                                <tr>
                                    <th width="40%">Patient ID:</th>
                                    <td><span class="badge bg-secondary fs-6">#PAT-<?= htmlspecialchars($patient['patient_id']); ?></span></td>
                                </tr>
                                <tr>
                                    <th>Full Name:</th>
                                    <td><strong class="fs-5 text-dark"><?= htmlspecialchars($patient['full_name']); ?></strong></td>
                                </tr>
                                <tr>
                                    <th>National ID (NID):</th>
                                    <td><?= htmlspecialchars($patient['national_id'] ?? 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <th>Gender & Age:</th>
                                    <td><?= htmlspecialchars($patient['gender'] ?? 'N/A'); ?> | <?= htmlspecialchars($patient['age'] ?? 'N/A'); ?> Years</td>
                                </tr>
                                <tr>
                                    <th>Blood Group:</th>
                                    <td><span class="badge bg-danger fs-6"><?= htmlspecialchars($patient['blood_group'] ?? 'N/A'); ?></span></td>
                                </tr>
                            </table>
                        </div>

                        <!-- Contact & Admission Details -->
                        <div class="col-md-6 mb-4">
                            <h6 class="text-secondary border-bottom pb-2 mb-3">Contact & Admission Details</h6>
                            <table class="table table-borderless table-sm">
                                <tr>
                                    <th width="40%">Phone Number:</th>
                                    <td><i class="bi bi-telephone text-primary"></i> <?= htmlspecialchars($patient['phone'] ?? 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <th>Address:</th>
                                    <td><?= nl2br(htmlspecialchars($patient['address'] ?? 'N/A')); ?></td>
                                </tr>
                                <tr>
                                    <th>Emergency Contact:</th>
                                    <td>
                                        <strong><?= htmlspecialchars($patient['emergency_contact_name'] ?? 'N/A'); ?></strong><br>
                                        <small class="text-muted"><i class="bi bi-telephone"></i> <?= htmlspecialchars($patient['emergency_contact_phone'] ?? 'N/A'); ?></small>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Admitted Cabin/Bed:</th>
                                    <td>
                                        <?php if ($cabin): ?>
                                            <span class="badge bg-info text-dark fs-6"><i class="bi bi-door-open"></i> <?= htmlspecialchars($cabin['bed_number']); ?></span>
                                            <small class="text-muted d-block mt-1"><?= htmlspecialchars($cabin['cabin_type']); ?> (Rate: ৳<?= number_format($cabin['daily_rate'], 2); ?>/day)</small>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark border">Not Currently Admitted</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Medical Notes / History -->
                    <div class="row">
                        <div class="col-12">
                            <h6 class="text-secondary border-bottom pb-2 mb-3">Medical History & Allergies</h6>
                            <div class="p-3 bg-light rounded border text-muted">
                                <?= !empty($patient['medical_history']) ? nl2br(htmlspecialchars($patient['medical_history'])) : '<em>No previous medical history or allergies recorded for this patient.</em>'; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Edit Button (Hidden from Nurse) -->
                    <?php if (in_array($role, ['Admin', 'Receptionist'])): ?>
                        <div class="mt-4 text-end">
                            <a href="edit_patient.php?id=<?= $patient['patient_id']; ?>" class="btn btn-warning"><i class="bi bi-pencil-square"></i> Edit Patient Info</a>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>