<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'Patient';
$full_name = $_SESSION['full_name'] ?? 'User';
$message   = "";
$message_type = "";

// অটো-ফিক্স (payment_status, consultation_type, meeting_link ও billing_invoices কলাম চেক)
try {
    $check_pay = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'payment_status'");
    if ($check_pay && $check_pay->rowCount() == 0) {
        $pdo->exec("ALTER TABLE appointments ADD payment_status ENUM('Unpaid', 'Paid', 'Due') NOT NULL DEFAULT 'Unpaid' AFTER consultation_fee");
    }
    $pdo->exec("UPDATE appointments SET payment_status = 'Unpaid' WHERE payment_status IS NULL OR payment_status = '' OR payment_status = '0'");

    $check_type = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'consultation_type'");
    if ($check_type && $check_type->rowCount() == 0) {
        $pdo->exec("ALTER TABLE appointments ADD consultation_type ENUM('Offline', 'Online') NOT NULL DEFAULT 'Offline' AFTER consultation_fee");
    }

    $check_link = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'meeting_link'");
    if ($check_link && $check_link->rowCount() == 0) {
        $pdo->exec("ALTER TABLE appointments ADD meeting_link VARCHAR(255) NULL AFTER status");
    }

    $check_inv_app = $pdo->query("SHOW COLUMNS FROM billing_invoices LIKE 'appointment_id'");
    if ($check_inv_app && $check_inv_app->rowCount() == 0) {
        $pdo->exec("ALTER TABLE billing_invoices ADD COLUMN appointment_id INT UNSIGNED NULL AFTER patient_id");
    }
} catch (PDOException $e) {}

