<?php
session_start();
require 'db_connect.php';

// 1. Login Security Check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'Patient';
$full_name = $_SESSION['full_name'] ?? 'User';

// Receptionist block (Accountant, Admin, Patient exclusive)
if ($role == 'Receptionist') {
    header("Location: dashboard.php");
    exit();
}

$message = "";
$message_type = "";

// 2. Database Schema Sync (billing_invoices Table)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS billing_invoices (
        invoice_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        patient_id INT UNSIGNED NOT NULL,
        appointment_id INT UNSIGNED NULL,
        patient_name VARCHAR(255) NULL,
        phone VARCHAR(50) NULL,
        itemized_services JSON NULL,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        net_payable DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        payment_status ENUM('Paid','Partial','Due') NOT NULL DEFAULT 'Due',
        invoice_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;");

    $columns = $pdo->query("SHOW COLUMNS FROM billing_invoices")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('patient_name', $columns)) {
        $pdo->exec("ALTER TABLE billing_invoices ADD COLUMN patient_name VARCHAR(255) NULL AFTER patient_id");
    }
    if (!in_array('phone', $columns)) {
        $pdo->exec("ALTER TABLE billing_invoices ADD COLUMN phone VARCHAR(50) NULL AFTER patient_name");
    }
    if (!in_array('appointment_id', $columns)) {
        $pdo->exec("ALTER TABLE billing_invoices ADD COLUMN appointment_id INT UNSIGNED NULL AFTER patient_id");
    }
} catch (PDOException $e) {}

// 3. User Info & Patient Auto-Linking
$u_phone = "";
try {
    $stmt_u = $pdo->prepare("SELECT full_name, phone_number FROM users WHERE user_id = ?");
    $stmt_u->execute([$user_id]);
    $u_data = $stmt_u->fetch(PDO::FETCH_ASSOC);
    if ($u_data) {
        $full_name = trim($u_data['full_name'] ?? $full_name);
        $u_phone   = trim($u_data['phone_number'] ?? '');
    }
} catch (PDOException $e) {}

