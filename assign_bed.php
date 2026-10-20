<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$role      = $_SESSION['role'] ?? '';
$full_name = $_SESSION['full_name'] ?? 'User';

// কেবিন অ্যাসাইনমেন্ট শুধু Admin এবং Receptionist করতে পারবে (Nurse বাদ দেওয়া হয়েছে)
if (!in_array($role, ['Admin', 'Receptionist'])) {
    header("Location: dashboard.php");
    exit();
}

$message      = "";
$message_type = "";

// 🚀 auto-check: cabins_beds টেবিলে doctor_id কলাম না থাকলে অটো তৈরি করা
try {
    $check_doc_col = $pdo->query("SHOW COLUMNS FROM cabins_beds LIKE 'doctor_id'");
    if ($check_doc_col && $check_doc_col->rowCount() == 0) {
        $pdo->exec("ALTER TABLE cabins_beds ADD COLUMN doctor_id INT UNSIGNED NULL AFTER patient_id");
    }
} catch (PDOException $e) {}

// বেড বুকিং/অ্যাসাইনমেন্ট প্রসেস
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_bed'])) {
    $bed_id     = intval($_POST['bed_id'] ?? 0);
    $patient_id = intval($_POST['patient_id'] ?? 0);
    $doctor_id  = intval($_POST['doctor_id'] ?? 0);

    if ($bed_id > 0 && $patient_id > 0 && $doctor_id > 0) {
        try {
            // ১. বেডের স্ট্যাটাস Occupied করা, patient_id ও doctor_id সেট করা
            $upd = $pdo->prepare("UPDATE cabins_beds SET availability_status = 'Occupied', patient_id = ?, doctor_id = ?, emergency_note = NULL WHERE bed_id = ? AND availability_status = 'Available'");
            $upd->execute([$patient_id, $doctor_id, $bed_id]);

            if ($upd->rowCount() > 0) {
                // ২. users টেবিলের সাথে patients টেবিলের user_id স্বয়ংক্রিয়ভাবে সিঙ্ক করা
                $sync_sql = "UPDATE patients p 
                             JOIN users u ON (
                                 (p.phone IS NOT NULL AND p.phone != '' AND REPLACE(p.phone, ' ', '') = REPLACE(u.phone_number, ' ', ''))
                                 OR (p.full_name IS NOT NULL AND p.full_name != '' AND REPLACE(LOWER(p.full_name), ' ', '') = REPLACE(LOWER(u.full_name), ' ', ''))
                             ) 
                             SET p.user_id = u.user_id 
                             WHERE p.patient_id = ? AND (p.user_id IS NULL OR p.user_id = 0)";
                
                $pdo->prepare($sync_sql)->execute([$patient_id]);

                $message      = "Bed successfully assigned to the patient with assigned doctor!";
                $message_type = "success";
            } else {
                $message      = "Selected bed is no longer available!";
                $message_type = "warning";
            }
        } catch (PDOException $e) {
            $message      = "Database Error: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message      = "Please select a valid bed, patient, and assigned doctor!";
        $message_type = "warning";
    }
}

