<?php
session_start();
require 'db_connect.php';

// 1. Login security check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'Patient';
$full_name = $_SESSION['full_name'] ?? 'User';
$message   = "";
$message_type = "";

// 2. Database table and column check (Auto-Fix)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS appointments (
        appointment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        patient_id INT UNSIGNED NOT NULL,
        doctor_id INT UNSIGNED NOT NULL,
        appointment_date DATE NOT NULL,
        appointment_time VARCHAR(100) NULL,
        problem_description TEXT NULL,
        consultation_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status ENUM('Pending', 'Approved', 'Confirmed', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;");

    // 🆕 Auto-fix hospital_settings table for dynamic bKash & Nagad merchant numbers
    $pdo->exec("CREATE TABLE IF NOT EXISTS hospital_settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB;");

    $pdo->exec("INSERT INTO hospital_settings (setting_key, setting_value) VALUES 
    ('bkash_number', '01700000000'),
    ('nagad_number', '01700000000') 
    ON DUPLICATE KEY UPDATE setting_key=setting_key;");

    $check_time = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'appointment_time'");
    if ($check_time and $check_time->rowCount() == 0) {
        $pdo->exec("ALTER TABLE appointments ADD appointment_time VARCHAR(100) NULL AFTER appointment_date");
    }

    $check_desc = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'problem_description'");
    if ($check_desc and $check_desc->rowCount() == 0) {
        $pdo->exec("ALTER TABLE appointments ADD problem_description TEXT NULL AFTER appointment_time");
    }

    $check_fee = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'consultation_fee'");
    if ($check_fee and $check_fee->rowCount() == 0) {
        $pdo->exec("ALTER TABLE appointments ADD consultation_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER problem_description");
    }

    $check_status = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'status'");
    if ($check_status and $check_status->rowCount() == 0) {
        $pdo->exec("ALTER TABLE appointments ADD status ENUM('Pending', 'Approved', 'Confirmed', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Pending' AFTER consultation_fee");
    }

    // Auto-fix consultation_type (Online / Offline)
    $check_type = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'consultation_type'");
    if ($check_type and $check_type->rowCount() == 0) {
        $pdo->exec("ALTER TABLE appointments ADD consultation_type ENUM('Offline', 'Online') NOT NULL DEFAULT 'Offline' AFTER consultation_fee");
    }

    // Auto-fix payment_status
    $check_pay = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'payment_status'");
    if ($check_pay and $check_pay->rowCount() == 0) {
        $pdo->exec("ALTER TABLE appointments ADD payment_status ENUM('Unpaid', 'Paid', 'Due', 'Pending Verification') NOT NULL DEFAULT 'Unpaid' AFTER consultation_fee");
    }

    // Auto-fix payment_method & transaction_id
    $check_method = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'payment_method'");
    if ($check_method and $check_method->rowCount() == 0) {
        $pdo->exec("ALTER TABLE appointments ADD payment_method VARCHAR(50) NULL AFTER payment_status");
    }

    $check_trx = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'transaction_id'");
    if ($check_trx and $check_trx->rowCount() == 0) {
        $pdo->exec("ALTER TABLE appointments ADD transaction_id VARCHAR(100) NULL AFTER payment_method");
    }

    // Auto-fix appointment_id in billing_invoices
    $check_inv_app = $pdo->query("SHOW COLUMNS FROM billing_invoices LIKE 'appointment_id'");
    if ($check_inv_app and $check_inv_app->rowCount() == 0) {
        $pdo->exec("ALTER TABLE billing_invoices ADD COLUMN appointment_id INT UNSIGNED NULL AFTER patient_id");
    }
} catch (PDOException $e) {
    // Error handling
}

// 🆕 Fetch dynamic bKash & Nagad merchant numbers from database
$settings = [];
try {
    $stmt_set = $pdo->query("SELECT setting_key, setting_value FROM hospital_settings");
    while ($s_row = $stmt_set->fetch(PDO::FETCH_ASSOC)) {
        $settings[$s_row['setting_key']] = $s_row['setting_value'];
    }
} catch (PDOException $e) {}

$dynamic_bkash = $settings['bkash_number'] ?? '01700000000';
$dynamic_nagad = $settings['nagad_number'] ?? '01700000000';

// 3. Load logged patient_id
$logged_patient_id = null;
if ($role == 'Patient') {
    $stmt_p = $pdo->prepare("SELECT patient_id FROM patients WHERE user_id = ?");
    $stmt_p->execute([$user_id]);
    $logged_patient_id = $stmt_p->fetchColumn();
}

// 4. Fetch patient options for Admin/Receptionist
$patient_options = [];
if (in_array($role, ['Admin', 'Receptionist'])) {
    $stmt_pts = $pdo->query("SELECT patient_id, full_name, phone FROM patients ORDER BY patient_id DESC");
    if ($stmt_pts) {
        $patient_options = $stmt_pts->fetchAll(PDO::FETCH_ASSOC);
    }
}

// 5. Fetch doctors list
$doctors_list = [];
try {
    $sql_docs = "SELECT d.doctor_id, d.department_name, d.specialization, d.consultation_fee, d.schedule_hours, d.availability_status, u.full_name 
                 FROM doctors d 
                 JOIN users u ON d.user_id = u.user_id 
                 WHERE d.status = 'Approved' AND u.status IN ('Active', 'Present') 
                 AND (d.availability_status IS NULL OR d.availability_status = '' OR d.availability_status IN ('Present', 'Available'))
                 ORDER BY u.full_name ASC";
    $stmt_d = $pdo->query($sql_docs);
    if ($stmt_d) {
        $doctors_list = $stmt_d->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $message = "Error fetching doctors: " . $e->getMessage();
    $message_type = "danger";
}

// 6. Handle submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $selected_patient_id = ($role == 'Patient') ? $logged_patient_id : intval($_POST['patient_id'] ?? 0);
    $doctor_id          = intval($_POST['doctor_id'] ?? 0);
    $appointment_date   = $_POST['appointment_date'] ?? '';
    $appointment_time   = trim($_POST['appointment_time'] ?? '');
    $problem_desc       = trim($_POST['problem_description'] ?? '');
    $consultation_type  = trim($_POST['consultation_type'] ?? 'Offline');
    $payment_method     = trim($_POST['payment_method'] ?? 'Cash at Counter');
    $transaction_id     = trim($_POST['transaction_id'] ?? '');

    $initial_status = in_array($role, ['Admin', 'Receptionist']) ? 'Approved' : 'Pending';

    // 🛑 Rule 1: Check Offline Cash vs Online Payment
    $is_cash = in_array($payment_method, ['Cash at Counter', 'Cash at Hospital Counter (Offline)', 'Cash']);
    $is_online_consult = ($consultation_type === 'Online');

    // Strict Backend Validation for Day Matching
    $day_of_week = date('D', strtotime($appointment_date)); // Sat, Sun, Mon, Tue, Wed, Thu, Fri
    $is_day_mismatch = false;

    if ((stripos($appointment_time, 'Sat') !== false and stripos($appointment_time, 'Wed') !== false) and !in_array($day_of_week, ['Sat', 'Wed'])) {
        $is_day_mismatch = true;
    } elseif ((stripos($appointment_time, 'Sun') !== false and stripos($appointment_time, 'Thu') !== false) and !in_array($day_of_week, ['Sun', 'Thu'])) {
        $is_day_mismatch = true;
    }

    if (empty($selected_patient_id)) {
        $message = "Please complete your patient profile or select a patient first!";
        $message_type = "warning";
    } elseif (empty($doctor_id) or empty($appointment_date) or empty($appointment_time)) {
        $message = "Please fill in all required fields (Doctor, Date, Time)!";
        $message_type = "warning";
    } elseif ($is_online_consult and $is_cash) {
        // 🛑 online appointment must require online payment
        $message = "⚠️ অনলাইন কনসাল্টেশনের জন্য বিকাশ বা নগদে পেমেন্ট করা বাধ্যতামূলক! ক্যাশ পেমেন্ট গ্রহণযোগ্য নয়।";
        $message_type = "danger";
    } elseif (!$is_cash and empty($transaction_id)) {
        $message = "⚠️ অনুগ্রহ করে বিকাশ/নগদ পেমেন্টের ট্রানজেকশন আইডি (TrxID) প্রদান করুন!";
        $message_type = "warning";
    } elseif ($is_day_mismatch) {
        $message = "Selected date ($appointment_date, $day_of_week) is invalid for the chosen slot!";
        $message_type = "danger";
    } else {
        try {
            $chk_doc_st = $pdo->prepare("SELECT availability_status FROM doctors WHERE doctor_id = ?");
            $chk_doc_st->execute([$doctor_id]);
            $doc_curr_st = $chk_doc_st->fetchColumn();

            if (in_array($doc_curr_st, ['Absent', 'On Leave'])) {
                $message = "Selected doctor is currently Absent/On Leave. Please choose another doctor!";
                $message_type = "danger";
            } else {
                $stmt_fee = $pdo->prepare("SELECT consultation_fee FROM doctors WHERE doctor_id = ?");
                $stmt_fee->execute([$doctor_id]);
                $fee = $stmt_fee->fetchColumn() ?: 0.00;

                // Determine Payment Status: Cash = Unpaid (Due), Online = Pending Verification (Until Admin Approves)
                $initial_pay_st = $is_cash ? 'Unpaid' : 'Pending Verification';
                $inv_pay_status = $is_cash ? 'Due' : 'Due';

                // Admin or Receptionist directly booking
                if (in_array($role, ['Admin', 'Receptionist'])) {
                    $initial_pay_st = $is_cash ? 'Unpaid' : 'Paid';
                    $inv_pay_status = $is_cash ? 'Due' : 'Paid';
                }

                // 1. Insert into appointments
                $ins_app = $pdo->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, problem_description, consultation_fee, consultation_type, status, payment_status, payment_method, transaction_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins_app->execute([$selected_patient_id, $doctor_id, $appointment_date, $appointment_time, $problem_desc, $fee, $consultation_type, $initial_status, $initial_pay_st, $payment_method, $transaction_id]);
                $inserted_app_id = $pdo->lastInsertId();

                // 2. Automatically insert record into billing_invoices (Due status initially until Receptionist approves)
                if (floatval($fee) > 0) {
                    try {
                        $stmt_pinfo = $pdo->prepare("SELECT full_name, phone FROM patients WHERE patient_id = ?");
                        $stmt_pinfo->execute([$selected_patient_id]);
                        $pinfo = $stmt_pinfo->fetch(PDO::FETCH_ASSOC);
                        $p_name  = $pinfo['full_name'] ?? $full_name;
                        $p_phone = $pinfo['phone'] ?? '';

                        $itemized = json_encode([
                            [
                                'service'    => 'Doctor Consultation Fee (' . $consultation_type . ')',
                                'quantity'   => 1,
                                'unit_price' => floatval($fee),
                                'total'      => floatval($fee)
                            ]
                        ], JSON_UNESCAPED_UNICODE);

                        $inv_stmt = $pdo->prepare("INSERT INTO billing_invoices (patient_id, appointment_id, patient_name, phone, itemized_services, total_amount, discount, net_payable, payment_status) VALUES (?, ?, ?, ?, ?, ?, 0.00, ?, ?)");
                        $inv_stmt->execute([$selected_patient_id, $inserted_app_id, $p_name, $p_phone, $itemized, $fee, $fee, $inv_pay_status]);
                        $last_inv_id = $pdo->lastInsertId();

                        // Admin or Receptionist srasori book korle kebol finance_transactions-e add hobe
                        if (in_array($role, ['Admin', 'Receptionist']) && $inv_pay_status === 'Paid') {
                            $acc_head = "Online Doctor Consultation Fee (#INV-" . $last_inv_id . ") - " . $p_name;
                            $ins_inc = $pdo->prepare("INSERT INTO finance_transactions (transaction_type, account_head, amount) VALUES ('Income', ?, ?)");
                            $ins_inc->execute([$acc_head, $fee]);
                        }
                    } catch (PDOException $e) {
                        // Safe fallback
                    }
                }

                $message = $is_cash 
                    ? "Appointment booked! Payment Status: Due. Please pay ৳" . number_format($fee, 2) . " at Reception/Accountant counter." 
                    : "Appointment request submitted! TrxID check kore Receptionist approve korar por payment verify hobe.";
                $message_type = "success";
            }
        } catch (PDOException $e) {
            $message = "Booking Error: " . $e->getMessage();
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
    <title>Book Appointment - Smart Hospital ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Flatpickr CSS for Custom Graphical Calendar -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .booking-card { max-width: 750px; margin: 40px auto; border: none; border-radius: 12px; }
        .card-header { background-color: #1e293b; color: #ffffff; border-top-left-radius: 12px !important; border-top-right-radius: 12px !important; }
        .doc-info-box { display: none; background-color: #e0f2fe; border-left: 4px solid #0284c7; border-radius: 6px; }
        
        /* CSS to visibly disable unselectable days in Calendar Popup */
        .flatpickr-day.flatpickr-disabled, .flatpickr-day.flatpickr-disabled:hover {
            color: #cbd5e1 !important;
            background: #f1f5f9 !important;
            cursor: not-allowed !important;
            text-decoration: line-through;
            opacity: 0.4;
        }
    </style>
</head>
<body>

<div class="container py-4">
    <div class="card booking-card shadow">
        <div class="card-header p-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Book Doctor Appointment</h5>
            <a href="dashboard.php" class="btn btn-sm btn-outline-light"><i class="bi bi-arrow-left"></i> Dashboard</a>
        </div>
        <div class="card-body p-4">

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $message_type; ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form method="POST" action="book_appointment.php">
                
                <?php if (in_array($role, ['Admin', 'Receptionist'])): ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select Patient <span class="text-danger">*</span></label>
                        <select name="patient_id" class="form-select" required>
                            <option value="">-- Choose Patient --</option>
                            <?php foreach ($patient_options as $p): ?>
                                <option value="<?= $p['patient_id']; ?>">
                                    #PAT-<?= $p['patient_id']; ?>: <?= htmlspecialchars($p['full_name']); ?> (<?= htmlspecialchars($p['phone'] ?: 'No Phone'); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Patient Name</label>
                        <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($full_name); ?>" readonly>
                    </div>
                <?php endif; ?>

                <!-- Consultation Type Options (Online vs Offline) -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">Consultation Mode <span class="text-danger">*</span></label>
                    <select name="consultation_type" id="consultationType" class="form-select fw-bold text-primary" required onchange="togglePaymentRules()">
                        <option value="Offline">🏥 Hospital Visit (In-Person / Offline)</option>
                        <option value="Online">🌐 Online Teleconsultation (WhatsApp / Zoom Call)</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Select Specialist Doctor <span class="text-danger">*</span></label>
                    <select name="doctor_id" id="doctorSelect" class="form-select" required onchange="updateDoctorDetails()">
                        <option value="">-- Choose Available Doctor --</option>
                        <?php foreach ($doctors_list as $doc): ?>
                            <option value="<?= $doc['doctor_id']; ?>" 
                                    data-dept="<?= htmlspecialchars($doc['department_name']); ?>"
                                    data-spec="<?= htmlspecialchars($doc['specialization'] ?: 'General'); ?>"
                                    data-fee="<?= htmlspecialchars($doc['consultation_fee']); ?>"
                                    data-schedule="<?= htmlspecialchars($doc['schedule_hours'] ?: 'Flexible Hours'); ?>">
                                Dr. <?= htmlspecialchars($doc['full_name']); ?> (<?= htmlspecialchars($doc['department_name']); ?>) - 🟢 Available
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="doctorInfoBox" class="doc-info-box p-3 mb-3">
                    <div class="row text-dark">
                        <div class="col-md-4">
                            <small class="text-muted d-block">Department</small>
                            <strong id="infoDept">-</strong>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Schedule Hours</small>
                            <strong id="infoSchedule">-</strong>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Consultation Fee</small>
                            <strong id="infoFee" class="text-success">৳ 0.00</strong>
                        </div>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Preferred Time / Slot <span class="text-danger">*</span></label>
                        <select name="appointment_time" id="timeSlotSelect" class="form-select" required onchange="applyCalendarRestrictions()">
                            <option value="">-- Select Time Slot --</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Appointment Date <span class="text-danger">*</span></label>
                        <!-- type="text" and readonly forces custom Flatpickr calendar pop-up instead of browser default -->
                        <input type="text" name="appointment_date" id="appointmentDate" class="form-control bg-white" placeholder="Click to choose date" readonly required>
                    </div>
                </div>

                <!-- Payment Method Options with Dynamic Database Numbers -->
                <div class="card p-3 mb-3 border-light bg-light">
                    <label class="form-label fw-semibold text-primary"><i class="bi bi-wallet2 me-1"></i> Payment Method</label>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <select name="payment_method" id="paymentMethodSelect" class="form-select" onchange="toggleTrxBox()">
                                <option value="Cash at Counter" id="optCash">💵 Cash at Hospital Counter (Offline)</option>
                                <option value="bKash">📱 bKash Online (Merchant: <?= htmlspecialchars($dynamic_bkash); ?>)</option>
                                <option value="Nagad">📱 Nagad Online (Merchant: <?= htmlspecialchars($dynamic_nagad); ?>)</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="trxBox" style="display: none;">
                            <input type="text" name="transaction_id" id="trxInput" class="form-control" placeholder="Enter TrxID / Reference No">
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">Reason for Visit / Symptoms</label>
                    <textarea name="problem_description" class="form-control" rows="3" placeholder="Describe symptoms or reason for consulting the doctor..."></textarea>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                    <a href="dashboard.php" class="btn btn-secondary me-md-2">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-calendar-plus me-1"></i> Confirm Booking</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Flatpickr JS Library -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
let fpPicker = null;

document.addEventListener("DOMContentLoaded", function() {
    initFlatpickr([0, 1, 2, 3, 4, 5, 6]); // Default all days active
    togglePaymentRules();
});

function initFlatpickr(allowedDays) {
    if (fpPicker) {
        fpPicker.destroy();
    }

    const dateInput = document.getElementById('appointmentDate');
    dateInput.value = ""; // Clear existing input when slot changes

    fpPicker = flatpickr("#appointmentDate", {
        dateFormat: "Y-m-d",
        minDate: "today",
        disable: [
            function(date) {
                // Return true to DISABLE any day that is NOT in allowedDays
                return !allowedDays.includes(date.getDay());
            }
        ]
    });
}

function updateDoctorDetails() {
    const select = document.getElementById('doctorSelect');
    const selectedOption = select.options[select.selectedIndex];
    const infoBox = document.getElementById('doctorInfoBox');
    const timeSlotSelect = document.getElementById('timeSlotSelect');

    timeSlotSelect.innerHTML = '<option value="">-- Select Time Slot --</option>';

    if (select.value) {
        const dept = selectedOption.getAttribute('data-dept');
        const spec = selectedOption.getAttribute('data-spec');
        const schedule = selectedOption.getAttribute('data-schedule');
        const fee = selectedOption.getAttribute('data-fee');

        document.getElementById('infoDept').innerText = dept + " (" + spec + ")";
        document.getElementById('infoSchedule').innerText = schedule;
        document.getElementById('infoFee').innerText = "৳ " + parseFloat(fee).toFixed(2);
        infoBox.style.display = 'block';

        if (schedule && schedule !== 'Flexible Hours') {
            let slots = schedule.split(/[,;\n]+/);
            
            slots.forEach(slot => {
                let cleanSlot = slot.trim();
                if (cleanSlot) {
                    let option = document.createElement('option');
                    option.value = cleanSlot;
                    option.textContent = cleanSlot;
                    timeSlotSelect.appendChild(option);
                }
            });
        }
        
        if (timeSlotSelect.options.length <= 1) {
            const genericSlots = [
                "Morning Slot (10:00 AM - 01:00 PM)",
                "Afternoon Slot (02:00 PM - 05:00 PM)",
                "Evening Slot (06:00 PM - 09:00 PM)"
            ];
            genericSlots.forEach(slot => {
                let option = document.createElement('option');
                option.value = slot;
                option.textContent = slot;
                timeSlotSelect.appendChild(option);
            });
        }
        applyCalendarRestrictions();
    } else {
        infoBox.style.display = 'none';
    }
}

// Strictly enable ONLY matching slot days and disable all other days
function applyCalendarRestrictions() {
    const slotSelect = document.getElementById('timeSlotSelect');
    const selectedSlot = slotSelect.value;

    let allowedDays = [0, 1, 2, 3, 4, 5, 6]; // 0=Sun, 1=Mon, 2=Tue, 3=Wed, 4=Thu, 5=Fri, 6=Sat

    if (selectedSlot.includes("Sat") && selectedSlot.includes("Wed")) {
        allowedDays = [6, 3];
    } else if (selectedSlot.includes("Sun") && selectedSlot.includes("Thu")) {
        allowedDays = [0, 4];
    }

    initFlatpickr(allowedDays);
}

function togglePaymentRules() {
    const cType = document.getElementById('consultationType').value;
    const optCash = document.getElementById('optCash');
    const paySelect = document.getElementById('paymentMethodSelect');

    if (cType === 'Online') {
        optCash.disabled = true;
        if (paySelect.value === 'Cash at Counter') {
            paySelect.value = 'bKash';
        }
    } else {
        optCash.disabled = false;
    }
    toggleTrxBox();
}

function toggleTrxBox() {
    const method = document.getElementById('paymentMethodSelect').value;
    const trxBox = document.getElementById('trxBox');
    const trxInput = document.getElementById('trxInput');

    if (method === 'bKash' || method === 'Nagad') {
        trxBox.style.display = 'block';
        trxInput.required = true;
    } else {
        trxBox.style.display = 'none';
        trxInput.required = false;
    }
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>