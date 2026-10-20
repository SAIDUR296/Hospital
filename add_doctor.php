<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$role = $_SESSION['role'];
$session_user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];

if ($role != 'Admin' && $role != 'Doctor') {
    header("Location: dashboard.php");
    exit();
}

$message = "";
$existing_doctor = null;

if ($role == 'Doctor') {
    $stmt = $pdo->prepare("SELECT * FROM doctors WHERE user_id = ?");
    $stmt->execute([$session_user_id]);
    $existing_doctor = $stmt->fetch(PDO::FETCH_ASSOC);
}

$available_users = [];
if ($role == 'Admin') {
    $users_stmt = $pdo->query("SELECT user_id, full_name, email FROM users WHERE role = 'Doctor'");
    $available_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // ১. স্কিমা অনুযায়ী department_id রিসিভ করা হচ্ছে
    $department_id = $_POST['department_id']; 
    $specialization = trim($_POST['specialization']);
    $qualification = trim($_POST['qualification']);
    $license_number = trim($_POST['license_number']);
    $consultation_fee = $_POST['consultation_fee'];
    
    // ২. schedule_hours কে JSON ফরম্যাটে কনভার্ট করা হচ্ছে
    $schedule_hours_raw = trim($_POST['schedule_hours']);
    $schedule_hours_json = json_encode([$schedule_hours_raw]); 
    
    if ($role == 'Doctor') {
        $target_user_id = $session_user_id;
        $status = 'Pending';
    } else {
        $target_user_id = $_POST['user_id'];
        $status = $_POST['status'] ?? 'Approved';
    }

    try {
        $check = $pdo->prepare("SELECT doctor_id FROM doctors WHERE user_id = ?");
        $check->execute([$target_user_id]);
        $exists = $check->fetch();

        if ($exists) {
            // Update Query
            $sql = "UPDATE doctors SET department_id = :department_id, specialization = :specialization, qualification = :qualification, license_number = :license_number, consultation_fee = :consultation_fee, schedule_hours = :schedule_hours, status = :status WHERE user_id = :user_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':department_id' => $department_id,
                ':specialization' => $specialization,
                ':qualification' => $qualification,
                ':license_number' => $license_number,
                ':consultation_fee' => $consultation_fee,
                ':schedule_hours' => $schedule_hours_json,
                ':status' => $status,
                ':user_id' => $target_user_id
            ]);
            $message = "<div class='alert alert-success'>Doctor profile updated successfully! " . ($role == 'Doctor' ? "Status is now <strong>Pending</strong> for Admin approval." : "") . "</div>";
        } else {
            // Insert Query (leave_status এবং availability_status অ্যাড করা হলো)
            $sql = "INSERT INTO doctors (user_id, department_id, specialization, qualification, license_number, consultation_fee, schedule_hours, leave_status, availability_status, status) 
                    VALUES (:user_id, :department_id, :specialization, :qualification, :license_number, :consultation_fee, :schedule_hours, 0, 'Available', :status)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':user_id' => $target_user_id,
                ':department_id' => $department_id,
                ':specialization' => $specialization,
                ':qualification' => $qualification,
                ':license_number' => $license_number,
                ':consultation_fee' => $consultation_fee,
                ':schedule_hours' => $schedule_hours_json,
                ':status' => $status
            ]);
            $message = "<div class='alert alert-success'>Doctor profile submitted successfully! " . ($role == 'Doctor' ? "Please wait for Admin verification." : "") . "</div>";
        }

        if ($role == 'Doctor') {
            $stmt = $pdo->prepare("SELECT * FROM doctors WHERE user_id = ?");
            $stmt->execute([$session_user_id]);
            $existing_doctor = $stmt->fetch(PDO::FETCH_ASSOC);
        }

    } catch (PDOException $e) {
        $message = "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
    }
}

