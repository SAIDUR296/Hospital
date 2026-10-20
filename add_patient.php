<?php
session_start();
require 'db_connect.php';

// ১. সিকিউরিটি চেক
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$role = $_SESSION['role'];
$session_user_id = $_SESSION['user_id'];
$session_full_name = $_SESSION['full_name'];

// অনুমতি চেক: Admin, Receptionist এবং Patient
if ($role != 'Admin' && $role != 'Receptionist' && $role != 'Patient') {
    header("Location: dashboard.php");
    exit();
}

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $patient_user_id = ($role == 'Patient') ? $session_user_id : NULL;
    $full_name = ($role == 'Patient') ? $session_full_name : trim($_POST['full_name']);
    
    $dob = $_POST['dob'];
    $gender = $_POST['gender'];
    
    // ফাঁকা ফিল্ডগুলোর জন্য NULL ভ্যালু সেট করা (যেন UNIQUE Key Error না আসে)
    $blood_group             = !empty($_POST['blood_group']) ? $_POST['blood_group'] : NULL;
    $national_id              = !empty(trim($_POST['national_id'])) ? trim($_POST['national_id']) : NULL;
    $phone                    = !empty(trim($_POST['phone'])) ? trim($_POST['phone']) : NULL;
    $address                  = !empty(trim($_POST['address'])) ? trim($_POST['address']) : NULL;
    $emergency_contact_name   = !empty(trim($_POST['emergency_contact_name'])) ? trim($_POST['emergency_contact_name']) : NULL;
    $emergency_contact_phone  = !empty(trim($_POST['emergency_contact_phone'])) ? trim($_POST['emergency_contact_phone']) : NULL;
    $medical_history          = !empty(trim($_POST['medical_history'])) ? trim($_POST['medical_history']) : NULL;
    $allergies                = !empty(trim($_POST['allergies'])) ? trim($_POST['allergies']) : NULL;

    try {
        $sql = "INSERT INTO patients (user_id, full_name, dob, gender, blood_group, national_id, phone, address, emergency_contact_name, emergency_contact_phone, medical_history, allergies) 
                VALUES (:user_id, :full_name, :dob, :gender, :blood_group, :national_id, :phone, :address, :emergency_contact_name, :emergency_contact_phone, :medical_history, :allergies)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':user_id'                 => $patient_user_id,
            ':full_name'               => $full_name,
            ':dob'                     => $dob,
            ':gender'                  => $gender,
            ':blood_group'             => $blood_group,
            ':national_id'              => $national_id,
            ':phone'                   => $phone,
            ':address'                 => $address,
            ':emergency_contact_name'  => $emergency_contact_name,
            ':emergency_contact_phone' => $emergency_contact_phone,
            ':medical_history'         => $medical_history,
            ':allergies'               => $allergies
        ]);

        $message = "<div class='alert alert-success alert-dismissible fade show' role='alert'>
                        <strong>Success!</strong> Patient profile saved successfully.
                        <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                    </div>";

    } catch (PDOException $e) {
        $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'>
                        <strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "
                        <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                    </div>";
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
            <div class="col-md-10">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-3">
                        <h4 class="mb-0"><i class="bi bi-person-plus"></i> <?= ($role == 'Patient') ? 'Complete Your Medical Profile' : 'New Patient Registration'; ?></h4>
                        <a href="dashboard.php" class="btn btn-light btn-sm text-primary fw-bold"><i class="bi bi-arrow-left"></i> Back</a>
                    </div>
                    <div class="card-body p-4">
                        <?= $message ?>
                        <form action="add_patient.php" method="POST">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                    <?php if ($role == 'Patient'): ?>
                                        <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($session_full_name); ?>" readonly>
                                        <small class="text-muted">Name is locked from your account profile.</small>
                                    <?php else: ?>
                                        <input type="text" name="full_name" class="form-control" required placeholder="Enter patient full name">
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Date of Birth (DOB) <span class="text-danger">*</span></label>
                                    <input type="date" name="dob" class="form-control" required>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Gender <span class="text-danger">*</span></label>
                                    <select name="gender" class="form-select" required>
                                        <option value="">Select Gender</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                                    <input type="text" name="phone" class="form-control" required placeholder="01XXXXXXXXX">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">National ID / NID</label>
                                    <input type="text" name="national_id" class="form-control" placeholder="NID number">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Blood Group</label>
                                    <select name="blood_group" class="form-select">
                                        <option value="">Select Blood Group</option>
                                        <option value="A+">A+</option>
                                        <option value="A-">A-</option>
                                        <option value="B+">B+</option>
                                        <option value="B-">B-</option>
                                        <option value="AB+">AB+</option>
                                        <option value="AB-">AB-</option>
                                        <option value="O+">O+</option>
                                        <option value="O-">O-</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Emergency Contact Name</label>
                                    <input type="text" name="emergency_contact_name" class="form-control" placeholder="Guardian or relative name">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Emergency Contact Phone</label>
                                    <input type="text" name="emergency_contact_phone" class="form-control" placeholder="Emergency phone number">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Medical History</label>
                                    <textarea name="medical_history" class="form-control" rows="2" placeholder="Previous diseases, surgeries, etc."></textarea>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Allergies</label>
                                    <textarea name="allergies" class="form-control" rows="2" placeholder="Drug or food allergies"></textarea>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label">Address</label>
                                <textarea name="address" class="form-control" rows="2" placeholder="Patient residential address"></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 py-2 fw-bold"><i class="bi bi-check-circle"></i> Save Patient Information</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>