<?php
session_start();
require 'db_connect.php';

// ১. সিকিউরিটি চেক: লগইন করা না থাকলে রিডাইরেক্ট করবে
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'Patient';
$full_name = $_SESSION['full_name'] ?? 'User';

// ২. রোল চেক: সকল স্টাফ রোল এক্সেস পাবে
$allowed_roles = ['Admin', 'Receptionist', 'Doctor', 'Nurse', 'Lab Technician', 'Pharmacist', 'Accountant'];
if (!in_array($role, $allowed_roles)) {
    header("Location: dashboard.php");
    exit();
}

$success = "";
$error   = "";

// -------------------------------------------------------------
// ৩. পেশেন্ট আপডেট অ্যাকশন প্রসেসিং (UPDATE HANDLER)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_patient') {
    if (in_array($role, ['Admin', 'Receptionist'])) {
        $p_id       = intval($_POST['patient_id']);
        $p_name     = trim($_POST['full_name']);
        $p_phone    = trim($_POST['phone']);
        $p_age      = trim($_POST['age'] ?? '');
        $p_gender   = $_POST['gender'] ?? 'Male';
        $p_blood    = $_POST['blood_group'] ?? '';
        $p_nid      = trim($_POST['national_id'] ?? '');
        $p_address  = trim($_POST['address'] ?? '');
        $p_em_name  = trim($_POST['emergency_contact_name'] ?? '');
        $p_em_phone = trim($_POST['emergency_contact_phone'] ?? '');

        if (!empty($p_name) && !empty($p_phone) && $p_id > 0) {
            try {
                // Patients টেবিল আপডেট
                $stmt_upd = $pdo->prepare("UPDATE patients SET 
                                            full_name = ?, 
                                            phone = ?, 
                                            age = ?, 
                                            gender = ?, 
                                            blood_group = ?, 
                                            national_id = ?, 
                                            address = ?, 
                                            emergency_contact_name = ?, 
                                            emergency_contact_phone = ? 
                                           WHERE patient_id = ?");
                $stmt_upd->execute([$p_name, $p_phone, $p_age, $p_gender, $p_blood, $p_nid, $p_address, $p_em_name, $p_em_phone, $p_id]);

                // Linked `users` টেবিলেও অটো-সিঙ্ক
                $stmt_get_u = $pdo->prepare("SELECT user_id FROM patients WHERE patient_id = ?");
                $stmt_get_u->execute([$p_id]);
                $linked_user = $stmt_get_u->fetch(PDO::FETCH_ASSOC);

                if ($linked_user && !empty($linked_user['user_id'])) {
                    $stmt_u_upd = $pdo->prepare("UPDATE users SET full_name = ?, phone_number = ? WHERE user_id = ?");
                    $stmt_u_upd->execute([$p_name, $p_phone, $linked_user['user_id']]);
                }

                $success = "Patient #PAT-$p_id information updated successfully!";
            } catch (PDOException $e) {
                $error = "Update Error: " . $e->getMessage();
            }
        } else {
            $error = "Full Name and Phone Number are required!";
        }
    } else {
        $error = "You do not have permission to edit patient records!";
    }
}

// -------------------------------------------------------------
// ৪. সার্চ ও ফিল্টারিং ডাটা ফেচিং
// -------------------------------------------------------------
$search      = trim($_GET['search'] ?? '');
$blood_group = $_GET['blood_group'] ?? '';

