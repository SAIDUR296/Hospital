<?php
session_start();
require 'db_connect.php';

// ১. লগইন সিকিউরিটি চেক
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'] ?? 'Patient';
$message = "";
$message_type = "";

// 🆕 Auto-fix: doctors টেবিলে signature_image কলাম চেক ও এড করা
try {
    $pdo->exec("ALTER TABLE doctors ADD COLUMN signature_image VARCHAR(255) NULL");
} catch (PDOException $e) {}

// 🆕 Auto-fix: users টেবিলে ব্যাংকিং ডিটেইলস ও স্যালারি কলাম চেক ও এড করা
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN bank_name VARCHAR(100) NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN account_number VARCHAR(50) NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN account_holder VARCHAR(100) NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN branch_name VARCHAR(100) NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN monthly_salary DECIMAL(10,2) DEFAULT 0.00");
} catch (PDOException $e) {}

// ২. ডাটাবেজ থেকে রিয়েল-টাইম ইউজার ডাটা ফেচ
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        die("User account not found.");
    }

    // পেশেন্ট ডাটা ফেচ (user_id অথবা phone দিয়ে নিরাপদে খোঁজা)
    $patient = null;
    if ($role == 'Patient') {
        $stmt_p = $pdo->prepare("SELECT * FROM patients WHERE user_id = ? OR (phone = ? AND phone != '')");
        $stmt_p->execute([$user_id, $user['phone_number'] ?? '']);
        $patient = $stmt_p->fetch(PDO::FETCH_ASSOC);

        // যদি patients টেবিলে user_id ফাঁকা থাকে, তবে অটো লিঙ্ক করে নেওয়া
        if ($patient && empty($patient['user_id'])) {
            $pdo->prepare("UPDATE patients SET user_id = ? WHERE patient_id = ?")->execute([$user_id, $patient['patient_id']]);
            $patient['user_id'] = $user_id;
        }
    }

    $doctor = null;
    if ($role == 'Doctor') {
        $stmt_d = $pdo->prepare("SELECT * FROM doctors WHERE user_id = ?");
        $stmt_d->execute([$user_id]);
        $doctor = $stmt_d->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

// ৩. প্রোফাইল আপডেট সেভ লজিক
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name    = trim($_POST['full_name'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');

    if (empty($full_name) || empty($email)) {
        $message = "Full Name and Email are required!";
        $message_type = "danger";
    } else {
        try {
            // Patient Profile Update
            if ($role == 'Patient') {
                $upd_user = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone_number = ? WHERE user_id = ?");
                $upd_user->execute([$full_name, $email, $phone_number, $user_id]);

                $dob                     = !empty($_POST['dob']) ? $_POST['dob'] : NULL;
                $gender                  = !empty($_POST['gender']) ? $_POST['gender'] : NULL;
                $blood_group             = !empty($_POST['blood_group']) ? $_POST['blood_group'] : NULL;
                $national_id             = trim($_POST['national_id'] ?? '');
                $national_id             = !empty($national_id) ? $national_id : NULL;
                $address                 = trim($_POST['address'] ?? '');
                $emergency_contact_name  = trim($_POST['emergency_contact_name'] ?? '');
                $emergency_contact_phone = trim($_POST['emergency_contact_phone'] ?? '');

                if ($patient && !empty($patient['patient_id'])) {
                    $upd_pat = $pdo->prepare("UPDATE patients SET user_id = ?, full_name = ?, dob = ?, gender = ?, blood_group = ?, national_id = ?, phone = ?, address = ?, emergency_contact_name = ?, emergency_contact_phone = ? WHERE patient_id = ?");
                    $upd_pat->execute([$user_id, $full_name, $dob, $gender, $blood_group, $national_id, $phone_number, $address, $emergency_contact_name, $emergency_contact_phone, $patient['patient_id']]);
                } else {
                    $ins_pat = $pdo->prepare("INSERT INTO patients (user_id, full_name, dob, gender, blood_group, national_id, phone, address, emergency_contact_name, emergency_contact_phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins_pat->execute([$user_id, $full_name, $dob, $gender, $blood_group, $national_id, $phone_number, $address, $emergency_contact_name, $emergency_contact_phone]);
                }

                $stmt_p = $pdo->prepare("SELECT * FROM patients WHERE user_id = ? OR (phone = ? AND phone != '')");
                $stmt_p->execute([$user_id, $phone_number]);
                $patient = $stmt_p->fetch(PDO::FETCH_ASSOC);

                $message = "Patient profile updated successfully!";
                $message_type = "success";

            // Doctor Profile Update (Signature Upload with Auto-Rotate & Dimension Fix)
            } elseif ($role == 'Doctor') {
                $department_name  = trim($_POST['department_name'] ?? '');
                $specialization   = trim($_POST['specialization'] ?? '');
                $qualification    = trim($_POST['qualification'] ?? '');
                $license_number   = trim($_POST['license_number'] ?? '');
                $consultation_fee = $_POST['consultation_fee'] ?? 0.00;
                
                // Banking & Salary details capture
                $bank_name        = trim($_POST['bank_name'] ?? '');
                $account_number   = trim($_POST['account_number'] ?? '');
                $account_holder   = trim($_POST['account_holder'] ?? '');
                $branch_name      = trim($_POST['branch_name'] ?? '');
                $monthly_salary   = floatval($_POST['monthly_salary'] ?? 0.00);

                // Checkboxes array capture & string conversion
                $schedule_arr     = $_POST['schedule_hours'] ?? [];
                $schedule_hours   = !empty($schedule_arr) ? implode(', ', $schedule_arr) : '';

                // 🆕 Signature Image File Upload Logic with Auto-Rotate & Dimension Fix
                $sig_filename = $doctor['signature_image'] ?? '';
                if (isset($_FILES['signature_image']) && $_FILES['signature_image']['error'] == 0) {
                    $ext = strtolower(pathinfo($_FILES['signature_image']['name'], PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                    if (in_array($ext, $allowed)) {
                        $sig_filename = "sig_doc_" . $user_id . "_" . time() . "." . $ext;
                        if (!is_dir('uploads/signatures')) {
                            mkdir('uploads/signatures', 0777, true);
                        }
                        $target_path = "uploads/signatures/" . $sig_filename;
                        if (move_uploaded_file($_FILES['signature_image']['tmp_name'], $target_path)) {
                            
                            // Auto rotate fix for EXIF orientation
                            if (in_array($ext, ['jpg', 'jpeg']) && function_exists('exif_read_data')) {
                                @$exif = exif_read_data($target_path);
                                if ($exif && isset($exif['Orientation'])) {
                                    $image = imagecreatefromjpeg($target_path);
                                    $rotate = 0;
                                    switch ($exif['Orientation']) {
                                        case 3: $rotate = 180; break;
                                        case 6: $rotate = -90; break;
                                        case 8: $rotate = 90; break;
                                    }
                                    if ($rotate && $image) {
                                        $image = imagerotate($image, $rotate, 0);
                                        imagejpeg($image, $target_path, 90);
                                        imagedestroy($image);
                                    }
                                }
                            }

                            // Dimension Check: Jodi portrait/khara hoy (height > width), tahole automatic rotate kore landscape kore nibe
                            list($width, $height) = @getimagesize($target_path);
                            if ($width && $height && $height > $width) {
                                if ($ext == 'png' && function_exists('imagecreatefrompng')) {
                                    $image = @imagecreatefrompng($target_path);
                                    if ($image) {
                                        $image = imagerotate($image, 90, 0);
                                        imagepng($image, $target_path);
                                        imagedestroy($image);
                                    }
                                } elseif (in_array($ext, ['jpg', 'jpeg', 'webp']) && function_exists('imagecreatefromjpeg')) {
                                    $image = @imagecreatefromjpeg($target_path);
                                    if ($image) {
                                        $image = imagerotate($image, 90, 0);
                                        imagejpeg($image, $target_path, 90);
                                        imagedestroy($image);
                                    }
                                }
                            }

                        }
                    }
                }

                $upd_user = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone_number = ?, bank_name = ?, account_number = ?, account_holder = ?, branch_name = ?, monthly_salary = ?, status = 'Inactive' WHERE user_id = ?");
                $upd_user->execute([$full_name, $email, $phone_number, $bank_name, $account_number, $account_holder, $branch_name, $monthly_salary, $user_id]);

                if ($doctor) {
                    $upd_doc = $pdo->prepare("UPDATE doctors SET department_name = ?, specialization = ?, qualification = ?, license_number = ?, consultation_fee = ?, schedule_hours = ?, signature_image = ?, status = 'Pending' WHERE user_id = ?");
                    $upd_doc->execute([$department_name, $specialization, $qualification, $license_number, $consultation_fee, $schedule_hours, $sig_filename, $user_id]);
                } else {
                    $ins_doc = $pdo->prepare("INSERT INTO doctors (user_id, department_name, specialization, qualification, license_number, consultation_fee, schedule_hours, signature_image, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
                    $ins_doc->execute([$user_id, $department_name, $specialization, $qualification, $license_number, $consultation_fee, $schedule_hours, $sig_filename]);
                }

                // Refresh doctor data
                $stmt_d = $pdo->prepare("SELECT * FROM doctors WHERE user_id = ?");
                $stmt_d->execute([$user_id]);
                $doctor = $stmt_d->fetch(PDO::FETCH_ASSOC);

                $user['status'] = 'Inactive';
                $message = "Doctor credentials, bank info, schedule & digital signature submitted! Awaiting Admin Approval.";
                $message_type = "warning";

            // Staff Profile Update
            } else {
                $degree                  = trim($_POST['degree'] ?? '');
                $education_qualification = trim($_POST['education_qualification'] ?? '');
                $registration_number     = trim($_POST['registration_number'] ?? '');
                $experience_years        = intval($_POST['experience_years'] ?? 0);

                // Banking & Salary details capture
                $bank_name        = trim($_POST['bank_name'] ?? '');
                $account_number   = trim($_POST['account_number'] ?? '');
                $account_holder   = trim($_POST['account_holder'] ?? '');
                $branch_name      = trim($_POST['branch_name'] ?? '');
                $monthly_salary   = floatval($_POST['monthly_salary'] ?? 0.00);

                $upd_staff = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone_number = ?, degree = ?, education_qualification = ?, registration_number = ?, experience_years = ?, bank_name = ?, account_number = ?, account_holder = ?, branch_name = ?, monthly_salary = ?, status = 'Inactive' WHERE user_id = ?");
                $upd_staff->execute([$full_name, $email, $phone_number, $degree, $education_qualification, $registration_number, $experience_years, $bank_name, $account_number, $account_holder, $branch_name, $monthly_salary, $user_id]);

                $user['status'] = 'Inactive';
                $message = "Staff credentials & bank details updated successfully! Awaiting Admin Approval.";
                $message_type = "warning";
            }

            $_SESSION['full_name'] = $full_name;
            $user['full_name']      = $full_name;
            $user['email']          = $email;
            $user['phone_number']   = $phone_number;
            $user['bank_name']      = $bank_name ?? $user['bank_name'];
            $user['account_number'] = $account_number ?? $user['account_number'];
            $user['account_holder'] = $account_holder ?? $user['account_holder'];
            $user['branch_name']    = $branch_name ?? $user['branch_name'];
            $user['monthly_salary'] = $monthly_salary ?? $user['monthly_salary'];

        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $message = "Error: This National ID, Email, or Phone number belongs to another user!";
            } else {
                $message = "Update Error: " . $e->getMessage();
            }
            $message_type = "danger";
        }
    }
}

// ৪. স্ট্যাটাস ভ্যালিডেশন
$is_approved_status = ($user['status'] === 'Active');
if ($role == 'Doctor' && $doctor) {
    if ($doctor['status'] !== 'Approved') {
        $is_approved_status = false;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - Smart Hospital ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .profile-card { max-width: 850px; margin: 30px auto; border-radius: 12px; border: none; }
        .card-header { background-color: #1e293b; color: #fff; border-top-left-radius: 12px !important; border-top-right-radius: 12px !important; }
        .role-box { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; }
    </style>
</head>
<body>

<div class="container py-4">
    <div class="card profile-card shadow">
        <div class="card-header p-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-person-gear me-2"></i>Edit Profile (<?= htmlspecialchars($role); ?>)</h5>
            <a href="dashboard.php" class="btn btn-sm btn-outline-light"><i class="bi bi-arrow-left"></i> Dashboard</a>
        </div>
        <div class="card-body p-4">

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $message_type; ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- 🆕 Added enctype="multipart/form-data" for file uploads -->
            <form method="POST" action="edit_profile.php" enctype="multipart/form-data">
                
                <!-- ১. একাউন্ট সাধারণ তথ্য -->
                <div class="row mb-3">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold">Username</label>
                        <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($user['username']); ?>" readonly>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold">Account Role</label>
                        <input type="text" class="form-control bg-light text-primary fw-bold" value="<?= htmlspecialchars($role); ?>" readonly>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name']); ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-semibold">Phone Number <span class="text-danger">*</span></label>
                        <input type="text" name="phone_number" class="form-control" value="<?= htmlspecialchars($user['phone_number'] ?? ''); ?>" required>
                    </div>
                </div>

                <!-- 🏦 🆕 STAFF & DOCTOR BANK ACCOUNT & SALARY DETAILS SECTION -->
                <?php if ($role != 'Patient'): ?>
                    <div class="role-box mb-4 border-start border-4 border-success">
                        <h6 class="fw-bold text-success mb-3"><i class="bi bi-bank me-2"></i>Bank Account & Monthly Salary Details (For Payroll & Withdrawals)</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Bank Name <span class="text-danger">*</span></label>
                                <input type="text" name="bank_name" class="form-control" value="<?= htmlspecialchars($user['bank_name'] ?? ''); ?>" placeholder="e.g. Dutch-Bangla Bank / Islami Bank" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Account Number <span class="text-danger">*</span></label>
                                <input type="text" name="account_number" class="form-control" value="<?= htmlspecialchars($user['account_number'] ?? ''); ?>" placeholder="e.g. 151.120.xxxxx" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Account Holder Name <span class="text-danger">*</span></label>
                                <input type="text" name="account_holder" class="form-control" value="<?= htmlspecialchars($user['account_holder'] ?? $user['full_name']); ?>" placeholder="Name on Bank Account" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Branch Name / District</label>
                                <input type="text" name="branch_name" class="form-control" value="<?= htmlspecialchars($user['branch_name'] ?? ''); ?>" placeholder="e.g. Dhanmondi Branch, Dhaka">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Expected Monthly Salary (BDT) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" name="monthly_salary" class="form-control" value="<?= htmlspecialchars($user['monthly_salary'] ?? '0.00'); ?>" placeholder="e.g. 25000" required>
                                <small class="text-muted">Admin-er map-krito maski salary eikhane set thakbe.</small>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ২. DOCTOR FIELDS (Signature Upload Added) -->
                <?php if ($role == 'Doctor'): ?>
                    <div class="role-box mb-4">
                        <h6 class="fw-bold text-dark mb-3"><i class="bi bi-hospital text-primary me-2"></i>Doctor Credentials, License, Schedule & Signature</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Department <span class="text-danger">*</span></label>
                                <select name="department_name" class="form-select" required>
                                    <option value="">-- Select Department --</option>
                                    <?php 
                                    $departments = [
                                        'Cardiology', 'Internal Medicine', 'General Surgery', 'Pediatrics', 
                                        'Gynecology & Obstetrics', 'Orthopedics', 'Neurology', 'Ophthalmology (Eye)', 
                                        'ENT (Otolaryngology)', 'Dermatology & Venereology', 'Psychiatry', 
                                        'Nephrology', 'Urology', 'Oncology (Cancer)', 'Physical Medicine & Rehabilitation'
                                    ];
                                    foreach ($departments as $d) {
                                        $selected = (($doctor['department_name'] ?? '') == $d) ? 'selected' : '';
                                        echo "<option value=\"$d\" $selected>$d</option>";
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Specialization <span class="text-danger">*</span></label>
                                <select name="specialization" class="form-select" required>
                                    <option value="">-- Select Specialization --</option>
                                    <?php 
                                    $specializations = [
                                        'Cardiologist', 'Medicine Specialist', 'General Surgeon', 'Pediatrician', 
                                        'Gynecologist & Obstetrician', 'Orthopedic Surgeon', 'Neurologist', 
                                        'Ophthalmologist', 'ENT Specialist', 'Dermatologist', 'Psychiatrist', 
                                        'Nephrologist', 'Urologist', 'Oncologist', 'General Practitioner (GP)'
                                    ];
                                    foreach ($specializations as $spec) {
                                        $selected = (($doctor['specialization'] ?? '') == $spec) ? 'selected' : '';
                                        echo "<option value=\"$spec\" $selected>$spec</option>";
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Medical Qualification <span class="text-danger">*</span></label>
                                <select name="qualification" class="form-select" required>
                                    <option value="">-- Select Qualification --</option>
                                    <?php 
                                    $qualifications = [
                                        'MBBS', 'MBBS, FCPS', 'MBBS, MS', 'MBBS, MD', 
                                        'MBBS, MRCP', 'MBBS, FRCS', 'MBBS, DCH', 'MBBS, D-Ortho', 
                                        'MBBS, FCPS (Medicine)', 'MBBS, FCPS (Surgery)', 
                                        'MBBS, FCPS (Gynecology & Obstetrics)', 'MBBS, FCPS (Pediatrics)', 'MBBS, MPH'
                                    ];
                                    foreach ($qualifications as $qual) {
                                        $selected = (($doctor['qualification'] ?? '') == $qual) ? 'selected' : '';
                                        echo "<option value=\"$qual\" $selected>$qual</option>";
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">BMDC License Number <span class="text-danger">*</span></label>
                                <input type="text" name="license_number" class="form-control" value="<?= htmlspecialchars($doctor['license_number'] ?? ''); ?>" placeholder="BMDC Reg No" required>
                            </div>

                            <!-- Visiting Hours / Schedule Checkboxes (Multiple Select) -->
                            <div class="col-md-12 mb-3">
                                <label class="form-label fw-semibold">Visiting Hours / Schedule (Multiple Select) <span class="text-danger">*</span></label>
                                <div class="row bg-white p-3 border rounded">
                                    <?php 
                                    $selected_schedules = isset($doctor['schedule_hours']) ? explode(', ', $doctor['schedule_hours']) : [];
                                    $schedules = [
                                        'Sat - Wed (10:00 AM - 02:00 PM)',
                                        'Sat - Wed (04:00 PM - 09:00 PM)',
                                        'Sun - Thu (09:00 AM - 01:00 PM)',
                                        'Sun - Thu (05:00 PM - 10:00 PM)',
                                        'Everyday (10:00 AM - 01:00 PM)',
                                        'Everyday (04:00 PM - 08:00 PM)',
                                        'Friday Only (09:00 AM - 12:00 PM)',
                                        'Friday Only (03:00 PM - 06:00 PM)'
                                    ];
                                    foreach ($schedules as $sch) {
                                        $checked = in_array($sch, $selected_schedules) ? 'checked' : '';
                                        echo '<div class="col-md-6 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="schedule_hours[]" value="' . $sch . '" id="sch_' . md5($sch) . '" ' . $checked . '>
                                                    <label class="form-check-label" for="sch_' . md5($sch) . '">' . $sch . '</label>
                                                </div>
                                              </div>';
                                    }
                                    ?>
                                </div>
                                <small class="text-muted">Apni ekadhik din ba somoy select korte parben.</small>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Consultation Fee (BDT) <span class="text-danger">*</span></label>
                                <input type="number" name="consultation_fee" class="form-control" value="<?= htmlspecialchars($doctor['consultation_fee'] ?? '500'); ?>" required>
                            </div>

                            <!-- 🆕 Digital Signature Upload Field -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Upload Digital Signature (PNG/JPG) <span class="text-danger">*</span></label>
                                <input type="file" name="signature_image" class="form-control" accept="image/*" <?= empty($doctor['signature_image']) ? 'required' : ''; ?>>
                                <small class="text-muted d-block mt-1">Upload transparent/white background signature image.</small>
                                <?php if (!empty($doctor['signature_image'])): ?>
                                    <div class="mt-2 p-2 bg-white border rounded d-inline-block">
                                        <small class="text-success d-block fw-semibold mb-1"><i class="bi bi-check-circle me-1"></i> Current Signature:</small>
                                        <img src="uploads/signatures/<?= htmlspecialchars($doctor['signature_image']); ?>" alt="Doctor Signature" style="height: 40px; max-width: 150px; object-fit: contain;">
                                    </div>
                                <?php endif; ?>
                            </div>

                        </div>
                    </div>

                <!-- ৩. RECEPTIONIST / ACCOUNTANT FIELDS -->
                <?php elseif (in_array($role, ['Receptionist', 'Accountant'])): ?>
                    <div class="role-box mb-4">
                        <h6 class="fw-bold text-dark mb-3"><i class="bi bi-person-workspace text-primary me-2"></i>Frontdesk & Staff Qualifications</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Highest Education / Degree <span class="text-danger">*</span></label>
                                <select name="degree" class="form-select" required>
                                    <option value="">-- Select Degree --</option>
                                    <option value="BBA / MBA in Accounting" <?= ($user['degree'] ?? '') == 'BBA / MBA in Accounting' ? 'selected' : ''; ?>>BBA / MBA in Accounting</option>
                                    <option value="Bachelor of Arts (BA)" <?= ($user['degree'] ?? '') == 'Bachelor of Arts (BA)' ? 'selected' : ''; ?>>Bachelor of Arts (BA)</option>
                                    <option value="Bachelor of Science (B.Sc)" <?= ($user['degree'] ?? '') == 'Bachelor of Science (B.Sc)' ? 'selected' : ''; ?>>Bachelor of Science (B.Sc)</option>
                                    <option value="HSC / Higher Secondary" <?= ($user['degree'] ?? '') == 'HSC / Higher Secondary' ? 'selected' : ''; ?>>HSC / Higher Secondary</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">NID or Employee Reg No <span class="text-danger">*</span></label>
                                <input type="text" name="registration_number" class="form-control" value="<?= htmlspecialchars($user['registration_number'] ?? ''); ?>" placeholder="NID or Reg Card No" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Special Skill / Training</label>
                                <input type="text" name="education_qualification" class="form-control" value="<?= htmlspecialchars($user['education_qualification'] ?? ''); ?>" placeholder="e.g. Computer Office Application / ERP">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Work Experience (Years)</label>
                                <input type="number" name="experience_years" class="form-control" value="<?= htmlspecialchars($user['experience_years'] ?? ''); ?>" placeholder="e.g. 2">
                            </div>
                        </div>
                    </div>

                <!-- ৪. NURSE FIELDS -->
                <?php elseif ($role == 'Nurse'): ?>
                    <div class="role-box mb-4">
                        <h6 class="fw-bold text-dark mb-3"><i class="bi bi-heart-pulse text-danger me-2"></i>Nursing Credentials</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Nursing Degree <span class="text-danger">*</span></label>
                                <select name="degree" class="form-select" required>
                                    <option value="">-- Select Degree --</option>
                                    <option value="B.Sc in Nursing" <?= ($user['degree'] ?? '') == 'B.Sc in Nursing' ? 'selected' : ''; ?>>B.Sc in Nursing</option>
                                    <option value="Diploma in Nursing Science" <?= ($user['degree'] ?? '') == 'Diploma in Nursing Science' ? 'selected' : ''; ?>>Diploma in Nursing Science</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">BNMC Registration No <span class="text-danger">*</span></label>
                                <input type="text" name="registration_number" class="form-control" value="<?= htmlspecialchars($user['registration_number'] ?? ''); ?>" placeholder="BNMC Reg No" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Work Experience (Years)</label>
                                <input type="number" name="experience_years" class="form-control" value="<?= htmlspecialchars($user['experience_years'] ?? ''); ?>" placeholder="e.g. 3">
                            </div>
                        </div>
                    </div>

                <!-- ৫. PHARMACIST FIELDS -->
                <?php elseif ($role == 'Pharmacist'): ?>
                    <div class="role-box mb-4">
                        <h6 class="fw-bold text-dark mb-3"><i class="bi bi-capsule text-success me-2"></i>Pharmacy Credentials</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Pharmacy Degree <span class="text-danger">*</span></label>
                                <select name="degree" class="form-select" required>
                                    <option value="">-- Select Degree --</option>
                                    <option value="B.Pharm" <?= ($user['degree'] ?? '') == 'B.Pharm' ? 'selected' : ''; ?>>B.Pharm</option>
                                    <option value="Diploma in Pharmacy" <?= ($user['degree'] ?? '') == 'Diploma in Pharmacy' ? 'selected' : ''; ?>>Diploma in Pharmacy</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Pharmacy Council Reg No <span class="text-danger">*</span></label>
                                <input type="text" name="registration_number" class="form-control" value="<?= htmlspecialchars($user['registration_number'] ?? ''); ?>" placeholder="PCB Reg No" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Work Experience (Years)</label>
                                <input type="number" name="experience_years" class="form-control" value="<?= htmlspecialchars($user['experience_years'] ?? ''); ?>" placeholder="e.g. 2">
                            </div>
                        </div>
                    </div>

                <!-- ৬. LAB TECHNICIAN FIELDS -->
                <?php elseif ($role == 'Lab Technician'): ?>
                    <div class="role-box mb-4">
                        <h6 class="fw-bold text-dark mb-3"><i class="bi bi-eyedropper text-info me-2"></i>Lab Technology Credentials</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Specialized Lab Technology <span class="text-danger">*</span></label>
                                <select name="degree" class="form-select" required>
                                    <option value="">-- Select Specialization --</option>
                                    <option value="B.Sc in MLT" <?= ($user['degree'] ?? '') == 'B.Sc in MLT' ? 'selected' : ''; ?>>B.Sc in MLT</option>
                                    <option value="Diploma in Pathology" <?= ($user['degree'] ?? '') == 'Diploma in Pathology' ? 'selected' : ''; ?>>Diploma in Pathology</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Tech License / Reg No <span class="text-danger">*</span></label>
                                <input type="text" name="registration_number" class="form-control" value="<?= htmlspecialchars($user['registration_number'] ?? ''); ?>" placeholder="Tech License No" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Lab Experience (Years)</label>
                                <input type="number" name="experience_years" class="form-control" value="<?= htmlspecialchars($user['experience_years'] ?? ''); ?>" placeholder="e.g. 4">
                            </div>
                        </div>
                    </div>

                <!-- ৭. PATIENT FIELDS -->
                <?php elseif ($role == 'Patient'): ?>
                    <div class="role-box mb-4">
                        <h6 class="fw-bold text-dark mb-3"><i class="bi bi-person me-2"></i>Patient Information</h6>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label fw-semibold">Date of Birth</label>
                                <input type="date" name="dob" class="form-control" value="<?= htmlspecialchars($patient['dob'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label fw-semibold">Gender</label>
                                <select name="gender" class="form-select">
                                    <option value="">-- Select --</option>
                                    <option value="Male" <?= ($patient['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?= ($patient['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="Other" <?= ($patient['gender'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label fw-semibold">Blood Group</label>
                                <select name="blood_group" class="form-select">
                                    <option value="">-- Select --</option>
                                    <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bg): ?>
                                        <option value="<?= $bg; ?>" <?= ($patient['blood_group'] ?? '') == $bg ? 'selected' : ''; ?>><?= $bg; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label fw-semibold">National ID (NID)</label>
                                <input type="text" name="national_id" class="form-control" value="<?= htmlspecialchars($patient['national_id'] ?? ''); ?>" placeholder="e.g. 1995xxxxxxxx">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Emergency Contact Name</label>
                                <input type="text" name="emergency_contact_name" class="form-control" value="<?= htmlspecialchars($patient['emergency_contact_name'] ?? ''); ?>" placeholder="Relative/Contact Person Name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Emergency Contact Phone</label>
                                <input type="text" name="emergency_contact_phone" class="form-control" value="<?= htmlspecialchars($patient['emergency_contact_phone'] ?? ''); ?>" placeholder="017xxxxxxxx">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label fw-semibold">Full Address</label>
                                <textarea name="address" class="form-control" rows="2" placeholder="House/Road No, Area, District"><?= htmlspecialchars($patient['address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- একাউন্ট স্ট্যাটাস প্রদর্শন -->
                <?php if ($role != 'Patient'): ?>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Account Approval Status</label>
                        <div>
                            <?php if ($is_approved_status): ?>
                                <span class="badge bg-success fs-6"><i class="bi bi-check-circle me-1"></i> Active / Approved</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark fs-6"><i class="bi bi-clock-history me-1"></i> Pending Admin Approval</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                    <a href="dashboard.php" class="btn btn-secondary me-md-2">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send-check me-1"></i> Save Profile Credentials</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>