// খালি বেড, নিবন্ধিত রোগী ও অ্যাপ্রুভড ডাক্তারদের ডাটা ফেচ
try {
    $available_beds = $pdo->query("SELECT * FROM cabins_beds WHERE availability_status = 'Available' ORDER BY bed_number ASC")->fetchAll(PDO::FETCH_ASSOC);
    $patients       = $pdo->query("SELECT patient_id, full_name, phone, blood_group FROM patients ORDER BY patient_id DESC")->fetchAll(PDO::FETCH_ASSOC);
    
    // অ্যাপ্রুভড ও এক্টিভ ডাক্তারদের তালিকা ফেচ
    $sql_docs = "SELECT d.doctor_id, d.department_name, u.full_name 
                 FROM doctors d 
                 JOIN users u ON d.user_id = u.user_id 
                 WHERE d.status = 'Approved' AND u.status = 'Active' 
                 ORDER BY u.full_name ASC";
    $doctors = $pdo->query($sql_docs)->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

$selected_bed_id = intval($_GET['bed_id'] ?? 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Cabin & Bed - Smart Hospital ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { min-height: 100vh; background-color: #2c3e50; color: white; width: 260px; flex-shrink: 0; }
        .sidebar a { color: #adb5bd; text-decoration: none; padding: 10px 15px; display: block; border-radius: 5px; margin-bottom: 5px; }
        .sidebar a:hover, .sidebar a.active { background-color: #34495e; color: #ffffff; }
        .sidebar i { margin-right: 10px; }
        .card-box { max-width: 650px; margin: 0 auto; border-radius: 10px; }
    </style>
</head>
<body>

<div class="d-flex">
    <div class="sidebar p-3">
        <h4 class="text-center text-white mb-4"><i class="bi bi-hospital"></i> Hospital ERP</h4>
        <hr class="text-secondary">
        <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="patient_list.php"><i class="bi bi-people"></i> Patient List</a>
        <a href="book_appointment.php"><i class="bi bi-calendar-check"></i> Appointments</a>
        <a href="cabins.php" class="active"><i class="bi bi-building"></i> Cabins & Beds</a>
        <hr class="text-secondary mt-4">
        <a href="logout.php" class="text-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>

    <div class="w-100">
        <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm px-4 py-3 mb-4">
            <div class="container-fluid">
                <span class="navbar-brand mb-0 h1">Assign Cabin / Bed to Patient</span>
                <div class="d-flex align-items-center">
                    <span class="me-3 text-muted">Welcome, <strong><?= htmlspecialchars($full_name); ?></strong></span>
                    <a href="logout.php" class="btn btn-sm btn-outline-danger">Logout</a>
                </div>
            </div>
        </nav>

        <div class="container-fluid px-4">
            <div class="card card-box shadow-sm border-0">
                <div class="card-header bg-primary text-white p-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-person-plus-fill me-2"></i>Book / Assign Cabin Bed</h5>
                    <a href="cabins.php" class="btn btn-sm btn-outline-light"><i class="bi bi-arrow-left"></i> Back to Cabins</a>
                </div>
                <div class="card-body p-4">

                    <?php if (!empty($message)): ?>
                        <div class="alert alert-<?= $message_type; ?> alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="assign_bed.php">
                        <input type="hidden" name="assign_bed" value="1">

                        <!-- ১. খালি বেড নির্বাচন -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Select Available Bed / Cabin <span class="text-danger">*</span></label>
                            <select name="bed_id" class="form-select" required>
                                <option value="">-- Choose Available Bed --</option>
                                <?php foreach ($available_beds as $bed): ?>
                                    <option value="<?= $bed['bed_id']; ?>" <?= ($selected_bed_id == $bed['bed_id']) ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($bed['bed_number']); ?> (<?= htmlspecialchars($bed['cabin_type']); ?>) - Rate: ৳<?= number_format($bed['daily_rate'], 2); ?>/day
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- ২. রোগী নির্বাচন -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Select Patient <span class="text-danger">*</span></label>
                            <select name="patient_id" class="form-select" required>
                                <option value="">-- Choose Registered Patient --</option>
                                <?php foreach ($patients as $p): ?>
                                    <option value="<?= $p['patient_id']; ?>">
                                        #PAT-<?= $p['patient_id']; ?> | <?= htmlspecialchars($p['full_name']); ?> (Phone: <?= htmlspecialchars($p['phone'] ?: 'N/A'); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 🚀 ৩. চিকিৎসা প্রদানকারী ডাক্তার নির্বাচন (NEW ADDED) -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Assign Treating Doctor <span class="text-danger">*</span></label>
                            <select name="doctor_id" class="form-select" required>
                                <option value="">-- Choose Approved Doctor --</option>
                                <?php foreach ($doctors as $doc): ?>
                                    <option value="<?= $doc['doctor_id']; ?>">
                                        Dr. <?= htmlspecialchars($doc['full_name']); ?> (<?= htmlspecialchars($doc['department_name'] ?: 'General'); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary py-2 fw-bold">
                                <i class="bi bi-check-circle me-1"></i> Confirm Bed Assignment
                            </button>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>