// JSON থেকে শিডিউল রিড করা (ফর্মের জন্য)
$curr_schedule = '';
if (!empty($existing_doctor['schedule_hours'])) {
    $decoded = json_decode($existing_doctor['schedule_hours'], true);
    $curr_schedule = is_array($decoded) ? ($decoded[0] ?? '') : $existing_doctor['schedule_hours'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Registration & Profile - Smart Hospital ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark px-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php"><i class="bi bi-hospital"></i> Smart Hospital ERP</a>
            <div>
                <a href="dashboard.php" class="btn btn-outline-light btn-sm me-2"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a href="logout.php" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-5 mb-5">
        <div class="row justify-content-center">
            <div class="col-md-9">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-3">
                        <h4 class="mb-0">
                            <i class="bi bi-person-badge"></i> 
                            <?= ($role == 'Doctor') ? 'My Doctor Profile & Registration' : 'Add / Manage Doctor Profile'; ?>
                        </h4>
                        <a href="dashboard.php" class="btn btn-light btn-sm text-primary fw-bold"><i class="bi bi-arrow-left"></i> Back</a>
                    </div>
                    <div class="card-body p-4">
                        <?= $message ?>

                        <?php if ($role == 'Doctor' && $existing_doctor): ?>
                            <div class="mb-4 p-3 bg-light rounded border">
                                <h5>Current Status: 
                                    <?php 
                                        $st = $existing_doctor['status'] ?? 'Pending';
                                        $badge = ($st == 'Approved') ? 'bg-success' : (($st == 'Rejected') ? 'bg-danger' : 'bg-warning text-dark');
                                    ?>
                                    <span class="badge <?= $badge; ?>"><?= htmlspecialchars($st); ?></span>
                                </h5>
                                <?php if ($st == 'Pending'): ?>
                                    <p class="text-muted mb-0">Your profile is submitted and waiting for Admin verification.</p>
                                <?php elseif ($st == 'Approved'): ?>
                                    <p class="text-success mb-0"><i class="bi bi-check-circle-fill"></i> Your account has been verified and accepted by the Admin!</p>
                                <?php else: ?>
                                    <p class="text-danger mb-0">Your profile was rejected. Please correct your information and submit again.</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <form action="" method="POST">
                            <?php if ($role == 'Admin'): ?>
                                <div class="mb-3">
                                    <label class="form-label">Select Doctor Account <span class="text-danger">*</span></label>
                                    <select name="user_id" class="form-select" required>
                                        <option value="">Choose doctor user...</option>
                                        <?php foreach ($available_users as $usr): ?>
                                            <option value="<?= $usr['user_id']; ?>">Dr. <?= htmlspecialchars($usr['full_name']); ?> (<?= htmlspecialchars($usr['email']); ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Set Verification Status <span class="text-danger">*</span></label>
                                    <select name="status" class="form-select" required>
                                        <option value="Approved">Approved (Accept Real Doctor)</option>
                                        <option value="Pending">Pending</option>
                                        <option value="Rejected">Rejected</option>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div class="row">
                                <!-- Department IDs mapped dynamically -->
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Department <span class="text-danger">*</span></label>
                                    <select name="department_id" class="form-select" required>
                                        <option value="">Select Department</option>
                                        <?php 
                                        $departments = [
                                            1 => 'Cardiology', 2 => 'Internal Medicine', 3 => 'General Surgery', 4 => 'Pediatrics', 
                                            5 => 'Gynecology & Obstetrics', 6 => 'Orthopedics', 7 => 'Neurology', 8 => 'Ophthalmology (Eye)',
                                            9 => 'Dental & Maxillofacial Surgery', 10 => 'Oncology (Cancer)'
                                        ];
                                        $curr_dept = $existing_doctor['department_id'] ?? '';
                                        foreach ($departments as $id => $dept_name) {
                                            $sel = ($curr_dept == $id) ? 'selected' : '';
                                            echo "<option value=\"$id\" $sel>$dept_name</option>";
                                        }
                                        ?>
                                    </select>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Specialization <span class="text-danger">*</span></label>
                                    <select name="specialization" class="form-select" required>
                                        <option value="">Select Specialization</option>
                                        <?php 
                                        $specializations = [
                                            'Cardiologist', 'Medicine Specialist', 'General Surgeon', 'Paediatrician', 
                                            'Gynecologist', 'Orthopaedic Surgeon', 'Dermatologist', 'ENT Specialist', 
                                            'Ophthalmologist', 'Neurologist', 'Dental Surgeon'
                                        ];
                                        sort($specializations);
                                        $curr_spec = $existing_doctor['specialization'] ?? '';
                                        foreach ($specializations as $spec) {
                                            $sel = ($curr_spec == $spec) ? 'selected' : '';
                                            echo "<option value=\"$spec\" $sel>$spec</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Qualification <span class="text-danger">*</span></label>
                                    <select name="qualification" class="form-select" required>
                                        <option value="">Select Qualification</option>
                                        <?php 
                                        $qualifications = ['MBBS', 'MBBS, FCPS', 'MBBS, MD', 'MBBS, MS', 'BDS', 'BDS, FCPS'];
                                        $curr_qual = $existing_doctor['qualification'] ?? '';
                                        foreach ($qualifications as $qual) {
                                            $sel = ($curr_qual == $qual) ? 'selected' : '';
                                            echo "<option value=\"$qual\" $sel>$qual</option>";
                                        }
                                        ?>
                                    </select>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">License Number <span class="text-danger">*</span></label>
                                    <input type="text" name="license_number" class="form-control" required value="<?= htmlspecialchars($existing_doctor['license_number'] ?? ''); ?>" placeholder="BMDC Registration Number">
                                    <small class="text-muted">Admin checks this to verify real doctor.</small>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Consultation Fee (BDT) <span class="text-danger">*</span></label>
                                    <select name="consultation_fee" class="form-select" required>
                                        <option value="">Select Fee</option>
                                        <?php 
                                        $fees = [300, 500, 700, 1000, 1500, 2000];
                                        $curr_fee = $existing_doctor['consultation_fee'] ?? '';
                                        foreach ($fees as $fee) {
                                            $sel = (floatval($curr_fee) == floatval($fee)) ? 'selected' : '';
                                            echo "<option value=\"$fee\" $sel>৳ $fee</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Schedule Hours <span class="text-danger">*</span></label>
                                    <select name="schedule_hours" class="form-select" required>
                                        <option value="">Select Schedule Hours</option>
                                        <?php 
                                        $schedules = [
                                            'Sat - Mon (10:00 AM - 02:00 PM)', 'Tue - Thu (10:00 AM - 02:00 PM)',
                                            'Sat, Mon, Wed (05:00 PM - 09:00 PM)', 'Friday & Saturday (04:00 PM - 09:00 PM)'
                                        ];
                                        foreach ($schedules as $sch) {
                                            $sel = ($curr_schedule == $sch) ? 'selected' : '';
                                            echo "<option value=\"$sch\" $sel>$sch</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 py-2 fw-bold"><i class="bi bi-check-circle"></i> Save / Submit Profile</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>