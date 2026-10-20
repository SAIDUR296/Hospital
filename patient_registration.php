<?php
session_start();
require 'db_connect.php';

// সিকিউরিটি চেক: শুধুমাত্র অ্যাডমিন ও অ্যাক্টিভ রিসেপশনিস্ট এক্সেস করতে পারবে
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Receptionist'])) {
    header("Location: dashboard.php");
    exit();
}

$message = "";
$message_type = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name               = trim($_POST['full_name'] ?? '');
    $username                = trim($_POST['username'] ?? '');
    $email                   = trim($_POST['email'] ?? '');
    $phone_number            = trim($_POST['phone_number'] ?? '');
    $password                = trim($_POST['password'] ?? '123456'); // ডিফল্ট পাসওয়ার্ড
    $gender                  = trim($_POST['gender'] ?? '');
    $dob                     = !empty($_POST['dob']) ? $_POST['dob'] : NULL;
    $blood_group             = trim($_POST['blood_group'] ?? '');
    $address                 = trim($_POST['address'] ?? '');
    $emergency_contact_name  = trim($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_phone = trim($_POST['emergency_contact_phone'] ?? '');

    if (empty($full_name) || empty($username) || empty($phone_number)) {
        $message = "Full Name, Username, and Phone Number are required!";
        $message_type = "warning";
    } else {
        try {
            // ইউজারনেম ও ইমেইল ইউনিক চেক
            $chk = $pdo->prepare("SELECT user_id FROM users WHERE username = ? OR (email = ? AND email != '')");
            $chk->execute([$username, $email]);

            if ($chk->rowCount() > 0) {
                $message = "Username or Email already exists!";
                $message_type = "danger";
            } else {
                $pdo->beginTransaction();

                // ১. users টেবিলে পেশেন্ট ইনসার্ট
                $pass_hash = password_hash($password, PASSWORD_BCRYPT);
                $ins_u = $pdo->prepare("INSERT INTO users (username, password_hash, role, full_name, email, phone_number, status) VALUES (?, ?, 'Patient', ?, ?, ?, 'Active')");
                $ins_u->execute([$username, $pass_hash, $full_name, $email, $phone_number]);
                $new_user_id = $pdo->lastInsertId();

                // ২. patients টেবিলে ডিটেইলস ইনসার্ট
                $ins_p = $pdo->prepare("INSERT INTO patients (user_id, full_name, phone, gender, dob, blood_group, address, emergency_contact_name, emergency_contact_phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins_p->execute([$new_user_id, $full_name, $phone_number, $gender, $dob, $blood_group, $address, $emergency_contact_name, $emergency_contact_phone]);

                $pdo->commit();

                $message = "Patient registered successfully! Default Password: $password";
                $message_type = "success";
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "Registration Error: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Registration - Smart Hospital ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card-custom { max-width: 800px; margin: 30px auto; border: none; border-radius: 12px; }
        .card-header { background-color: #1e293b; color: #ffffff; border-top-left-radius: 12px !important; border-top-right-radius: 12px !important; }
    </style>
</head>
<body>

<div class="container py-4">
    <div class="card card-custom shadow">
        <div class="card-header p-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-person-plus-fill me-2"></i>New Patient Registration (Frontdesk)</h5>
            <a href="dashboard.php" class="btn btn-sm btn-outline-light"><i class="bi bi-arrow-left"></i> Dashboard</a>
        </div>
        <div class="card-body p-4">

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $message_type; ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST" action="patient_registration.php">
                <h6 class="fw-bold text-primary mb-3 border-bottom pb-2">Account Login Info</h6>
                <div class="row mb-3">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control" placeholder="e.g. Rahul Hasan" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" placeholder="e.g. rahul123" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold">Phone Number <span class="text-danger">*</span></label>
                        <input type="text" name="phone_number" class="form-control" placeholder="017XXXXXXXX" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold">Email Address</label>
                        <input type="email" name="email" class="form-control" placeholder="patient@example.com">
                    </div>
                </div>

                <h6 class="fw-bold text-primary mb-3 border-bottom pb-2 mt-4">Personal & Emergency Details</h6>
                <div class="row mb-3">
                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-semibold">Gender</label>
                        <select name="gender" class="form-select">
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-semibold">Date of Birth</label>
                        <input type="date" name="dob" class="form-control">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-semibold">Blood Group</label>
                        <select name="blood_group" class="form-select">
                            <option value="">-- Select --</option>
                            <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bg): ?>
                                <option value="<?= $bg; ?>"><?= $bg; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-12 mb-3">
                        <label class="form-label fw-semibold">Address</label>
                        <input type="text" name="address" class="form-control" placeholder="House, Road, Area...">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold">Emergency Contact Person</label>
                        <input type="text" name="emergency_contact_name" class="form-control" placeholder="Relative Name">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold">Emergency Contact Phone</label>
                        <input type="text" name="emergency_contact_phone" class="form-control" placeholder="018XXXXXXXX">
                    </div>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                    <a href="dashboard.php" class="btn btn-secondary me-md-2">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-person-check me-1"></i> Register Patient</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>