// স্টেটাস আপডেট ও একাউন্টসে ইনভয়েস এন্ট্রি (নার্সদের জন্য সম্পূর্ণ ব্লকড)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action_status'], $_POST['appointment_id'])) {
    if (in_array($role, ['Admin', 'Receptionist', 'Doctor'])) {
        $app_id     = intval($_POST['appointment_id']);
        $new_status = $_POST['action_status'];
        $allowed_statuses = ['Confirmed', 'Approved', 'Completed', 'Cancelled', 'Pending'];

        if (in_array($new_status, $allowed_statuses)) {
            try {
                if (in_array($role, ['Admin', 'Receptionist'])) {
                    if (in_array($new_status, ['Approved', 'Confirmed'])) {
                        // ১. অ্যাপয়েন্টমেন্ট ও রোগীর তথ্য ফেচ করা
                        $stmt_app = $pdo->prepare("SELECT a.*, p.full_name AS patient_name, p.phone AS patient_phone FROM appointments a JOIN patients p ON a.patient_id = p.patient_id WHERE a.appointment_id = ?");
                        $stmt_app->execute([$app_id]);
                        $app_data = $stmt_app->fetch(PDO::FETCH_ASSOC);

                        if ($app_data) {
                            $pay_method = $app_data['payment_method'] ?? 'Cash at Counter';
                            $is_cash = in_array($pay_method, ['Cash at Counter', 'Cash at Hospital Counter (Offline)', 'Cash']);

                            // Cash payment hole Unpaid/Due, Online payment hole Paid
                            $app_pay_st = $is_cash ? 'Unpaid' : 'Paid';
                            $inv_pay_st = $is_cash ? 'Due' : 'Paid';

                            // ২. অ্যাপয়েন্টমেন্ট স্টেটাস 'Approved' করা কিন্তু পেমেন্ট টাইপ অনুযায়ী Unpaid/Paid সেট করা
                            $pdo->prepare("UPDATE appointments SET status = ?, payment_status = ? WHERE appointment_id = ?")->execute([$new_status, $app_pay_st, $app_id]);

                            // ৩. billing_invoices (Accounts) টেবিলে বিল যুক্ত করা (ক্যাশ হলে Due, অনলাইন হলে Paid)
                            $fee = floatval($app_data['consultation_fee'] ?? 0);
                            if ($fee > 0) {
                                $chk_inv = $pdo->prepare("SELECT invoice_id FROM billing_invoices WHERE appointment_id = ?");
                                $chk_inv->execute([$app_id]);
                                if (!$chk_inv->fetchColumn()) {
                                    $c_type = $app_data['consultation_type'] ?? 'Offline';
                                    $itemized = json_encode([
                                        [
                                            'service'    => 'Doctor Consultation Fee (' . $c_type . ')',
                                            'quantity'   => 1,
                                            'unit_price' => $fee,
                                            'total'      => $fee
                                        ]
                                    ], JSON_UNESCAPED_UNICODE);

                                    $inv_stmt = $pdo->prepare("INSERT INTO billing_invoices (patient_id, appointment_id, patient_name, phone, itemized_services, total_amount, discount, net_payable, payment_status) VALUES (?, ?, ?, ?, ?, ?, 0.00, ?, ?)");
                                    $inv_stmt->execute([$app_data['patient_id'], $app_id, $app_data['patient_name'], $app_data['patient_phone'], $itemized, $fee, $fee, $inv_pay_st]);

                                    if ($inv_pay_st === 'Paid') {
                                        $last_inv = $pdo->lastInsertId();
                                        $acc_head = "Online Doctor Consultation Fee (#INV-" . $last_inv . ") - " . $app_data['patient_name'];
                                        $pdo->prepare("INSERT INTO finance_transactions (transaction_type, account_head, amount) VALUES ('Income', ?, ?)")->execute([$acc_head, $fee]);
                                    }
                                }
                            }
                            $message = $is_cash 
                                ? "Appointment Approved! Invoice added to Accounts as Due (Collect Cash from Billing)." 
                                : "Appointment Approved & Online Payment Confirmed!";
                        } else {
                            $pdo->prepare("UPDATE appointments SET status = ? WHERE appointment_id = ?")->execute([$new_status, $app_id]);
                            $message = "Appointment status updated to $new_status!";
                        }
                    } else {
                        $pdo->prepare("UPDATE appointments SET status = ? WHERE appointment_id = ?")->execute([$new_status, $app_id]);
                        $message = "Appointment status updated to $new_status!";
                    }
                    $message_type = "success";
                } elseif ($role == 'Doctor') {
                    $stmt_doc = $pdo->prepare("SELECT doctor_id FROM doctors WHERE user_id = ?");
                    $stmt_doc->execute([$user_id]);
                    $my_doc_id = $stmt_doc->fetchColumn();
                    if ($my_doc_id) {
                        $pdo->prepare("UPDATE appointments SET status = ? WHERE appointment_id = ? AND doctor_id = ?")->execute([$new_status, $app_id, $my_doc_id]);
                        $message = "Appointment status updated to $new_status!";
                        $message_type = "success";
                    }
                }
            } catch (PDOException $e) {
                $message = "Error: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }
}

// ডাটা ফেচ (Doctor availability status fetching for alert banner)
$appointments_list = [];
try {
    $sql_base = "SELECT a.*, p.full_name AS patient_name, p.phone AS patient_phone, 
                        u_doc.full_name AS doctor_name, d.department_name, d.availability_status AS doc_avail_status, pr.prescription_id
                 FROM appointments a 
                 LEFT JOIN patients p ON a.patient_id = p.patient_id 
                 LEFT JOIN doctors d ON a.doctor_id = d.doctor_id 
                 LEFT JOIN users u_doc ON d.user_id = u_doc.user_id 
                 LEFT JOIN prescriptions pr ON a.appointment_id = pr.appointment_id ";

    if ($role == 'Doctor') {
        $stmt = $pdo->prepare($sql_base . "WHERE d.user_id = ? AND a.status IN ('Confirmed', 'Approved') ORDER BY a.appointment_id DESC");
        $stmt->execute([$user_id]);
    } elseif ($role == 'Patient') {
        $stmt = $pdo->prepare($sql_base . "WHERE p.user_id = ? ORDER BY a.appointment_id DESC");
        $stmt->execute([$user_id]);
    } else {
        $stmt = $pdo->query($sql_base . "ORDER BY a.appointment_id DESC");
    }
    if ($stmt) $appointments_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "Error: " . $e->getMessage();
    $message_type = "danger";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointments Directory - Smart Hospital ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-card { border: none; border-radius: 12px; margin-top: 30px; }
        .card-header { background-color: #1e293b; color: #ffffff; border-top-left-radius: 12px !important; border-top-right-radius: 12px !important; }
    </style>
</head>
<body>

<div class="container py-4">
    <div class="card main-card shadow-sm">
        <div class="card-header p-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-journal-medical me-2"></i>Appointments & Doctor Visits Directory</h5>
            <div>
                <a href="dashboard.php" class="btn btn-sm btn-outline-light me-2"><i class="bi bi-arrow-left"></i> Dashboard</a>
                <?php if (in_array($role, ['Admin', 'Receptionist', 'Patient'])): ?>
                    <a href="book_appointment.php" class="btn btn-sm btn-primary"><i class="bi bi-plus-circle"></i> Book New</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-4">

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $message_type; ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>SL#</th>
                            <th>Patient Details</th>
                            <th>Assigned Doctor</th>
                            <th>Date & Mode</th>
                            <th>Fee & Payment</th>
                            <th>Status</th>
                            <th class="text-center">Action / Prescription</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($appointments_list)): $sl = 1; ?>
                            <?php foreach ($appointments_list as $app): ?>
                                <?php 
                                    $pay_status = strtoupper(trim($app['payment_status'] ?? 'UNPAID'));
                                    $is_paid = ($pay_status === 'PAID');
                                    $doc_status = $app['doc_avail_status'] ?? 'Present';

                                    // WhatsApp Call Number Normalization
                                    $phone_raw = preg_replace('/[^0-9]/', '', $app['patient_phone'] ?? '');
                                    if (strlen($phone_raw) == 11 && substr($phone_raw, 0, 2) == '01') {
                                        $phone_raw = '88' . $phone_raw;
                                    }
                                ?>
                                <tr class="<?= in_array($doc_status, ['Absent', 'On Leave']) ? 'table-warning' : ''; ?>">
                                    <td><?= $sl++; ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($app['patient_name'] ?? 'N/A'); ?></strong><br>
                                        <small class="text-muted"><i class="bi bi-telephone"></i> <?= htmlspecialchars($app['patient_phone'] ?? 'N/A'); ?></small><br>
                                        <?php if (!empty($app['problem_description'])): ?>
                                            <small class="text-secondary"><em>"<?= htmlspecialchars($app['problem_description']); ?>"</em></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong>Dr. <?= htmlspecialchars($app['doctor_name'] ?? 'N/A'); ?></strong><br>
                                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($app['department_name'] ?? 'General'); ?></span>
                                        
                                        <!-- Doctor Absence Badge Alert -->
                                        <?php if ($doc_status == 'Absent'): ?>
                                            <br><span class="badge bg-danger mt-1"><i class="bi bi-exclamation-triangle-fill me-1"></i> Doctor Absent Today</span>
                                        <?php elseif ($doc_status == 'On Leave'): ?>
                                            <br><span class="badge bg-warning text-dark mt-1"><i class="bi bi-clock-history me-1"></i> Doctor On Leave</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info text-dark">
                                            <i class="bi bi-calendar-event me-1"></i> 
                                            <?= !empty($app['appointment_date']) ? date('Y-m-d', strtotime($app['appointment_date'])) : (!empty($app['scheduled_time']) ? date('Y-m-d', strtotime($app['scheduled_time'])) : ''); ?>
                                        </span><br>
                                        <small class="text-dark fw-semibold"><?= htmlspecialchars($app['appointment_time'] ?? ''); ?></small><br>
                                        
                                        <!-- Mode Display -->
                                        <?php if (($app['consultation_type'] ?? '') == 'Online'): ?>
                                            <span class="badge bg-primary mt-1"><i class="bi bi-globe"></i> Online Call</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary mt-1"><i class="bi bi-building"></i> In-Person</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong class="text-primary">৳<?= number_format(floatval($app['consultation_fee'] ?? 0), 2); ?></strong><br>
                                        <?php if ($is_paid): ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Unpaid</span>
                                        <?php endif; ?>

                                        <?php if (!empty($app['payment_method'])): ?>
                                            <small class="d-block text-muted"><?= htmlspecialchars($app['payment_method']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                            $st = !empty($app['status']) ? $app['status'] : 'Pending';
                                            if ($st == 'Confirmed' || $st == 'Approved') $badge = 'bg-success';
                                            elseif ($st == 'Completed') $badge = 'bg-primary';
                                            elseif ($st == 'Cancelled') $badge = 'bg-danger';
                                            else $badge = 'bg-warning text-dark';
                                        ?>
                                        <span class="badge <?= $badge; ?>"><?= htmlspecialchars($st); ?></span>

                                        <?php if (in_array($doc_status, ['Absent', 'On Leave']) && in_array($st, ['Confirmed', 'Approved', 'Pending'])): ?>
                                            <small class="d-block text-danger fw-bold mt-1"><i class="bi bi-info-circle"></i> Reschedule Needed</small>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-center">
                                        <?php if ($role == 'Nurse'): ?>
                                             <span class="badge bg-light text-dark border"><i class="bi bi-eye text-primary me-1"></i> View Only</span>
                                        <?php else: ?>

                                            <!-- Live Communication Action Buttons -->
                                            <div class="mb-1">
                                                <?php if (($app['consultation_type'] ?? '') == 'Online' && !empty($phone_raw) && in_array($role, ['Doctor', 'Admin', 'Receptionist'])): ?>
                                                    <a href="https://api.whatsapp.com/send?phone=<?= $phone_raw; ?>&text=Assalamu%20Alaikum%20<?= urlencode($app['patient_name'] ?? ''); ?>,%20your%20doctor%20consultation%20time%20is%20now.%20Please%20be%20ready." 
                                                       target="_blank" class="btn btn-sm btn-outline-success me-1" title="WhatsApp Live Call">
                                                        <i class="bi bi-whatsapp"></i> Live Call
                                                    </a>
                                                <?php endif; ?>

                                                <?php if (!empty($app['meeting_link'])): ?>
                                                    <a href="<?= htmlspecialchars($app['meeting_link']); ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Join Video Session">
                                                        <i class="bi bi-camera-video-fill"></i> Join Meeting
                                                    </a>
                                                <?php endif; ?>
                                            </div>

                                            <?php if (!empty($app['prescription_id'])): ?>
                                                <?php if ($role == 'Patient' && !$is_paid): ?>
                                                    <a href="billing.php" class="btn btn-sm btn-outline-danger fw-bold">
                                                        <i class="bi bi-lock-fill me-1"></i> Pay ৳<?= number_format($app['consultation_fee'] ?? 0, 0); ?> to Unlock Rx
                                                    </a>
                                                <?php else: ?>
                                                    <a href="view_prescription.php?id=<?= $app['prescription_id']; ?>" class="btn btn-sm btn-success" target="_blank">
                                                        <i class="bi bi-file-earmark-medical me-1"></i> View Prescription
                                                    </a>
                                                <?php endif; ?>
                                            <?php elseif (in_array($role, ['Doctor', 'Admin']) && $st != 'Cancelled'): ?>
                                                <a href="add_prescription.php?appointment_id=<?= $app['appointment_id']; ?>" class="btn btn-sm btn-outline-primary mb-1">
                                                    <i class="bi bi-pencil-square me-1"></i> Write Rx
                                                </a>
                                            <?php endif; ?>

                                            <!-- Receptionist / Admin Approval Action -->
                                            <?php if (in_array($role, ['Admin', 'Receptionist'])): ?>
                                                <div class="btn-group btn-group-sm d-block mt-1" role="group">
                                                    <?php if ($st == 'Pending'): ?>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="appointment_id" value="<?= $app['appointment_id']; ?>">
                                                            <input type="hidden" name="action_status" value="Approved">
                                                            <button type="submit" class="btn btn-success btn-sm me-1 fw-bold">
                                                                <i class="bi bi-check-circle me-1"></i> Approve Booking
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if ($st != 'Cancelled' && $st != 'Completed'): ?>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="appointment_id" value="<?= $app['appointment_id']; ?>">
                                                            <input type="hidden" name="action_status" value="Cancelled">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Cancel this appointment?');">Cancel</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No appointments found.</td>
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