try {
    $sql = "SELECT p.*, cb.bed_number, cb.cabin_type 
            FROM patients p 
            LEFT JOIN cabins_beds cb ON p.patient_id = cb.patient_id 
            WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (p.full_name LIKE :s1 OR p.phone LIKE :s2 OR p.national_id LIKE :s3 OR p.patient_id = :exact_id)";
        $params[':s1']       = "%$search%";
        $params[':s2']       = "%$search%";
        $params[':s3']       = "%$search%";
        $params[':exact_id'] = is_numeric($search) ? intval($search) : 0;
    }

    if (!empty($blood_group)) {
        $sql .= " AND p.blood_group = :blood_group";
        $params[':blood_group'] = $blood_group;
    }

    $sql .= " ORDER BY p.patient_id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Error fetching data: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Directory - Smart Hospital ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { min-height: 100vh; background-color: #1e293b; color: white; width: 260px; flex-shrink: 0; }
        .sidebar a { color: #94a3b8; text-decoration: none; padding: 10px 15px; display: block; border-radius: 6px; margin-bottom: 4px; font-weight: 500; }
        .sidebar a:hover, .sidebar a.active { background-color: #334155; color: #ffffff; }
        .sidebar i { margin-right: 10px; }
        .nav-category { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 1px; color: #64748b; margin-top: 18px; margin-bottom: 6px; padding-left: 15px; font-weight: 700; }
    </style>
</head>
<body>

<div class="d-flex">
    <!-- Dynamic Sidebar -->
    <div class="sidebar p-3">
        <h4 class="text-center text-white mb-4"><i class="bi bi-hospital"></i> Hospital ERP</h4>
        <hr class="text-secondary">
        <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        
        <?php if ($role == 'Accountant'): ?>
            <div class="nav-category">Accounts & Finance</div>
            <a href="billing.php"><i class="bi bi-receipt"></i> Billing & Invoices</a>
            <a href="finance.php"><i class="bi bi-cash-coin"></i> Payroll & Finance</a>
            <div class="nav-category">Patient Management</div>
            <a href="patient_list.php" class="active"><i class="bi bi-people"></i> Patient Directory</a>
        <?php elseif ($role == 'Lab Technician'): ?>
            <div class="nav-category">Lab Station</div>
            <a href="modules.php"><i class="bi bi-eyedropper text-danger"></i> Modules</a>
            <a href="patient_list.php" class="active"><i class="bi bi-people"></i> Patient Directory</a>
            <a href="edit_profile.php"><i class="bi bi-person-badge"></i> Edit My Profile</a>
        <?php elseif ($role == 'Nurse'): ?>
            <div class="nav-category">Nurse Station</div>
            <a href="cabins.php"><i class="bi bi-door-open-fill"></i> Cabin & Bed Status</a>
            <a href="patient_list.php" class="active"><i class="bi bi-person-lines-fill"></i> Patient Directory</a>
            <a href="appointments.php"><i class="bi bi-journal-medical"></i> Doctor Visit Schedule</a>
        <?php else: ?>
            <div class="nav-category">Patient & Frontdesk</div>
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
        <!-- Navbar -->
        <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm px-4 py-3 mb-4">
            <div class="container-fluid">
                <span class="navbar-brand mb-0 h1">Patient Directory</span>
                <div class="d-flex align-items-center">
                    <span class="me-3 text-muted">Welcome, <strong><?= htmlspecialchars($full_name); ?></strong> (<?= htmlspecialchars($role); ?>)</span>
                    <a href="logout.php" class="btn btn-sm btn-outline-danger">Logout</a>
                </div>
            </div>
        </nav>

        <div class="container-fluid px-4">
            <?php if (!empty($success)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Search & Filter Section -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body p-3">
                    <form method="GET" action="patient_list.php" class="row g-2 align-items-center">
                        <div class="col-md-5">
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                                <input type="text" name="search" class="form-control" placeholder="Search by Name, Phone, ID, or NID..." value="<?= htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <select name="blood_group" class="form-select">
                                <option value="">-- All Blood Groups --</option>
                                <?php 
                                $groups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
                                foreach($groups as $bg) {
                                    $selected = ($blood_group == $bg) ? 'selected' : '';
                                    echo "<option value='$bg' $selected>$bg</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-filter"></i> Filter</button>
                            <a href="patient_list.php" class="btn btn-outline-secondary" title="Reset"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
                            
                            <?php if (in_array($role, ['Admin', 'Receptionist'])): ?>
                                <a href="patient_registration.php" class="btn btn-success ms-auto"><i class="bi bi-person-plus"></i> Add New</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Patient List Table -->
            <div class="card shadow-sm border-0">
                <div class="card-header p-3">
                    <h5 class="mb-0 text-primary fw-bold"><i class="bi bi-people-fill me-2"></i>Registered Patients List</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Patient ID</th>
                                    <th>Patient Details</th>
                                    <th>Phone</th>
                                    <th>Blood Group</th>
                                    <th>Admitted Bed</th>
                                    <th>Emergency Contact</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($patients)): ?>
                                    <?php foreach ($patients as $row): ?>
                                        <tr>
                                            <td><span class="badge bg-secondary">#PAT-<?= htmlspecialchars($row['patient_id']); ?></span></td>
                                            <td>
                                                <strong><?= htmlspecialchars($row['full_name']); ?></strong><br>
                                                <small class="text-muted">Age: <?= htmlspecialchars($row['age'] ?? 'N/A'); ?> | <?= htmlspecialchars($row['gender'] ?? 'N/A'); ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($row['phone']); ?></td>
                                            <td>
                                                <?php if(!empty($row['blood_group'])): ?>
                                                    <span class="badge bg-danger"><?= htmlspecialchars($row['blood_group']); ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-dark">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($row['bed_number'])): ?>
                                                    <span class="badge bg-info text-dark"><i class="bi bi-door-open"></i> <?= htmlspecialchars($row['bed_number']); ?></span><br>
                                                    <small class="text-muted"><?= htmlspecialchars($row['cabin_type']); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted small">Not Admitted</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($row['emergency_contact_name'] ?? 'N/A'); ?><br>
                                                <small class="text-muted"><?= htmlspecialchars($row['emergency_contact_phone'] ?? ''); ?></small>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm">
                                                    <!-- View Profile Button -->
                                                    <a href="view_patient.php?id=<?= $row['patient_id']; ?>" class="btn btn-outline-info text-dark" title="View Profile"><i class="bi bi-eye"></i></a>
                                                    
                                                    <!-- Accountant Direct Billing & Services Modal Button -->
                                                    <?php if (in_array($role, ['Accountant', 'Admin'])): ?>
                                                        <button type="button" class="btn btn-sm btn-success fw-bold" data-bs-toggle="modal" data-bs-target="#servicesModal<?= $row['patient_id']; ?>" title="View Services & Bill">
                                                            <i class="bi bi-calculator me-1"></i> View Services & Bill
                                                        </button>
                                                    <?php endif; ?>

                                                    <!-- Admin & Receptionist Edit Trigger -->
                                                    <?php if (in_array($role, ['Admin', 'Receptionist'])): ?>
                                                        <button type="button" class="btn btn-outline-warning text-dark" data-bs-toggle="modal" data-bs-target="#editPatientModal<?= $row['patient_id']; ?>" title="Edit Patient Info">
                                                            <i class="bi bi-pencil-square"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>

                                                <!-- 🆕 VIEW SERVICES & UNBILLED ITEMS MODAL -->
                                                <?php if (in_array($role, ['Accountant', 'Admin'])): ?>
                                                    <div class="modal fade text-start" id="servicesModal<?= $row['patient_id']; ?>" tabindex="-1" aria-hidden="true">
                                                        <div class="modal-dialog modal-lg modal-dialog-centered">
                                                            <div class="modal-content">
                                                                <div class="modal-header bg-success text-white">
                                                                    <h5 class="modal-title fw-bold">
                                                                        <i class="bi bi-calculator me-2"></i>Patient Services & Charges - <?= htmlspecialchars($row['full_name']); ?>
                                                                    </h5>
                                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                </div>
                                                                <div class="modal-body p-4">
                                                                    <?php
                                                                    $p_id = $row['patient_id'];
                                                                    $total_pending_bill = 0.00;
                                                                    $unbilled_items = [];

                                                                    // 1. Doctor Appointment Charges (Unpaid or Due)
                                                                    try {
                                                                        $stmt_apt = $pdo->prepare("SELECT * FROM appointments WHERE patient_id = ? AND (payment_status = 'Unpaid' OR payment_status = 'Due')");
                                                                        $stmt_apt->execute([$p_id]);
                                                                        while ($apt = $stmt_apt->fetch(PDO::FETCH_ASSOC)) {
                                                                            $fee = floatval($apt['consultation_fee'] ?? 500.00);
                                                                            $unbilled_items[] = [
                                                                                'category' => 'Doctor Consultation',
                                                                                'service'  => 'Appointment Visit #' . $apt['appointment_id'] . ' (' . ($apt['consultation_type'] ?? 'Offline') . ')',
                                                                                'amount'   => $fee,
                                                                                'status'   => $apt['payment_status'] ?? 'Unpaid'
                                                                            ];
                                                                            $total_pending_bill += $fee;
                                                                        }
                                                                    } catch (PDOException $e) {}

                                                                    // 2. Lab Test Charges (Laboratory Tests)
                                                                    try {
                                                                        $stmt_lab = $pdo->prepare("SELECT * FROM laboratory_tests WHERE patient_id = ? AND (payment_status = 'Unpaid' OR payment_status = 'Due' OR payment_status IS NULL)");
                                                                        $stmt_lab->execute([$p_id]);
                                                                        while ($lab = $stmt_lab->fetch(PDO::FETCH_ASSOC)) {
                                                                            $cost = floatval($lab['cost'] ?? 0.00);
                                                                            $unbilled_items[] = [
                                                                                'category' => 'Lab Test',
                                                                                'service'  => ($lab['test_name'] ?? 'Pathology Test Charge') . ' (' . ($lab['sample_type'] ?? 'Hospital Visit') . ')',
                                                                                'amount'   => $cost,
                                                                                'status'   => $lab['payment_status'] ?? 'Unpaid'
                                                                            ];
                                                                            $total_pending_bill += $cost;
                                                                        }
                                                                    } catch (PDOException $e) {}

                                                                    // 3. Admitted Cabin/Bed Charge
                                                                    if (!empty($row['bed_number'])) {
                                                                        $bed_charge = 1000.00;
                                                                        $unbilled_items[] = [
                                                                            'category' => 'Cabin / Bed Charge',
                                                                            'service'  => 'Bed ' . $row['bed_number'] . ' (' . ($row['cabin_type'] ?? 'General') . ')',
                                                                            'amount'   => $bed_charge,
                                                                            'status'   => 'Due'
                                                                        ];
                                                                        $total_pending_bill += $bed_charge;
                                                                    }

                                                                    // 4. Fetch Invoices History for Patient
                                                                    $patient_invoices = [];
                                                                    try {
                                                                        $stmt_inv_hist = $pdo->prepare("SELECT * FROM billing_invoices WHERE patient_id = ? ORDER BY invoice_id DESC");
                                                                        $stmt_inv_hist->execute([$p_id]);
                                                                        $patient_invoices = $stmt_inv_hist->fetchAll(PDO::FETCH_ASSOC);
                                                                    } catch (PDOException $e) {}
                                                                    ?>

                                                                    <h6 class="fw-bold text-danger mb-2"><i class="bi bi-exclamation-circle me-1"></i> Unbilled / Pending Charges</h6>
                                                                    <div class="table-responsive mb-4">
                                                                        <table class="table table-bordered align-middle mb-0">
                                                                            <thead class="table-light">
                                                                                <tr>
                                                                                    <th>Category</th>
                                                                                    <th>Service Description</th>
                                                                                    <th>Status</th>
                                                                                    <th class="text-end">Amount (BDT)</th>
                                                                                </tr>
                                                                            </thead>
                                                                            <tbody>
                                                                                <?php if (!empty($unbilled_items)): foreach ($unbilled_items as $item): ?>
                                                                                    <tr>
                                                                                        <td><span class="badge bg-info text-dark"><?= htmlspecialchars($item['category']); ?></span></td>
                                                                                        <td><strong><?= htmlspecialchars($item['service']); ?></strong></td>
                                                                                        <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($item['status']); ?></span></td>
                                                                                        <td class="text-end fw-bold text-danger">৳<?= number_format($item['amount'], 2); ?></td>
                                                                                    </tr>
                                                                                <?php endforeach; else: ?>
                                                                                    <tr>
                                                                                        <td colspan="4" class="text-center text-muted py-3">No pending or unbilled services found for this patient.</td>
                                                                                    </tr>
                                                                                <?php endif; ?>
                                                                            </tbody>
                                                                            <tfoot class="table-light">
                                                                                <tr>
                                                                                    <th colspan="3" class="text-end fw-bold">Total Due / Pending Amount:</th>
                                                                                    <th class="text-end text-danger fw-bold fs-5">৳<?= number_format($total_pending_bill, 2); ?></th>
                                                                                </tr>
                                                                            </tfoot>
                                                                        </table>
                                                                    </div>

                                                                    <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-receipt me-1"></i> Generated Invoices History</h6>
                                                                    <div class="table-responsive">
                                                                        <table class="table table-bordered align-middle mb-0">
                                                                            <thead class="table-light">
                                                                                <tr>
                                                                                    <th>Inv #</th>
                                                                                    <th>Services</th>
                                                                                    <th>Net Payable</th>
                                                                                    <th>Status</th>
                                                                                </tr>
                                                                            </thead>
                                                                            <tbody>
                                                                                <?php if (!empty($patient_invoices)): foreach ($patient_invoices as $p_inv): ?>
                                                                                    <tr>
                                                                                        <td><strong>#INV-<?= $p_inv['invoice_id']; ?></strong></td>
                                                                                        <td>
                                                                                            <?php
                                                                                            $items = json_decode($p_inv['itemized_services'], true);
                                                                                            if (is_array($items)) {
                                                                                                foreach ($items as $it) {
                                                                                                    echo "• " . htmlspecialchars($it['service'] ?? '') . "<br>";
                                                                                                }
                                                                                            } else {
                                                                                                echo htmlspecialchars($p_inv['itemized_services'] ?? 'General Service');
                                                                                            }
                                                                                            ?>
                                                                                        </td>
                                                                                        <td class="fw-bold text-success">৳<?= number_format($p_inv['net_payable'], 2); ?></td>
                                                                                        <td>
                                                                                            <?php if ($p_inv['payment_status'] == 'Paid'): ?>
                                                                                                <span class="badge bg-success">Paid</span>
                                                                                            <?php else: ?>
                                                                                                <span class="badge bg-warning text-dark">Due</span>
                                                                                            <?php endif; ?>
                                                                                        </td>
                                                                                    </tr>
                                                                                <?php endforeach; else: ?>
                                                                                    <tr><td colspan="4" class="text-center text-muted py-2">No generated invoices found.</td></tr>
                                                                                <?php endif; ?>
                                                                            </tbody>
                                                                        </table>
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer bg-light">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                                    <?php if ($total_pending_bill > 0): ?>
                                                                        <a href="billing.php?patient_id=<?= $p_id; ?>&auto_amount=<?= $total_pending_bill; ?>" class="btn btn-success fw-bold">
                                                                            <i class="bi bi-receipt me-1"></i> Proceed to Create Bill (৳<?= number_format($total_pending_bill, 2); ?>)
                                                                        </a>
                                                                    <?php else: ?>
                                                                        <a href="billing.php?patient_id=<?= $p_id; ?>" class="btn btn-outline-success fw-bold">
                                                                            <i class="bi bi-receipt me-1"></i> Open Custom Bill
                                                                        </a>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>

                                                <!-- UPDATE PATIENT MODAL (Admin & Receptionist Only) -->
                                                <?php if (in_array($role, ['Admin', 'Receptionist'])): ?>
                                                    <div class="modal fade text-start" id="editPatientModal<?= $row['patient_id']; ?>" tabindex="-1" aria-hidden="true">
                                                        <div class="modal-dialog modal-lg">
                                                            <div class="modal-content">
                                                                <form method="POST" action="patient_list.php">
                                                                    <input type="hidden" name="action" value="update_patient">
                                                                    <input type="hidden" name="patient_id" value="<?= $row['patient_id']; ?>">
                                                                    
                                                                    <div class="modal-header bg-primary text-white">
                                                                        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Update Patient #PAT-<?= $row['patient_id']; ?></h5>
                                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                    </div>
                                                                    
                                                                    <div class="modal-body">
                                                                        <div class="row g-3">
                                                                            <div class="col-md-6">
                                                                                <label class="form-label fw-bold">Full Name *</label>
                                                                                <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($row['full_name']); ?>" required>
                                                                            </div>
                                                                            <div class="col-md-6">
                                                                                <label class="form-label fw-bold">Phone Number *</label>
                                                                                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($row['phone']); ?>" required>
                                                                            </div>
                                                                            <div class="col-md-4">
                                                                                <label class="form-label fw-bold">Age</label>
                                                                                <input type="text" name="age" class="form-control" value="<?= htmlspecialchars($row['age'] ?? ''); ?>">
                                                                            </div>
                                                                            
                                                                            <div class="col-md-4">
                                                                                <label class="form-label fw-bold">Gender</label>
                                                                                <select name="gender" class="form-select">
                                                                                    <option value="Male" <?= ($row['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                                                                    <option value="Female" <?= ($row['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                                                                                    <option value="Other" <?= ($row['gender'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                                                                                </select>
                                                                            </div>
                                                                            
                                                                            <div class="col-md-6">
                                                                                <label class="form-label fw-bold">National ID (NID)</label>
                                                                                <input type="text" name="national_id" class="form-control" value="<?= htmlspecialchars($row['national_id'] ?? ''); ?>" placeholder="Optional NID Number">
                                                                            </div>
                                                                            <div class="col-md-6">
                                                                                <label class="form-label fw-bold">Address</label>
                                                                                <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($row['address'] ?? ''); ?>" placeholder="Patient Address">
                                                                            </div>
                                                                            <div class="col-md-6">
                                                                                <label class="form-label fw-bold">Emergency Contact Person</label>
                                                                                <input type="text" name="emergency_contact_name" class="form-control" value="<?= htmlspecialchars($row['emergency_contact_name'] ?? ''); ?>">
                                                                            </div>
                                                                            <div class="col-md-6">
                                                                                <label class="form-label fw-bold">Emergency Contact Phone</label>
                                                                                <input type="text" name="emergency_contact_phone" class="form-control" value="<?= htmlspecialchars($row['emergency_contact_phone'] ?? ''); ?>">
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                    
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                        <button type="submit" class="btn btn-success"><i class="bi bi-save me-1"></i> Save Changes</button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">No patients found matching your search.</td>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>