// 🛑 4. Collect Cash Payment Action (By Accountant / Admin)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'collect_cash_payment') {
    if (in_array($role, ['Admin', 'Accountant'])) {
        $inv_id = intval($_POST['invoice_id'] ?? 0);

        if ($inv_id > 0) {
            try {
                // Fetch Invoice Details
                $stmt = $pdo->prepare("SELECT * FROM billing_invoices WHERE invoice_id = ?");
                $stmt->execute([$inv_id]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($inv && $inv['payment_status'] != 'Paid') {
                    $net_payable = floatval($inv['net_payable']);
                    $p_id        = $inv['patient_id'];
                    $app_id      = $inv['appointment_id'] ?? null;

                    // 1. Update Invoice Payment Status to Paid
                    $pdo->prepare("UPDATE billing_invoices SET payment_status = 'Paid' WHERE invoice_id = ?")->execute([$inv_id]);

                    // 2. Add Entry to Finance Transactions (Total Income)
                    $acc_head = "Hospital Bill Counter Cash Collection (#INV-" . $inv_id . ") - " . ($inv['patient_name'] ?? 'Patient');
                    $pdo->prepare("INSERT INTO finance_transactions (transaction_type, account_head, amount) VALUES ('Income', ?, ?)")->execute([$acc_head, $net_payable]);

                    // 3. Update related Appointment and Laboratory Test Payment Status to Paid
                    if (!empty($app_id)) {
                        $pdo->prepare("UPDATE appointments SET payment_status = 'Paid' WHERE appointment_id = ?")->execute([$app_id]);
                    }
                    if (!empty($p_id)) {
                        $pdo->prepare("UPDATE appointments SET payment_status = 'Paid' WHERE patient_id = ? AND payment_status = 'Unpaid'")->execute([$p_id]);
                        $pdo->prepare("UPDATE laboratory_tests SET payment_status = 'Paid' WHERE patient_id = ? AND payment_status = 'Unpaid'")->execute([$p_id]);
                    }

                    $message = "✅ Payment of ৳" . number_format($net_payable, 2) . " collected successfully for Invoice #INV-" . $inv_id . "! Total Income updated.";
                    $message_type = "success";
                }
            } catch (PDOException $e) {
                $message = "Error collecting payment: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }
}

// 5. Generate Invoice Action (Accountant / Admin)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'create_invoice') {
    if (in_array($role, ['Admin', 'Accountant'])) {
        $pid         = intval($_POST['patient_id'] ?? 0);
        $service     = trim($_POST['service_description'] ?? '');
        $qty         = intval($_POST['quantity'] ?? 1);
        $unit_price  = floatval($_POST['unit_price'] ?? 0);
        $discount    = floatval($_POST['discount'] ?? 0);
        $p_status    = $_POST['payment_status'] ?? 'Due';

        $total_amount = $qty * $unit_price;
        $net_payable  = max(0, $total_amount - $discount);

        $services_arr = [
            [
                "service"    => $service,
                "quantity"   => $qty,
                "unit_price" => $unit_price,
                "total"      => $total_amount
            ]
        ];
        $json_services = json_encode($services_arr, JSON_UNESCAPED_UNICODE);

        $p_name  = "Walk-in Patient";
        $p_phone = "";
        if ($pid > 0) {
            $stmt_pat = $pdo->prepare("SELECT full_name, phone FROM patients WHERE patient_id = ?");
            $stmt_pat->execute([$pid]);
            $pat_info = $stmt_pat->fetch(PDO::FETCH_ASSOC);
            if ($pat_info) {
                $p_name  = $pat_info['full_name'];
                $p_phone = $pat_info['phone'];
            }
        }

        if (!empty($service) && $unit_price > 0) {
            try {
                $ins_stmt = $pdo->prepare("INSERT INTO billing_invoices (patient_id, patient_name, phone, itemized_services, total_amount, discount, net_payable, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ins_stmt->execute([$pid, $p_name, $p_phone, $json_services, $total_amount, $discount, $net_payable, $p_status]);
                $last_inv_id = $pdo->lastInsertId();

                // Payment Status 'Paid' হলে অটোমেটিক finance_transactions (Total Income)-এ টাকা যোগ হবে
                if ($p_status === 'Paid') {
                    $acc_head = "Hospital Bill Collection (#INV-" . $last_inv_id . ") - " . $p_name;
                    $ins_inc = $pdo->prepare("INSERT INTO finance_transactions (transaction_type, account_head, amount) VALUES ('Income', ?, ?)");
                    $ins_inc->execute([$acc_head, $net_payable]);
                }

                // Billing successful: Instant redirect to print receipt
                header("Location: print_receipt.php?id=" . $last_inv_id);
                exit();
            } catch (PDOException $e) {
                $message = "Error creating invoice: " . $e->getMessage();
                $message_type = "danger";
            }
        } else {
            $message = "Please fill in all required service details!";
            $message_type = "warning";
        }
    }
}

// 6. Direct Fetch Query For Patient & Staff
$invoices = [];
try {
    if ($role == 'Patient') {
        $p_ids = [0];
        $stmt_p = $pdo->prepare("SELECT patient_id FROM patients WHERE user_id = ? OR (phone = ? AND phone != '')");
        $stmt_p->execute([$user_id, $u_phone]);
        while ($r = $stmt_p->fetch(PDO::FETCH_ASSOC)) {
            $p_ids[] = (int)$r['patient_id'];
        }
        $p_ids_str = implode(',', array_unique($p_ids));

        $sql = "SELECT * FROM billing_invoices 
                WHERE patient_id IN ($p_ids_str)
                   OR (phone = ? AND ? != '')
                   OR (patient_name LIKE ? AND ? != '')
                ORDER BY invoice_id DESC";
        
        $stmt_inv = $pdo->prepare("SELECT * FROM billing_invoices WHERE patient_id IN ($p_ids_str) OR (phone = ? AND ? != '') OR (patient_name LIKE ? AND ? != '') ORDER BY invoice_id DESC");
        $stmt_inv->execute([$u_phone, $u_phone, "%$full_name%", $full_name]);
        $invoices = $stmt_inv->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sql = "SELECT i.*, COALESCE(i.patient_name, p.full_name) AS p_fullname, COALESCE(i.phone, p.phone) AS p_phone 
                FROM billing_invoices i 
                LEFT JOIN patients p ON i.patient_id = p.patient_id 
                ORDER BY i.invoice_id DESC";
        $invoices = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {}

// Patient Dropdown List
$patients_list = [];
try {
    $stmt_pl = $pdo->query("SELECT p.patient_id, COALESCE(p.full_name, u.full_name) AS full_name, COALESCE(p.phone, u.phone_number) AS phone 
                            FROM patients p 
                            LEFT JOIN users u ON p.user_id = u.user_id 
                            ORDER BY full_name ASC");
    if ($stmt_pl) $patients_list = $stmt_pl->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Selected Patient & Auto Amount via URL parameter (From Patient Directory)
$selected_patient_id = intval($_GET['patient_id'] ?? 0);
$auto_amount         = floatval($_GET['auto_amount'] ?? 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $role == 'Patient' ? 'My Personal Bills & Invoices' : 'Patient Billing & Invoice Management'; ?> - ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f7f6; margin: 0; }
        .sidebar { min-height: 100vh; background-color: #1e293b; color: white; width: 260px; flex-shrink: 0; }
        .sidebar a { color: #94a3b8; text-decoration: none; padding: 10px 15px; display: block; border-radius: 6px; margin-bottom: 4px; font-weight: 500; }
        .sidebar a:hover, .sidebar a.active { background-color: #334155; color: #ffffff; }
        .sidebar i { margin-right: 10px; }
        .nav-category { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 1px; color: #64748b; margin-top: 18px; margin-bottom: 6px; padding-left: 15px; font-weight: 700; }
    </style>
</head>
<body>

<div class="d-flex">
    <!-- Sidebar -->
    <div class="sidebar p-3">
        <h4 class="text-center text-white mb-4"><i class="bi bi-hospital"></i> Hospital ERP</h4>
        <hr class="text-secondary">
        
        <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>

        <?php if (in_array($role, ['Admin', 'Accountant'])): ?>
            <div class="nav-category">Accounts & Billing</div>
            <a href="billing.php" class="active"><i class="bi bi-receipt"></i> Billing & Invoices</a>
            <a href="finance.php"><i class="bi bi-cash-coin"></i> Payroll & Finance</a>
            <div class="nav-category">Patient Management</div>
            <a href="patient_list.php"><i class="bi bi-people"></i> Patient Directory</a>
        <?php else: ?>
            <div class="nav-category">Patient Portal</div>
            <a href="book_appointment.php"><i class="bi bi-calendar-check"></i> Book Appointment</a>
            <a href="appointments.php"><i class="bi bi-journal-check"></i> My Appointments</a>
            <a href="billing.php" class="active"><i class="bi bi-receipt"></i> My Bills & Invoices</a>
            <a href="modules.php?tab=blood"><i class="bi bi-droplet text-danger"></i> Request Blood</a>
            <a href="edit_profile.php"><i class="bi bi-person-badge"></i> Edit My Profile</a>
        <?php endif; ?>

        <hr class="text-secondary mt-4">
        <a href="logout.php" class="text-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>

    <!-- Main Content -->
    <div class="w-100 p-4">
        <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded shadow-sm">
            <h4 class="mb-0 text-primary fw-bold">
                <i class="bi bi-receipt me-2"></i><?= $role == 'Patient' ? 'My Personal Bills & Invoices' : 'Patient Billing & Invoice Management'; ?>
            </h4>
            <span class="text-muted">Welcome, <strong><?= htmlspecialchars($full_name); ?></strong> (<?= htmlspecialchars($role); ?>)</span>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $message_type; ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Accountant Form -->
        <?php if (in_array($role, ['Admin', 'Accountant'])): ?>
            <div class="card border-0 shadow-sm p-4 mb-4" style="background-color: #10b981; color: white;">
                <h5 class="fw-bold mb-3"><i class="bi bi-plus-circle me-2"></i>Generate New Patient Invoice</h5>
                
                <form method="POST" action="billing.php" class="bg-white p-3 rounded text-dark shadow-sm">
                    <input type="hidden" name="action" value="create_invoice">
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Select Patient *</label>
                            <select name="patient_id" class="form-select" required>
                                <option value="">-- Choose Patient --</option>
                                <?php foreach ($patients_list as $pl): ?>
                                    <option value="<?= $pl['patient_id']; ?>" <?= ($selected_patient_id == $pl['patient_id']) ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($pl['full_name']); ?> (<?= htmlspecialchars($pl['phone'] ?: 'No Phone'); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Service / Charge Description Dropdown Select -->
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Service / Charge Description *</label>
                            <select name="service_description" class="form-select" required>
                                <option value="">-- Select Service / Charge --</option>
                                <option value="Doctor Consultation Fee">Doctor Consultation Fee</option>
                                <option value="ICU Cabin Rent Charge">ICU Cabin Rent Charge</option>
                                <option value="VIP Cabin Rent Charge">VIP Cabin Rent Charge</option>
                                <option value="General Ward Bed Rent">General Ward Bed Rent</option>
                                <option value="Pathology & Lab Test Fee">Pathology & Lab Test Fee</option>
                                <option value="Blood Bank Service Charge">Blood Bank Service Charge</option>
                                <option value="Pharmacy & Medicine Bill">Pharmacy & Medicine Bill</option>
                                <option value="Emergency Ambulance Charge">Emergency Ambulance Charge</option>
                                <option value="Operation Theater (OT) Charge">Operation Theater (OT) Charge</option>
                                <option value="Nursing & Service Care Charge">Nursing & Service Care Charge</option>
                                <option value="Other Hospital Service Charge">Other Hospital Service Charge</option>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label fw-bold">Quantity</label>
                            <input type="number" name="quantity" class="form-control" value="1" min="1" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Unit Price (BDT) *</label>
                            <input type="number" step="0.01" name="unit_price" class="form-control" placeholder="0.00" value="<?= $auto_amount > 0 ? htmlspecialchars($auto_amount) : ''; ?>" required>
                        </div>
                    </div>

                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Discount Amount (BDT)</label>
                            <input type="number" step="0.01" name="discount" class="form-control" value="0.00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Payment Status</label>
                            <select name="payment_status" class="form-select">
                                <option value="Due" selected>Due (বাকি)</option>
                                <option value="Paid">Paid (পরিশোধিত)</option>
                                <option value="Partial">Partial</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <button type="submit" class="btn btn-success w-100 fw-bold py-2">
                                <i class="bi bi-check-circle me-1"></i> Generate Invoice & Print Receipt
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Table View -->
        <div class="card border-0 shadow-sm p-4">
            <h5 class="fw-bold text-secondary mb-3">
                <i class="bi bi-list-columns me-2"></i><?= $role == 'Patient' ? 'My Invoices History' : 'Hospital Billing Invoices Directory'; ?>
            </h5>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Inv #</th>
                            <th>Patient Info</th>
                            <th>Itemized Services</th>
                            <th>Total Amount</th>
                            <th>Discount</th>
                            <th>Net Payable</th>
                            <th>Status</th>
                            <th>Invoice Date</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($invoices)): foreach ($invoices as $inv): 
                            $display_name  = !empty($inv['patient_name']) ? $inv['patient_name'] : ($inv['p_fullname'] ?? 'N/A');
                            $display_phone = !empty($inv['phone']) ? $inv['phone'] : ($inv['p_phone'] ?? '');

                            $services_text = "N/A";
                            if (!empty($inv['itemized_services'])) {
                                $arr = json_decode($inv['itemized_services'], true);
                                if (is_array($arr)) {
                                    $serv_parts = [];
                                    foreach ($arr as $s) {
                                        $serv_parts[] = ($s['service'] ?? '') . (isset($s['quantity']) && $s['quantity'] > 1 ? ' (x'.$s['quantity'].')' : '');
                                    }
                                    $services_text = implode(', ', $serv_parts);
                                } else {
                                    $services_text = $inv['itemized_services'];
                                }
                            }
                        ?>
                            <tr>
                                <td><strong>#INV-<?= htmlspecialchars($inv['invoice_id']); ?></strong></td>
                                <td>
                                    <strong><?= htmlspecialchars($display_name); ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($display_phone); ?></small>
                                </td>
                                <td><?= htmlspecialchars($services_text); ?></td>
                                <td>৳<?= number_format($inv['total_amount'], 2); ?></td>
                                <td>৳<?= number_format($inv['discount'], 2); ?></td>
                                <td class="fw-bold text-success">৳<?= number_format($inv['net_payable'], 2); ?></td>
                                <td>
                                    <?php 
                                        $st = $inv['payment_status'] ?? 'Due';
                                        $badge_bg = 'bg-danger';
                                        if ($st == 'Paid') $badge_bg = 'bg-success';
                                        elseif ($st == 'Partial') $badge_bg = 'bg-warning text-dark';
                                        elseif ($st == 'Due') $badge_bg = 'bg-warning text-dark';
                                    ?>
                                    <span class="badge <?= $badge_bg; ?>"><?= htmlspecialchars($st); ?></span>
                                </td>
                                <td><small class="text-muted"><?= htmlspecialchars($inv['invoice_date'] ?? ''); ?></small></td>
                                <td class="text-center">
                                    <a href="print_receipt.php?id=<?= $inv['invoice_id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary fw-bold">
                                        <i class="bi bi-printer me-1"></i> Print Receipt
                                    </a>

                                    <?php if (in_array($role, ['Admin', 'Accountant']) && $inv['payment_status'] != 'Paid'): ?>
                                        <form method="POST" action="billing.php" class="d-inline" onsubmit="return confirm('Confirm collecting cash payment of ৳<?= number_format($inv['net_payable'], 2); ?> for Invoice #INV-<?= $inv['invoice_id']; ?>?');">
                                            <input type="hidden" name="action" value="collect_cash_payment">
                                            <input type="hidden" name="invoice_id" value="<?= $inv['invoice_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-success fw-bold ms-1">
                                                <i class="bi bi-cash-stack me-1"></i> Collect Cash
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">No billing invoices found.</td>
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