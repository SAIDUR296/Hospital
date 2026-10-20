<?php
session_start();
require 'db_connect.php';

// ১. লগইন সিকিউরিটি চেক
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// 🚀 Nurse role block filter (Nurse emergency dispatch page-e dhukte parbe na)
if (isset($_SESSION['role']) && $_SESSION['role'] === 'Nurse') {
    header("Location: dashboard.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'Patient';
$full_name = $_SESSION['full_name'] ?? 'User';
$message   = "";
$message_type = "";

// ডাটাবেজ টেবিল ও missing কলাম অটো যুক্ত করার সেফ চেক
try {
    // ১. অ্যাম্বুলেন্স লিস্ট টেবিল
    $pdo->exec("CREATE TABLE IF NOT EXISTS ambulances (
        ambulance_id INT AUTO_INCREMENT PRIMARY KEY,
        vehicle_number VARCHAR(100) NOT NULL,
        driver_name VARCHAR(100) NOT NULL,
        driver_phone VARCHAR(20) NOT NULL,
        status VARCHAR(20) DEFAULT 'Available'
    ) ENGINE=InnoDB;");

    // ২. ডিসপ্যাচ টেবিল
    $pdo->exec("CREATE TABLE IF NOT EXISTS ambulance_dispatches (
        dispatch_id INT AUTO_INCREMENT PRIMARY KEY,
        ambulance_id INT UNSIGNED NULL,
        vehicle_number VARCHAR(100) NULL,
        vehicle_type VARCHAR(50) DEFAULT 'Basic Ambulance',
        caller_name VARCHAR(100) NOT NULL,
        caller_phone VARCHAR(30) NOT NULL,
        pickup_location TEXT NOT NULL,
        severity_level VARCHAR(50) DEFAULT 'Moderate Emergency',
        status VARCHAR(30) DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;");

    // 🛠️ পুরানো টেবিলে ambulance_id কলাম না থাকলে তা অটো যুক্ত করার সেফ রান
    $pdo->exec("ALTER TABLE ambulance_dispatches ADD COLUMN ambulance_id INT UNSIGNED NULL");
} catch (PDOException $e) {}

// ১০টি ডিফল্ট অ্যাম্বুলেন্স ডাটা অ্যাড করা (যদি ডাটা না থাকে)
try {
    $chk_amb_cnt = $pdo->query("SELECT COUNT(*) FROM ambulances")->fetchColumn();
    if ($chk_amb_cnt == 0) {
        $pdo->exec("INSERT INTO ambulances (vehicle_number, driver_name, driver_phone, status) VALUES
            ('DHAKA-METRO-CHA-11-0001', 'Rafiqul Islam', '01711000001', 'Available'),
            ('DHAKA-METRO-CHA-11-0002', 'Karim Ullah', '01711000002', 'Available'),
            ('DHAKA-METRO-CHA-11-0003', 'Abdur Rahim', '01811000003', 'Available'),
            ('DHAKA-METRO-CHA-11-0004', 'Jamal Hossain', '01911000004', 'Available'),
            ('DHAKA-METRO-CHA-11-0005', 'Sohel Rana', '01611000005', 'Available'),
            ('DHAKA-METRO-CHA-11-0006', 'Mizanur Rahman', '01511000006', 'Available'),
            ('DHAKA-METRO-CHA-11-0007', 'Babul Mia', '01311000007', 'Available'),
            ('DHAKA-METRO-CHA-11-0008', 'Alimuddin Sheikh', '01411000008', 'Available'),
            ('DHAKA-METRO-CHA-11-0009', 'Siddiqur Rahman', '01711000009', 'Available'),
            ('DHAKA-METRO-CHA-11-0010', 'Anwar Hossain', '01811000010', 'Available')
        ");
    }
} catch (PDOException $e) {}

// -------------------------------------------------------------
// ২. পোস্ট অ্যাকশন
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // (A) পেশেন্ট যখন অ্যাম্বুলেন্স রিকোয়েস্ট পাঠাবে
    if (isset($_POST['action_type']) && $_POST['action_type'] == 'patient_request') {
        $caller_name     = trim($_POST['caller_name']);
        $caller_phone    = trim($_POST['caller_phone']);
        $pickup_location = trim($_POST['pickup_location']);
        $severity_level  = trim($_POST['severity_level']);

        if (!empty($caller_phone) && !empty($pickup_location)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO ambulance_dispatches (caller_name, caller_phone, pickup_location, severity_level, status) VALUES (?, ?, ?, ?, 'Pending')");
                $stmt->execute([$caller_name, $caller_phone, $pickup_location, $severity_level]);

                $message = "🚨 Ambulance request submitted successfully! Receptionist will assign an ambulance shortly.";
                $message_type = "success";
            } catch (PDOException $e) {
                $message = "Database Error: " . $e->getMessage();
                $message_type = "danger";
            }
        } else {
            $message = "Please fill in all required fields!";
            $message_type = "warning";
        }
    }

    // (B) রিসেপশনিস্ট/অ্যাডমিন যখন ড্রপডাউন থেকে অ্যাম্বুলেন্স সিলেক্ট করে ডিসপ্যাচ করবে
    if (isset($_POST['action_type']) && $_POST['action_type'] == 'assign_and_dispatch') {
        $dispatch_id = intval($_POST['dispatch_id']);
        $amb_id      = intval($_POST['selected_ambulance_id'] ?? 0);

        if ($amb_id > 0) {
            try {
                // সেলেক্ট করা অ্যাম্বুলেন্সের ডাটা আনা
                $stmt_a = $pdo->prepare("SELECT * FROM ambulances WHERE ambulance_id = ?");
                $stmt_a->execute([$amb_id]);
                $amb_data = $stmt_a->fetch(PDO::FETCH_ASSOC);

                if ($amb_data) {
                    $v_info = $amb_data['vehicle_number'] . " (" . $amb_data['driver_name'] . " - " . $amb_data['driver_phone'] . ")";

                    // ১. ডিসপ্যাচ টেবিল আপডেট করা
                    $stmt = $pdo->prepare("UPDATE ambulance_dispatches SET ambulance_id = ?, vehicle_number = ?, vehicle_type = 'Basic Ambulance', status = 'Dispatched' WHERE dispatch_id = ?");
                    $stmt->execute([$amb_id, $v_info, $dispatch_id]);

                    // ২. ওই অ্যাম্বুলেন্সের স্ট্যাটাস On Duty করা
                    $pdo->prepare("UPDATE ambulances SET status = 'On Duty' WHERE ambulance_id = ?")->execute([$amb_id]);

                    $message = "Ambulance #AMB-$dispatch_id assigned ($v_info) and dispatched!";
                    $message_type = "success";
                }
            } catch (PDOException $e) {
                $message = "Database Error: " . $e->getMessage();
                $message_type = "danger";
            }
        } else {
            $message = "Please select an available ambulance from the list!";
            $message_type = "warning";
        }
    }

    // (C) ১০টি অ্যাম্বুলেন্সের ড্রাইভার ও গাড়ির তথ্য আপডেট করার লজিক
    if (in_array($role, ['Admin', 'Receptionist']) && isset($_POST['action_type']) && $_POST['action_type'] == 'update_ambulance_info') {
        $amb_id   = intval($_POST['ambulance_id']);
        $v_no     = trim($_POST['vehicle_number']);
        $d_name   = trim($_POST['driver_name']);
        $d_phone  = trim($_POST['driver_phone']);
        $a_status = $_POST['status'];

        try {
            $stmt_u = $pdo->prepare("UPDATE ambulances SET vehicle_number = ?, driver_name = ?, driver_phone = ?, status = ? WHERE ambulance_id = ?");
            $stmt_u->execute([$v_no, $d_name, $d_phone, $a_status, $amb_id]);

            $message = "Ambulance & Driver info updated successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Error updating ambulance: " . $e->getMessage();
            $message_type = "danger";
        }
    }

    // (D) ট্রিপ শেষ হলে অ্যাম্বুলেন্স ফ্রী (Available) করা
    if (in_array($role, ['Admin', 'Receptionist']) && isset($_POST['action_type']) && $_POST['action_type'] == 'complete_trip') {
        $disp_id = intval($_POST['dispatch_id']);
        $amb_id  = intval($_POST['ambulance_id']);

        try {
            $pdo->prepare("UPDATE ambulance_dispatches SET status = 'Completed' WHERE dispatch_id = ?")->execute([$disp_id]);
            if ($amb_id > 0) {
                $pdo->prepare("UPDATE ambulances SET status = 'Available' WHERE ambulance_id = ?")->execute([$amb_id]);
            }
            $message = "Trip marked as Completed & Ambulance is now Available again!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// -------------------------------------------------------------
// ৩. ডাটা ফেচ করা
// -------------------------------------------------------------
$pending_requests     = [];
$all_logs             = [];
$all_ambulances       = [];
$available_ambulances = [];

try {
    // ১০টি অ্যাম্বুলেন্স লিস্ট (ম্যানেজমেন্টের জন্য)
    $stmt_amb_all = $pdo->query("SELECT * FROM ambulances ORDER BY ambulance_id ASC");
    if ($stmt_amb_all) $all_ambulances = $stmt_amb_all->fetchAll(PDO::FETCH_ASSOC);

    // ফাঁকা (Available) অ্যাম্বুলেন্স লিস্ট (ড্রপডাউনের জন্য)
    $stmt_amb_avail = $pdo->query("SELECT * FROM ambulances WHERE status = 'Available' ORDER BY ambulance_id ASC");
    if ($stmt_amb_avail) $available_ambulances = $stmt_amb_avail->fetchAll(PDO::FETCH_ASSOC);

    if ($role == 'Patient') {
        // পেশেন্ট শুধু তার নিজের পাঠানো রিকোয়েস্টের স্ট্যাটাস দেখবে
        $stmt = $pdo->prepare("SELECT * FROM ambulance_dispatches WHERE caller_name = ? OR caller_phone = ? ORDER BY dispatch_id DESC");
        $stmt->execute([$full_name, $_SESSION['phone_number'] ?? '']);
        $all_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // রিসেপশনিস্ট/অ্যাডমিন পেন্ডিং রিকোয়েস্ট ও লবস দেখবে
        $stmt_p = $pdo->query("SELECT * FROM ambulance_dispatches WHERE status = 'Pending' ORDER BY dispatch_id DESC");
        if ($stmt_p) $pending_requests = $stmt_p->fetchAll(PDO::FETCH_ASSOC);

        $stmt_l = $pdo->query("SELECT * FROM ambulance_dispatches ORDER BY dispatch_id DESC");
        if ($stmt_l) $all_logs = $stmt_l->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emergency Ambulance - Hospital ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f7f6; margin: 0; }
        .sidebar { min-height: 100vh; background-color: #1e293b; color: white; width: 260px; flex-shrink: 0; }
        .sidebar a { color: #94a3b8; text-decoration: none; padding: 10px 15px; display: block; border-radius: 6px; margin-bottom: 4px; font-weight: 500; }
        .sidebar a:hover, .sidebar a.active { background-color: #334155; color: #ffffff; }
        .sidebar i { margin-right: 10px; }
    </style>
</head>
<body>

<div class="d-flex">
    <!-- সাইডবার -->
    <div class="sidebar p-3">
        <h4 class="text-center text-white mb-4"><i class="bi bi-hospital"></i> Hospital ERP</h4>
        <hr class="text-secondary">
        <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="emergency_dispatch.php" class="active"><i class="bi bi-truck text-danger"></i> Emergency Dispatch</a>
        <hr class="text-secondary mt-4">
        <a href="logout.php" class="text-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>

    <!-- মূল কন্টেন্ট -->
    <div class="w-100">
        <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm px-4 py-3 mb-4">
            <div class="container-fluid">
                <span class="navbar-brand mb-0 h1 fw-bold text-danger"><i class="bi bi-truck me-2"></i>Emergency Dispatch & Ambulance Management</span>
                <span class="text-muted">Welcome, <strong><?= htmlspecialchars($full_name); ?></strong> (<?= htmlspecialchars($role); ?>)</span>
            </div>
        </nav>

        <div class="container-fluid px-4">

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $message_type; ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- ======================================================= -->
            <!-- 🚑 SECTION 1: PATIENT AMBULANCE REQUEST FORM (পেশেন্ট ভিউ) -->
            <!-- ======================================================= -->
            <?php if ($role == 'Patient'): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-danger text-white py-3">
                        <h5 class="mb-0 fw-bold"><i class="bi bi-telephone-outbound me-2"></i>Request Emergency Ambulance</h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST">
                            <input type="hidden" name="action_type" value="patient_request">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Caller / Patient Name *</label>
                                    <input type="text" name="caller_name" class="form-control" value="<?= htmlspecialchars($full_name); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Contact Phone Number *</label>
                                    <input type="text" name="caller_phone" class="form-control" placeholder="017xxxxxxxx" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Emergency Severity Level</label>
                                    <select name="severity_level" class="form-select">
                                        <option value="Moderate Emergency">Moderate Emergency</option>
                                        <option value="High Emergency">High Emergency</option>
                                        <option value="Critical (ICU Needed)">Critical (ICU Needed)</option>
                                    </select>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label fw-semibold">Pickup Location Address *</label>
                                    <input type="text" name="pickup_location" class="form-control" placeholder="House No, Road No, Area details where ambulance should go..." required>
                                </div>
                                <div class="col-md-12 text-end mt-3">
                                    <button type="submit" class="btn btn-danger btn-lg fw-bold px-4">
                                        <i class="bi bi-send-fill me-2"></i>Send Ambulance Request To Reception
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ======================================================= -->
            <!-- 📞 SECTION 2: RECEPTIONIST INCOMING REQUESTS (রিসেপশনিস্ট ভিউ) -->
            <!-- ======================================================= -->
            <?php if (in_array($role, ['Admin', 'Receptionist', 'Nurse'])): ?>
                
                <!-- ইনকামিং কল/পেন্ডিং রিকোয়েস্ট টেবিল -->
                <div class="card border-0 shadow-sm mb-4 border-start border-4 border-danger">
                    <div class="card-header bg-danger bg-opacity-10 d-flex justify-content-between align-items-center py-3">
                        <h5 class="mb-0 fw-bold text-danger">
                            <i class="bi bi-bell-fill me-2"></i>Incoming Patient Ambulance Calls & Requests (Pending)
                        </h5>
                        <span class="badge bg-danger fs-6"><?= count($pending_requests); ?> Pending</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Req #</th>
                                        <th>Caller / Patient</th>
                                        <th>Phone</th>
                                        <th>Pickup Location</th>
                                        <th>Severity</th>
                                        <th style="min-width: 350px;">Assign Available Ambulance & Dispatch</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($pending_requests)): ?>
                                        <?php foreach ($pending_requests as $req): ?>
                                            <tr>
                                                <td><span class="badge bg-dark">#AMB-<?= $req['dispatch_id']; ?></span></td>
                                                <td><strong><?= htmlspecialchars($req['caller_name']); ?></strong></td>
                                                <td class="text-primary fw-bold"><?= htmlspecialchars($req['caller_phone']); ?></td>
                                                <td><i class="bi bi-geo-alt-fill text-danger me-1"></i><?= htmlspecialchars($req['pickup_location']); ?></td>
                                                <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($req['severity_level']); ?></span></td>
                                                <td>
                                                    <!-- ড্রপডাউন থেকে ফাঁকা অ্যাম্বুলেন্স সিলেক্ট করে পাঠাবার ফর্ম -->
                                                    <form method="POST" class="d-flex gap-2">
                                                        <input type="hidden" name="action_type" value="assign_and_dispatch">
                                                        <input type="hidden" name="dispatch_id" value="<?= $req['dispatch_id']; ?>">
                                                        
                                                        <select name="selected_ambulance_id" class="form-select form-select-sm border-danger fw-semibold" required>
                                                            <option value="">-- Select Available Ambulance --</option>
                                                            <?php if (!empty($available_ambulances)): ?>
                                                                <?php foreach ($available_ambulances as $amb): ?>
                                                                    <option value="<?= $amb['ambulance_id']; ?>">
                                                                        🚑 <?= htmlspecialchars($amb['vehicle_number']); ?> (<?= htmlspecialchars($amb['driver_name']); ?> - <?= htmlspecialchars($amb['driver_phone']); ?>)
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            <?php else: ?>
                                                                <option value="" disabled>❌ No Available Ambulances!</option>
                                                            <?php endif; ?>
                                                        </select>

                                                        <button type="submit" class="btn btn-sm btn-danger fw-bold text-nowrap" <?= empty($available_ambulances) ? 'disabled' : ''; ?>>
                                                            <i class="bi bi-send-fill me-1"></i> Dispatch
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">No pending patient ambulance requests at the moment.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ======================================================= -->
                <!-- SECTION 3: AMBULANCE FLEET & DRIVER SETTINGS (১০টি গাড়ি প্যানেল) -->
                <!-- ======================================================= -->
                <div class="card border-0 shadow-sm mb-4 border-start border-4 border-primary">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                        <h5 class="mb-0 text-primary fw-bold"><i class="bi bi-gear-fill me-2"></i>Hospital Ambulance Fleet & Driver Settings (10 Units)</h5>
                        <span class="badge bg-primary"><?= count($all_ambulances); ?> Total Units</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 50px;">SL#</th>
                                        <th>Vehicle Registration No</th>
                                        <th>Driver Name</th>
                                        <th>Driver Phone Number</th>
                                        <th>Status</th>
                                        <th class="text-center" style="width: 140px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($all_ambulances)): foreach ($all_ambulances as $idx => $amb): ?>
                                        <tr>
                                            <form method="POST">
                                                <input type="hidden" name="action_type" value="update_ambulance_info">
                                                <input type="hidden" name="ambulance_id" value="<?= $amb['ambulance_id']; ?>">
                                                
                                                <td class="fw-bold text-center"><?= $idx + 1; ?></td>
                                                <td>
                                                    <input type="text" name="vehicle_number" class="form-control form-control-sm fw-bold text-primary" value="<?= htmlspecialchars($amb['vehicle_number']); ?>" required>
                                                </td>
                                                <td>
                                                    <input type="text" name="driver_name" class="form-control form-control-sm" value="<?= htmlspecialchars($amb['driver_name']); ?>" required>
                                                </td>
                                                <td>
                                                    <input type="text" name="driver_phone" class="form-control form-control-sm" value="<?= htmlspecialchars($amb['driver_phone']); ?>" required>
                                                </td>
                                                <td>
                                                    <select name="status" class="form-select form-select-sm fw-bold">
                                                        <option value="Available" <?= ($amb['status'] == 'Available') ? 'selected' : ''; ?>>🟢 Available</option>
                                                        <option value="On Duty" <?= ($amb['status'] == 'On Duty') ? 'selected' : ''; ?>>🔴 On Duty</option>
                                                        <option value="Maintenance" <?= ($amb['status'] == 'Maintenance') ? 'selected' : ''; ?>>🟡 Maintenance</option>
                                                    </select>
                                                </td>
                                                <td class="text-center">
                                                    <button type="submit" class="btn btn-sm btn-primary fw-bold px-3">
                                                        <i class="bi bi-save me-1"></i> Save
                                                    </button>
                                                </td>
                                            </form>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

            <!-- ======================================================= -->
            <!-- 📋 SECTION 4: DISPATCH LOGS (সকল হিস্ট্রি) -->
            <!-- ======================================================= -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-journal-text me-2"></i>Emergency Ambulance Dispatch Logs</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Req #</th>
                                    <th>Vehicle & Driver Info</th>
                                    <th>Caller Info</th>
                                    <th>Pickup Location</th>
                                    <th>Severity</th>
                                    <th>Status</th>
                                    <?php if (in_array($role, ['Admin', 'Receptionist', 'Nurse'])): ?>
                                        <th class="text-center">Action</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($all_logs)): ?>
                                    <?php foreach ($all_logs as $log): ?>
                                        <tr>
                                            <td><strong>#AMB-<?= $log['dispatch_id']; ?></strong></td>
                                            <td>
                                                <?php if (!empty($log['vehicle_number'])): ?>
                                                    <strong><i class="bi bi-truck text-danger me-1"></i><?= htmlspecialchars($log['vehicle_number']); ?></strong>
                                                <?php else: ?>
                                                    <span class="text-muted">Not Assigned Yet</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($log['caller_name']); ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($log['caller_phone']); ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($log['pickup_location']); ?></td>
                                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($log['severity_level']); ?></span></td>
                                            <td>
                                                <?php if ($log['status'] == 'Pending'): ?>
                                                    <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i> Pending Call</span>
                                                <?php elseif ($log['status'] == 'Dispatched'): ?>
                                                    <span class="badge bg-danger"><i class="bi bi-truck me-1"></i> Dispatched / On the Way</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Completed</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php if (in_array($role, ['Admin', 'Receptionist', 'Nurse'])): ?>
                                                <td class="text-center">
                                                    <?php if ($log['status'] == 'Dispatched'): ?>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="action_type" value="complete_trip">
                                                            <input type="hidden" name="dispatch_id" value="<?= $log['dispatch_id']; ?>">
                                                            <input type="hidden" name="ambulance_id" value="<?= $log['ambulance_id'] ?? 0; ?>">
                                                            <button type="submit" class="btn btn-sm btn-success fw-bold">
                                                                <i class="bi bi-check2-all me-1"></i> Free Ambulance
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-muted small">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">No emergency ambulance requests found.</td>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.js"></script>
</body>
</html>