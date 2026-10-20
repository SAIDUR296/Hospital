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

// Current user details fetch
$u_phone    = "";
$patient_id = null;
$doctor_id  = null;

try {
    $stmt_u = $pdo->prepare("SELECT phone_number FROM users WHERE user_id = ?");
    $stmt_u->execute([$user_id]);
    $curr_u  = $stmt_u->fetch(PDO::FETCH_ASSOC);
    $u_phone = trim($curr_u['phone_number'] ?? '');

    if ($role == 'Patient') {
        $stmt_p = $pdo->prepare("SELECT patient_id FROM patients WHERE user_id = ? OR (phone = ? AND phone != '')");
        $stmt_p->execute([$user_id, $u_phone]);
        $p_row = $stmt_p->fetch(PDO::FETCH_ASSOC);
        if ($p_row) {
            $patient_id = $p_row['patient_id'];
        }
    } elseif ($role == 'Doctor') {
        $stmt_d = $pdo->prepare("SELECT doctor_id FROM doctors WHERE user_id = ?");
        $stmt_d->execute([$user_id]);
        $d_row = $stmt_d->fetch(PDO::FETCH_ASSOC);
        if ($d_row) {
            $doctor_id = $d_row['doctor_id'];
        }
    }
} catch (PDOException $e) {}

// 2. Database Tables Auto-Creation Check
try {
    // Ensure UTF-8 Bangla Character Encoding Support
    $pdo->exec("SET NAMES utf8mb4");

    // Billing Invoices Table Check & Auto Creation
    $pdo->exec("CREATE TABLE IF NOT EXISTS billing_invoices (
        invoice_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        patient_id INT UNSIGNED NOT NULL,
        patient_name VARCHAR(255) NULL,
        phone VARCHAR(50) NULL,
        itemized_services JSON NULL,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        net_payable DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        payment_status ENUM('Paid','Partial','Due') NOT NULL DEFAULT 'Due',
        invoice_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Finance Transactions Table Check & Auto Creation
    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_transactions (
        transaction_id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_type ENUM('Income', 'Expense') NOT NULL,
        account_head VARCHAR(255) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Invoices Table Check & Auto Creation
    $pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
        invoice_id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        description VARCHAR(255) NOT NULL,
        total_amount DECIMAL(10,2) DEFAULT 0.00,
        paid_amount DECIMAL(10,2) DEFAULT 0.00,
        payment_status ENUM('Unpaid', 'Paid', 'Partially Paid') DEFAULT 'Unpaid',
        payment_method VARCHAR(50) NULL,
        transaction_id VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // OT Schedules Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS ot_schedules (
        ot_id INT AUTO_INCREMENT PRIMARY KEY,
        room_number VARCHAR(100) DEFAULT 'Pending Assignment',
        patient_id INT NOT NULL,
        surgeon_name VARCHAR(100) NOT NULL,
        surgery_name VARCHAR(255) NOT NULL,
        scheduled_time DATETIME NOT NULL,
        status VARCHAR(50) DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Lab Tests Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS laboratory_tests (
        lab_id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NULL,
        patient_name VARCHAR(255) NULL,
        phone VARCHAR(50) NULL,
        doctor_id INT NULL,
        test_name VARCHAR(255) NOT NULL,
        cost DECIMAL(10,2) DEFAULT 0.00,
        sample_type ENUM('Hospital Visit', 'Home Collection') NOT NULL DEFAULT 'Hospital Visit',
        collection_address TEXT NULL,
        payment_status ENUM('Unpaid', 'Paid') NOT NULL DEFAULT 'Unpaid',
        payment_method VARCHAR(50) NULL,
        transaction_id VARCHAR(100) NULL,
        scheduled_slot VARCHAR(150) NULL,
        status VARCHAR(50) DEFAULT 'Pending',
        report_file VARCHAR(255) NULL,
        result TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Dynamic Columns Check for laboratory_tests
    $lab_cols_arr = [
        "patient_name VARCHAR(255) NULL",
        "phone VARCHAR(50) NULL",
        "sample_type ENUM('Hospital Visit', 'Home Collection') NOT NULL DEFAULT 'Hospital Visit'",
        "collection_address TEXT NULL",
        "payment_status ENUM('Unpaid', 'Paid') NOT NULL DEFAULT 'Unpaid'",
        "payment_method VARCHAR(50) NULL",
        "transaction_id VARCHAR(100) NULL",
        "scheduled_slot VARCHAR(150) NULL",
        "report_file VARCHAR(255) NULL",
        "result TEXT NULL"
    ];
    foreach ($lab_cols_arr as $col_def) {
        $c_name = explode(' ', trim($col_def))[0];
        $chk_c = $pdo->query("SHOW COLUMNS FROM laboratory_tests LIKE '$c_name'");
        if ($chk_c && $chk_c->rowCount() == 0) {
            $pdo->exec("ALTER TABLE laboratory_tests ADD COLUMN $col_def");
        }
    }
    // Ensure patient_id is nullable in laboratory_tests
    $pdo->exec("ALTER TABLE laboratory_tests MODIFY COLUMN patient_id INT NULL;");

    // Blood Bank Table Check & Auto Creation
    $pdo->exec("CREATE TABLE IF NOT EXISTS blood_bank (
        blood_record_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NULL,
        doctor_id INT NULL,
        user_id INT NULL,
        record_type ENUM('Donor Entry','Blood Bag Stock','Blood Request') NOT NULL,
        blood_group ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        phone VARCHAR(20) NULL,
        quantity_bags INT UNSIGNED NOT NULL DEFAULT 1,
        expiry_date DATE NULL,
        status ENUM('Available','Reserved','Expired','Issued','Pending','Fulfilled') NOT NULL DEFAULT 'Available',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Dynamic Columns Check for Blood Bank Table
    try { $pdo->exec("ALTER TABLE blood_bank ADD COLUMN patient_id INT NULL AFTER blood_record_id;"); } catch (PDOException $ex) {}
    try { $pdo->exec("ALTER TABLE blood_bank ADD COLUMN doctor_id INT NULL AFTER patient_id;"); } catch (PDOException $ex) {}
    try { $pdo->exec("ALTER TABLE blood_bank ADD COLUMN user_id INT NULL AFTER doctor_id;"); } catch (PDOException $ex) {}

    // Staff Dispatches Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_dispatches (
        dispatch_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_type ENUM('Emergency Nursing','Ward Boy Assistance','Cleaning','Equipment Transport') NOT NULL,
        assigned_staff_id INT UNSIGNED NULL,
        location_ward VARCHAR(100) NOT NULL,
        urgency ENUM('High','Normal','Low') NOT NULL DEFAULT 'Normal',
        status ENUM('Unassigned','Assigned','In Progress','Completed') NOT NULL DEFAULT 'Unassigned',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_dispatch_staff FOREIGN KEY (assigned_staff_id) REFERENCES users(user_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Dynamic Columns Check for Blood Bank
    $columns = $pdo->query("SHOW COLUMNS FROM blood_bank")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('patient_id', $columns)) {
        $pdo->exec("ALTER TABLE blood_bank ADD COLUMN patient_id INT NULL AFTER blood_record_id");
    }
    if (!in_array('doctor_id', $columns)) {
        $pdo->exec("ALTER TABLE blood_bank ADD COLUMN doctor_id INT NULL AFTER patient_id");
    }
    if (!in_array('user_id', $columns)) {
        $pdo->exec("ALTER TABLE blood_bank ADD COLUMN user_id INT NULL AFTER doctor_id");
    }

    // Notifications Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        priority VARCHAR(50) DEFAULT 'Normal',
        target_role VARCHAR(100) DEFAULT 'All Staff',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $chk_notif_col = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'target_role'");
    if ($chk_notif_col && $chk_notif_col->rowCount() == 0) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN target_role VARCHAR(100) DEFAULT 'All Staff'");
    }

    // Audit Logs Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        log_id INT AUTO_INCREMENT PRIMARY KEY,
        user_name VARCHAR(255) NOT NULL,
        action TEXT NOT NULL,
        status VARCHAR(50) DEFAULT 'Success',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

} catch (PDOException $e) {}

// Function to log activity
function log_activity($pdo, $user, $action, $status = 'Success') {
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_name, action, status) VALUES (?, ?, ?)");
        $stmt->execute([$user, $action, $status]);
    } catch (PDOException $e) {}
}

// Function Helper to Send SMS Notification
function send_sms_notification($phone_number, $message_text) {
    if (empty($phone_number)) return false;
    return true; 
}

// Function Helper to Send Email Notification
function send_email_notification($recipient_email, $subject, $body_text) {
    if (empty($recipient_email)) return false;
    $headers  = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/plain;charset=UTF-8" . "\r\n";
    $headers .= "From: Smart Hospital ERP <no-reply@hospitalsystem.com>\r\n";
    return @mail($recipient_email, "=?UTF-8?B?".base64_encode($subject)."?=", $body_text, $headers);
}

// Active Tab Determination
$default_tab = 'ot';
if ($role == 'Lab Technician') {
    $default_tab = 'lab';
} elseif ($role == 'Nurse') {
    $default_tab = 'dispatch';
} elseif (in_array($role, ['Patient', 'Doctor', 'Receptionist', 'Pharmacist'])) {
    $default_tab = 'blood';
}

$active_tab = $_GET['tab'] ?? $default_tab;

$message = "";
$message_type = "";

// Fetch merchant numbers for payment
$bkash_num = '01700000000';
$nagad_num = '01700000000';
try {
    $stmt_set = $pdo->query("SELECT setting_key, setting_value FROM hospital_settings");
    if ($stmt_set) {
        while ($s_row = $stmt_set->fetch(PDO::FETCH_ASSOC)) {
            if ($s_row['setting_key'] == 'bkash_number') $bkash_num = $s_row['setting_value'];
            if ($s_row['setting_key'] == 'nagad_number') $nagad_num = $s_row['setting_value'];
        }
    }
} catch (PDOException $e) {}

// -------------------------------------------------------------
// POST ACTIONS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // OT Booking / Request (Doctor or Admin)
    if (isset($_POST['action']) && $_POST['action'] == 'book_ot') {
        $active_tab = 'ot';
        if (in_array($role, ['Admin', 'Doctor'])) {
            $pid      = intval($_POST['patient_id'] ?? 0);
            $doc_name = trim($_POST['surgeon_name'] ?? $full_name);
            $surgery  = trim($_POST['surgery_name'] ?? '');
            $s_time   = $_POST['scheduled_time'] ?? '';

            if ($role == 'Admin') {
                $room   = trim($_POST['room_number'] ?? 'OT-01');
                $status = 'Scheduled';
            } else {
                $room   = 'Pending Assignment';
                $status = 'Pending';
            }

            if ($pid > 0 && !empty($surgery) && !empty($s_time)) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO ot_schedules (room_number, patient_id, surgeon_name, surgery_name, scheduled_time, status) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$room, $pid, $doc_name, $surgery, $s_time, $status]);
                    $message = ($role == 'Doctor') ? "OT request submitted! Admin will assign the room shortly." : "OT Scheduled successfully!";
                    $message_type = "success";

                    log_activity($pdo, $full_name . " ($role)", "Booked/Requested OT for Patient ID #$pid");
                } catch (PDOException $e) {
                    $message = "Error booking OT: " . $e->getMessage();
                    $message_type = "danger";
                }
            } else {
                $message = "Please fill all required OT fields!";
                $message_type = "warning";
            }
        }
    }

    // OT Room Assignment & Status Update (Admin Only)
    if (isset($_POST['action']) && $_POST['action'] == 'update_ot_room' && $role == 'Admin') {
        $active_tab = 'ot';
        $ot_id   = intval($_POST['ot_id'] ?? 0);
        $room_no = trim($_POST['room_number'] ?? '');
        $status  = $_POST['ot_status'] ?? 'Scheduled';

        if ($ot_id > 0 && !empty($room_no)) {
            try {
                $stmt = $pdo->prepare("UPDATE ot_schedules SET room_number = ?, status = ? WHERE ot_id = ?");
                $stmt->execute([$room_no, $status, $ot_id]);
                $message = "OT Room assigned as '$room_no' and status updated to '$status'!";
                $message_type = "success";

                log_activity($pdo, $full_name . " ($role)", "Assigned OT Room $room_no for OT #$ot_id");
            } catch (PDOException $e) {
                $message = "Error updating OT room: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }

    // Patient Request Lab Test
    if (isset($_POST['action']) && $_POST['action'] == 'patient_request_test') {
        $active_tab = 'lab';
        if ($role == 'Patient') {
            $test_name   = trim($_POST['test_name'] ?? '');
            $cost        = floatval($_POST['cost'] ?? 0);
            $sample_type = $_POST['sample_type'] ?? 'Hospital Visit';
            $address     = trim($_POST['collection_address'] ?? '');
            $pay_method  = $_POST['payment_method'] ?? 'Cash at Counter';
            $trx_id      = trim($_POST['transaction_id'] ?? '');

            $is_home_service = ($sample_type == 'Home Collection');
            $is_cash = in_array($pay_method, ['Cash at Counter', 'Cash at Hospital Counter (Offline)', 'Cash']);

            // Patient ID Ensure/Check
            if (empty($patient_id)) {
                try {
                    $stmt_cp = $pdo->prepare("SELECT patient_id FROM patients WHERE user_id = ? OR (phone = ? AND phone != '') LIMIT 1");
                    $stmt_cp->execute([$user_id, $u_phone]);
                    $cp_row = $stmt_cp->fetch(PDO::FETCH_ASSOC);
                    if ($cp_row) {
                        $patient_id = $cp_row['patient_id'];
                    } else {
                        $stmt_ins_p = $pdo->prepare("INSERT INTO patients (user_id, full_name, phone) VALUES (?, ?, ?)");
                        $stmt_ins_p->execute([$user_id, $full_name, $u_phone]);
                        $patient_id = $pdo->lastInsertId();
                    }
                } catch (PDOException $e) {}
            }

            if ($is_home_service && $is_cash) {
                $message = "⚠️ ল্যাব টেস্ট হোম কালেকশনের জন্য অবশ্যই বিকাশ/নগদে অনলাইন পেমেন্ট করতে হবে! (হোম চার্জ ৳২০০ সহ)";
                $message_type = "danger";
            } elseif (!$is_cash && empty($trx_id)) {
                $message = "⚠️ অনলাইন পেমেন্টের ট্রানজেকশন আইডি (TrxID) প্রদান করুন!";
                $message_type = "warning";
            } else {
                try {
                    $pay_st     = $is_cash ? 'Unpaid' : 'Paid';
                    $inv_pay_st = $is_cash ? 'Due' : 'Paid';

                    $doc_id_val = !empty($doctor_id) ? $doctor_id : null;

                    $ins = $pdo->prepare("INSERT INTO laboratory_tests (patient_id, patient_name, phone, doctor_id, test_name, cost, sample_type, collection_address, payment_status, payment_method, transaction_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
                    $ins->execute([$patient_id, $full_name, $u_phone, $doc_id_val, $test_name, $cost, $sample_type, $address, $pay_st, $pay_method, $trx_id]);
                    $last_lab_id = $pdo->lastInsertId();

                    // Billing Invoice Entry
                    $stmt_p_info = $pdo->prepare("SELECT full_name, phone FROM patients WHERE patient_id = ?");
                    $stmt_p_info->execute([$patient_id]);
                    $p_data = $stmt_p_info->fetch(PDO::FETCH_ASSOC);

                    $test_title = "Lab Test ($sample_type): " . $test_name . " (#LAB-" . $last_lab_id . ")";
                    $json_services = json_encode([
                        ["service" => $test_title, "quantity" => 1, "unit_price" => $cost, "total" => $cost]
                    ], JSON_UNESCAPED_UNICODE);

                    $stmt_inv = $pdo->prepare("INSERT INTO billing_invoices (patient_id, patient_name, phone, itemized_services, total_amount, discount, net_payable, payment_status, invoice_date) VALUES (?, ?, ?, ?, ?, 0.00, ?, ?, NOW())");
                    $stmt_inv->execute([$patient_id, $p_data['full_name'] ?? $full_name, $p_data['phone'] ?? $u_phone, $json_services, $cost, $cost, $inv_pay_st]);
                    $last_inv_id = $pdo->lastInsertId();

                    if ($inv_pay_st == 'Paid') {
                        $acc_head = "Lab Test Online Collection (#INV-" . $last_inv_id . ") - " . ($p_data['full_name'] ?? $full_name);
                        $pdo->prepare("INSERT INTO finance_transactions (transaction_type, account_head, amount) VALUES ('Income', ?, ?)")->execute([$acc_head, $cost]);
                    }

                    $message = "Lab Test requested successfully! " . ($is_cash ? "Status: Unpaid (Please pay ৳" . number_format($cost,2) . " at hospital counter)" : "Payment Received.");
                    $message_type = "success";
                } catch (PDOException $e) {
                    $message = "Error: " . $e->getMessage();
                    $message_type = "danger";
                }
            }
        }
    }

    // Lab Technician / Admin Set Schedule & Slot
    if (isset($_POST['action']) && $_POST['action'] == 'set_schedule_slot' && in_array($role, ['Admin', 'Lab Technician'])) {
        $active_tab = 'lab';
        $t_id  = intval($_POST['lab_id'] ?? 0);
        
        $s_date   = $_POST['slot_date'] ?? date('Y-m-d');
        $s_time   = $_POST['slot_time'] ?? '09:00 AM';
        $s_serial = $_POST['serial_no'] ?? 'Serial #01';
        
        $slot  = trim("$s_date $s_time ($s_serial)");
        
        $p_st  = $_POST['payment_status'] ?? 'Paid';
        $t_st  = $_POST['status'] ?? 'Scheduled';

        if ($t_id > 0) {
            try {
                // 1. Lab tests table update
                $pdo->prepare("UPDATE laboratory_tests SET scheduled_slot = ?, payment_status = ?, status = ? WHERE lab_id = ?")->execute([$slot, $p_st, $t_st, $t_id]);
                
                // 2. Sync Payment 'Paid' to billing_invoices & finance_transactions
                if ($p_st == 'Paid') {
                    $stmt_lab = $pdo->prepare("SELECT lt.patient_id, lt.test_name, lt.cost, p.full_name, p.phone FROM laboratory_tests lt LEFT JOIN patients p ON lt.patient_id = p.patient_id WHERE lt.lab_id = ?");
                    $stmt_lab->execute([$t_id]);
                    $l_data = $stmt_lab->fetch(PDO::FETCH_ASSOC);

                    if ($l_data) {
                        $p_id       = $l_data['patient_id'];
                        $p_name     = $l_data['full_name'] ?? 'Walk-in Patient';
                        $p_phone    = $l_data['phone'] ?? '';
                        $test_title = "Lab Test: " . $l_data['test_name'] . " (#LAB-" . $t_id . ")";
                        $amount     = floatval($l_data['cost']);

                        $services_arr = [
                            [
                                "service"    => $test_title,
                                "quantity"   => 1,
                                "unit_price" => $amount,
                                "total"      => $amount
                            ]
                        ];
                        $json_services = json_encode($services_arr, JSON_UNESCAPED_UNICODE);

                        $chk_inv = $pdo->prepare("SELECT COUNT(*) FROM billing_invoices WHERE patient_id = ? AND itemized_services LIKE ?");
                        $chk_inv->execute([$p_id, "%#LAB-$t_id%"]);
                        
                        if ($chk_inv->fetchColumn() == 0) {
                            $stmt_inv = $pdo->prepare("INSERT INTO billing_invoices (patient_id, patient_name, phone, itemized_services, total_amount, discount, net_payable, payment_status, invoice_date) VALUES (?, ?, ?, ?, ?, 0.00, ?, 'Paid', NOW())");
                            $stmt_inv->execute([$p_id, $p_name, $p_phone, $json_services, $amount, $amount]);
                            $last_inv_id = $pdo->lastInsertId();

                            $acc_head = "Hospital Lab Test Collection (#INV-" . $last_inv_id . ") - " . $p_name;
                            $ins_inc = $pdo->prepare("INSERT INTO finance_transactions (transaction_type, account_head, amount) VALUES ('Income', ?, ?)");
                            $ins_inc->execute([$acc_head, $amount]);
                        }
                    }
                }

                $message = "Test schedule, serial & billing record updated successfully!";
                $message_type = "success";

                log_activity($pdo, $full_name . " ($role)", "Updated Lab Test #LAB-$t_id schedule/billing status to $t_st");
            } catch (PDOException $e) {
                $message = "Error: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }

    // Lab Technician / Admin Text Result Entry & Update
    if (isset($_POST['action']) && $_POST['action'] == 'update_lab_result' && in_array($role, ['Admin', 'Lab Technician'])) {
        $active_tab = 'lab';
        $t_id   = intval($_POST['lab_id'] ?? 0);
        $result = trim($_POST['lab_result'] ?? '');
        $status = $_POST['status'] ?? 'Completed';

        if ($t_id > 0 && !empty($result)) {
            try {
                $stmt = $pdo->prepare("UPDATE laboratory_tests SET result = ?, status = ? WHERE lab_id = ?");
                $stmt->execute([$result, $status, $t_id]);

                $message = "Lab Test Report result updated successfully!";
                $message_type = "success";

                log_activity($pdo, $full_name . " ($role)", "Updated Lab Result Text for #LAB-$t_id");
            } catch (PDOException $e) {
                $message = "Error updating lab result: " . $e->getMessage();
                $message_type = "danger";
            }
        } else {
            $message = "Please write result details before saving!";
            $message_type = "warning";
        }
    }

    // Lab Technician / Admin Upload PDF Report
    if (isset($_POST['action']) && $_POST['action'] == 'upload_pdf_report' && in_array($role, ['Admin', 'Lab Technician'])) {
        $active_tab = 'lab';
        $t_id = intval($_POST['lab_id'] ?? 0);

        if ($t_id > 0 && isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] == 0) {
            $allowed = ['pdf'];
            $filename = $_FILES['pdf_file']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if (in_array($ext, $allowed)) {
                $target_dir = "uploads/lab_reports/";
                if (!file_exists($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }
                $new_filename = "report_lab_" . $t_id . "_" . time() . "." . $ext;
                $target_file = $target_dir . $new_filename;

                if (move_uploaded_file($_FILES['pdf_file']['tmp_name'], $target_file)) {
                    try {
                        $stmt = $pdo->prepare("UPDATE laboratory_tests SET report_file = ?, status = 'Completed' WHERE lab_id = ?");
                        $stmt->execute([$target_file, $t_id]);

                        $message = "PDF Report uploaded and test marked as Completed successfully!";
                        $message_type = "success";

                        log_activity($pdo, $full_name . " ($role)", "Uploaded PDF report for Lab Test #LAB-$t_id");
                    } catch (PDOException $e) {
                        $message = "Database Error: " . $e->getMessage();
                        $message_type = "danger";
                    }
                } else {
                    $message = "Failed to upload PDF file to server folder!";
                    $message_type = "danger";
                }
            } else {
                $message = "Only PDF format files are allowed!";
                $message_type = "warning";
            }
        } else {
            $message = "Please select a valid PDF file!";
            $message_type = "warning";
        }
    }

    // Staff Dispatch Add Task
    if (isset($_POST['action']) && $_POST['action'] == 'add_dispatch_task') {
        $active_tab = 'dispatch';
        if (in_array($role, ['Admin', 'Nurse'])) {
            $task_type = $_POST['task_type'] ?? '';
            $location  = trim($_POST['location_ward'] ?? '');
            $urgency   = $_POST['urgency'] ?? 'Normal';
            $staff_id  = !empty($_POST['assigned_staff_id']) ? intval($_POST['assigned_staff_id']) : NULL;
            $status    = $staff_id ? 'Assigned' : 'Unassigned';

            if (!empty($task_type) && !empty($location)) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO staff_dispatches (task_type, assigned_staff_id, location_ward, urgency, status) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$task_type, $staff_id, $location, $urgency, $status]);
                    $message = "Staff dispatch task created successfully!";
                    $message_type = "success";

                    log_activity($pdo, $full_name . " ($role)", "Created Dispatch Task: $task_type at $location");
                } catch (PDOException $e) {
                    $message = "Error creating dispatch: " . $e->getMessage();
                    $message_type = "danger";
                }
            }
        }
    }

    // Staff Dispatch Update Status & Assignment
    if (isset($_POST['action']) && $_POST['action'] == 'update_dispatch') {
        $active_tab = 'dispatch';
        if (in_array($role, ['Admin', 'Nurse'])) {
            $dispatch_id = intval($_POST['dispatch_id'] ?? 0);
            $staff_id    = !empty($_POST['assigned_staff_id']) ? intval($_POST['assigned_staff_id']) : NULL;
            $d_status    = $_POST['status'] ?? 'Unassigned';

            if ($dispatch_id > 0) {
                try {
                    $stmt = $pdo->prepare("UPDATE staff_dispatches SET assigned_staff_id = ?, status = ? WHERE dispatch_id = ?");
                    $stmt->execute([$staff_id, $d_status, $dispatch_id]);
                    $message = "Dispatch task updated successfully!";
                    $message_type = "success";

                    log_activity($pdo, $full_name . " ($role)", "Updated Dispatch Task #$dispatch_id");
                } catch (PDOException $e) {
                    $message = "Error updating dispatch: " . $e->getMessage();
                    $message_type = "danger";
                }
            }
        }
    }

    // Patient Staff Call Action
    if (isset($_POST['action']) && $_POST['action'] == 'patient_staff_call') {
        $active_tab = 'blood';
        if ($role == 'Patient') {
            $task_type = $_POST['task_type'] ?? 'Emergency Nursing';
            $urgency   = $_POST['urgency'] ?? 'High';
            
            $patient_ward = 'Cabin / Ward (Patient ID: #' . $patient_id . ')';
            try {
                $stmt_w = $pdo->prepare("SELECT c.cabin_number FROM assigned_beds ab JOIN cabins c ON ab.cabin_id = c.cabin_id WHERE ab.patient_id = ? AND ab.status = 'Occupied' LIMIT 1");
                $stmt_w->execute([$patient_id]);
                $c_row = $stmt_w->fetch(PDO::FETCH_ASSOC);
                if ($c_row) {
                    $patient_ward = "Cabin No: " . $c_row['cabin_number'];
                }
            } catch (PDOException $e) {}

            try {
                $stmt = $pdo->prepare("INSERT INTO staff_dispatches (task_type, assigned_staff_id, location_ward, urgency, status) VALUES (?, NULL, ?, ?, 'Unassigned')");
                $stmt->execute([$task_type, $patient_ward, $urgency]);
                $message = "Staff called successfully! A nurse or staff member will assist you shortly.";
                $message_type = "success";

                log_activity($pdo, $full_name . " ($role)", "Requested Staff Call ($task_type) for $patient_ward");
            } catch (PDOException $e) {
                $message = "Error calling staff: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }

    // Blood Request / Stock Add Action
    if (isset($_POST['action']) && $_POST['action'] == 'add_blood_record') {
        $active_tab = 'blood';
        
        $rec_type = $_POST['record_type'] ?? (in_array($role, ['Admin', 'Receptionist']) ? 'Blood Bag Stock' : 'Blood Request');
        $b_group  = $_POST['blood_group'] ?? '';
        $name     = trim($_POST['full_name'] ?? $full_name);
        $phone    = trim($_POST['phone'] ?? $u_phone);
        $bags     = intval($_POST['quantity_bags'] ?? 1);
        $exp_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : NULL;
        $b_status = (in_array($role, ['Admin', 'Receptionist'])) ? ($_POST['status'] ?? 'Available') : 'Pending';

        if (!empty($b_group) && !empty($name)) {
            try {
                $stmt_ins = $pdo->prepare("INSERT INTO blood_bank (patient_id, doctor_id, user_id, record_type, blood_group, full_name, phone, quantity_bags, expiry_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_ins->execute([$patient_id, $doctor_id, $user_id, $rec_type, $b_group, $name, $phone, $bags, $exp_date, $b_status]);

                $message = ($rec_type == 'Blood Request') ? "Blood request submitted successfully! Admin will process it shortly." : "Blood record added successfully!";
                $message_type = "success";

                log_activity($pdo, $full_name . " ($role)", "Submitted Blood Record/Request ($rec_type) for $b_group - $bags Bags");
            } catch (PDOException $e) {
                $message = "Error: " . $e->getMessage();
                $message_type = "danger";
            }
        } else {
            $message = "Please select a Blood Group and provide your name!";
            $message_type = "warning";
        }
    }

    // Blood Request Status Accept/Update
    if (isset($_POST['action']) && $_POST['action'] == 'update_blood_status') {
        $active_tab = 'blood';
        if (in_array($role, ['Admin', 'Receptionist'])) {
            $rec_id   = intval($_POST['blood_record_id'] ?? 0);
            $n_status = $_POST['status'] ?? 'Fulfilled';

            if ($rec_id > 0) {
                try {
                    $stmt_up = $pdo->prepare("UPDATE blood_bank SET status = ? WHERE blood_record_id = ?");
                    $stmt_up->execute([$n_status, $rec_id]);

                    $message = "Blood Record #REC-$rec_id status updated to '$n_status' successfully!";
                    $message_type = "success";

                    log_activity($pdo, $full_name . " ($role)", "Updated Blood Record #REC-$rec_id status to $n_status");
                } catch (PDOException $e) {
                    $message = "Error updating status: " . $e->getMessage();
                    $message_type = "danger";
                }
            }
        }
    }

    // BROADCAST NOTIFICATION
    if (isset($_POST['action']) && $_POST['action'] == 'add_notification') {
        $active_tab = 'notifications';
        if ($role == 'Admin') {
            $title       = trim($_POST['title'] ?? '');
            $not_msg     = trim($_POST['not_message'] ?? '');
            $priority    = $_POST['priority'] ?? 'Normal';
            $target_role = $_POST['target_role'] ?? 'All Staff';
            $send_sms    = isset($_POST['send_sms']) ? 1 : 0;
            $send_email  = isset($_POST['send_email']) ? 1 : 0;

            if (!empty($title) && !empty($not_msg)) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO notifications (title, message, priority, target_role) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$title, $not_msg, $priority, $target_role]);

                    $recipients = [];
                    if ($target_role == 'All Staff') {
                        $stmt_users = $pdo->query("SELECT full_name, phone_number, email FROM users WHERE role != 'Patient'");
                    } elseif ($target_role == 'All Users (Including Patients)') {
                        $stmt_users = $pdo->query("SELECT full_name, phone_number, email FROM users");
                    } else {
                        $stmt_users = $pdo->prepare("SELECT full_name, phone_number, email FROM users WHERE role = ? OR role LIKE ?");
                        $stmt_users->execute([$target_role, '%' . $target_role . '%']);
                    }

                    if ($stmt_users) {
                        $recipients = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
                    }

                    $sms_count = 0;
                    $email_count = 0;

                    foreach ($recipients as $recipient) {
                        $phone = trim($recipient['phone_number'] ?? '');
                        $email = trim($recipient['email'] ?? '');

                        if ($send_sms && !empty($phone)) {
                            send_sms_notification($phone, "[$title]\n$not_msg");
                            $sms_count++;
                        }

                        if ($send_email && !empty($email)) {
                            send_email_notification($email, $title, $not_msg);
                            $email_count++;
                        }
                    }

                    $message = "Notification broadcasted successfully for '$target_role'!";
                    if ($send_sms) $message .= " Sent SMS to $sms_count user(s).";
                    if ($send_email) $message .= " Sent Email to $email_count user(s).";

                    $message_type = "success";

                    log_activity($pdo, $full_name . " ($role)", "Created Broadcast Notification: '$title'");
                } catch (PDOException $e) {
                    $message = "Error posting notification: " . $e->getMessage();
                    $message_type = "danger";
                }
            } else {
                $message = "Please fill Title and Message details!";
                $message_type = "warning";
            }
        }
    }
}

// DATA FETCHING
$patients = [];
try {
    $stmt = $pdo->query("SELECT patient_id, full_name FROM patients ORDER BY full_name ASC");
    if ($stmt) $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$ot_schedules = [];
try {
    $stmt = $pdo->query("SELECT ot.*, p.full_name AS patient_name FROM ot_schedules ot LEFT JOIN patients p ON ot.patient_id = p.patient_id ORDER BY ot.ot_id DESC");
    if ($stmt) $ot_schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Fetch Lab Tests (Reliable fetch query for all roles)
$lab_tests = [];
try {
    $sql_lab = "SELECT lt.*, 
                       COALESCE(p.full_name, lt.patient_name, 'Patient') AS display_patient_name, 
                       COALESCE(p.phone, lt.phone, '') AS display_patient_phone 
                FROM laboratory_tests lt 
                LEFT JOIN patients p ON lt.patient_id = p.patient_id ";
    if ($role == 'Patient') {
        $stmt_l = $pdo->prepare($sql_lab . "WHERE lt.patient_id = ? OR (p.user_id = ?) OR (lt.phone = ? AND lt.phone != '') ORDER BY lt.created_at DESC");
        $stmt_l->execute([$patient_id, $user_id, $u_phone]);
    } else {
        $stmt_l = $pdo->query($sql_lab . "ORDER BY lt.created_at DESC");
    }
    if ($stmt_l) $lab_tests = $stmt_l->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    try {
        $stmt_l = $pdo->query("SELECT *, patient_name AS display_patient_name, phone AS display_patient_phone FROM laboratory_tests ORDER BY lab_id DESC");
        if ($stmt_l) $lab_tests = $stmt_l->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $ex) {}
}

// Staff list for dispatch assignment (Updated for Nurse, Ward Boy, Cleaner & Support Staff)
$staff_list = [];
try {
    $stmt = $pdo->query("SELECT user_id, full_name, role FROM users WHERE role IN ('Nurse', 'Ward Boy', 'Cleaner', 'Support Staff') AND status = 'Active' ORDER BY full_name ASC");
    if ($stmt) $staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Staff Dispatches list
$dispatches_list = [];
try {
    $stmt = $pdo->query("SELECT sd.*, u.full_name AS staff_name FROM staff_dispatches sd LEFT JOIN users u ON sd.assigned_staff_id = u.user_id ORDER BY sd.dispatch_id DESC");
    if ($stmt) $dispatches_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Blood stock calculation
$blood_stock = [];
$all_groups  = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

foreach ($all_groups as $index => $g) {
    $total_stock  = 0;
    $total_issued = 0;
    try {
        $stmt_s = $pdo->prepare("SELECT COALESCE(SUM(quantity_bags), 0) FROM blood_bank WHERE blood_group = ? AND record_type IN ('Blood Bag Stock', 'Donor Entry') AND status != 'Expired'");
        $stmt_s->execute([$g]);
        $total_stock = intval($stmt_s->fetchColumn());

        $stmt_i = $pdo->prepare("SELECT COALESCE(SUM(quantity_bags), 0) FROM blood_bank WHERE blood_group = ? AND record_type = 'Blood Request' AND status IN ('Issued', 'Approved', 'Accepted', 'Fulfilled')");
        $stmt_i->execute([$g]);
        $total_issued = intval($stmt_i->fetchColumn());
    } catch (PDOException $e) {}

    $available = max(0, $total_stock - $total_issued);

    $blood_stock[] = [
        'id'          => $index + 1,
        'blood_group' => $g,
        'bags_count'  => $available
    ];
}

$all_blood_records = [];
try {
    $stmt_all = $pdo->query("SELECT * FROM blood_bank ORDER BY blood_record_id DESC");
    if ($stmt_all) {
        $all_blood_records = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {}

$my_blood_requests = [];
try {
    $sql_my = "SELECT * FROM blood_bank WHERE record_type = 'Blood Request' AND (";
    $conds  = ["user_id = :uid"];
    $params = [':uid' => $user_id];

    if (!empty($patient_id)) {
        $conds[] = "patient_id = :pid";
        $params[':pid'] = $patient_id;
    }
    if (!empty($doctor_id)) {
        $conds[] = "doctor_id = :did";
        $params[':did'] = $doctor_id;
    }
    if (!empty($u_phone)) {
        $conds[] = "phone = :phone";
        $params[':phone'] = $u_phone;
    }
    if (!empty($full_name)) {
        $conds[] = "full_name LIKE :fname";
        $params[':fname'] = '%' . $full_name . '%';
    }

    $sql_my .= implode(" OR ", $conds) . ") ORDER BY blood_record_id DESC";

    $stmt_my = $pdo->prepare($sql_my);
    $stmt_my->execute($params);
    $my_blood_requests = $stmt_my->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$notifications_list = [];
try {
    $stmt = $pdo->query("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 15");
    if ($stmt) $notifications_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$audit_logs_list = [];
try {
    $stmt = $pdo->query("SELECT * FROM audit_logs ORDER BY log_id DESC LIMIT 25");
    if ($stmt) $audit_logs_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Modules - Smart Hospital ERP</title>
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
    <!-- Sidebar Controls -->
    <div class="sidebar p-3">
        <h4 class="text-center text-white mb-4"><i class="bi bi-hospital"></i> Hospital ERP</h4>
        <hr class="text-secondary">
        
        <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>

        <?php if ($role == 'Admin'): ?>
            <div class="nav-category">Patient & Frontdesk</div>
            <a href="patient_registration.php"><i class="bi bi-person-plus"></i> Patient Registration</a>
            <a href="patient_list.php"><i class="bi bi-people"></i> Patient List</a>
            <a href="book_appointment.php"><i class="bi bi-calendar-check"></i> Appointments</a>
            <a href="cabins.php"><i class="bi bi-building"></i> Cabins & Beds</a>
            <a href="assign_bed.php"><i class="bi bi-journal-plus"></i> Assign Bed</a>

            <div class="nav-category">Hospital Management</div>
            <a href="doctor_list.php"><i class="bi bi-heart-pulse"></i> Doctors Management</a>
            <a href="pharmacy.php"><i class="bi bi-capsule"></i> Pharmacy & Inventory</a>
            <a href="emergency_dispatch.php"><i class="bi bi-truck"></i> Emergency Dispatch</a>
            
            <div class="nav-category">Accounts & Finance</div>
            <a href="billing.php"><i class="bi bi-receipt"></i> Billing & Invoices</a>
            <a href="finance.php"><i class="bi bi-cash-coin"></i> Payroll & Finance</a>

            <div class="nav-category">Core Engine</div>
            <a href="modules.php" class="active"><i class="bi bi-cpu"></i> Modules</a>
        <?php elseif ($role == 'Pharmacist'): ?>
            <div class="nav-category">Pharmacy Station</div>
            <a href="pharmacy.php"><i class="bi bi-capsule text-warning"></i> Pharmacy & Inventory</a>
            <a href="patient_list.php"><i class="bi bi-people"></i> Patient Directory</a>
            <a href="edit_profile.php"><i class="bi bi-person-badge"></i> Edit My Profile</a>
        <?php elseif ($role == 'Nurse'): ?>
            <div class="nav-category">Nurse Station</div>
            <a href="cabins.php"><i class="bi bi-door-open-fill"></i> Cabin & Bed Status</a>
            <a href="modules.php?tab=dispatch" class="active"><i class="bi bi-truck-flatbed text-warning"></i> Staff Dispatch</a>
            <a href="patient_list.php"><i class="bi bi-person-lines-fill"></i> Patient Directory</a>
            <a href="appointments.php"><i class="bi bi-journal-medical"></i> Doctor Schedule</a>
            <a href="edit_profile.php"><i class="bi bi-person-badge"></i> Edit My Profile</a>
        <?php elseif ($role == 'Receptionist'): ?>
            <div class="nav-category">Patient & Frontdesk</div>
            <a href="patient_registration.php"><i class="bi bi-person-plus"></i> Patient Registration</a>
            <a href="patient_list.php"><i class="bi bi-people"></i> Patient Directory</a>
            <a href="book_appointment.php"><i class="bi bi-calendar-check"></i> Appointments</a>
            <a href="cabins.php"><i class="bi bi-building"></i> Cabins & Beds</a>
            <a href="assign_bed.php"><i class="bi bi-journal-plus"></i> Assign Bed</a>
            <a href="modules.php?tab=blood"><i class="bi bi-droplet-fill text-danger"></i> Blood Bank Mgmt</a>
            <a href="billing.php"><i class="bi bi-receipt"></i> Billing & Invoices</a>
            <a href="edit_profile.php"><i class="bi bi-person-badge"></i> Edit My Profile</a>
        <?php elseif ($role == 'Lab Technician'): ?>
            <div class="nav-category">Lab Station</div>
            <a href="modules.php?tab=lab" class="active"><i class="bi bi-eyedropper text-danger"></i> Modules</a>
            <a href="patient_list.php"><i class="bi bi-people"></i> Patient Directory</a>
            <a href="edit_profile.php"><i class="bi bi-person-badge"></i> Edit My Profile</a>
        <?php elseif ($role == 'Doctor'): ?>
            <div class="nav-category">Doctor Portal</div>
            <a href="appointments.php"><i class="bi bi-journal-medical"></i> My Appointments</a>
            <a href="add_prescription.php"><i class="bi bi-prescription2"></i> Write Prescription</a>
            <a href="modules.php?tab=lab"><i class="bi bi-eyedropper text-primary"></i> Lab Reports</a>
            <a href="modules.php?tab=blood"><i class="bi bi-droplet text-danger"></i> Blood Request</a>
            <a href="edit_profile.php"><i class="bi bi-person-badge"></i> Edit My Profile</a>
        <?php else: ?>
            <div class="nav-category">Patient Portal</div>
            <a href="modules.php?tab=lab" class="<?= $active_tab == 'lab' ? 'active' : ''; ?>"><i class="bi bi-eyedropper text-primary"></i> Lab Tests</a>
            <a href="modules.php?tab=blood" class="<?= $active_tab == 'blood' ? 'active' : ''; ?>"><i class="bi bi-bell-fill text-warning"></i> Call Staff / Blood</a>
            <a href="edit_profile.php"><i class="bi bi-person-badge"></i> Edit My Profile</a>
        <?php endif; ?>

        <hr class="text-secondary mt-4">
        <a href="logout.php" class="text-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>

    <!-- Main Section -->
    <div class="w-100 p-4">
        <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded shadow-sm">
            <h4 class="mb-0 text-primary fw-bold"><i class="bi bi-cpu me-2"></i>Hospital Core Modules Management</h4>
            <span class="text-muted">User: <strong><?= htmlspecialchars($full_name); ?></strong> (<?= htmlspecialchars($role); ?>)</span>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $message_type; ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Module Tabs Navigation -->
        <ul class="nav nav-pills mb-4 bg-white p-2 rounded shadow-sm">
            <?php if (in_array($role, ['Admin', 'Doctor'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab == 'ot' ? 'active' : ''; ?>" href="modules.php?tab=ot"><i class="bi bi-scissors me-1"></i> 1. OT Mgmt</a>
                </li>
            <?php endif; ?>

            <?php if (in_array($role, ['Admin', 'Doctor', 'Lab Technician', 'Patient'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab == 'lab' ? 'active' : ''; ?>" href="modules.php?tab=lab"><i class="bi bi-eyedropper me-1"></i> 2. Lab Tests</a>
                </li>
            <?php endif; ?>

            <!-- Blood Bank / Patient Portal Tab -->
            <li class="nav-item">
                <a class="nav-link <?= $active_tab == 'blood' ? 'active' : ''; ?>" href="modules.php?tab=blood"><i class="bi bi-droplet me-1"></i> 3. Blood Bank / Services</a>
            </li>

            <!-- Staff Dispatch Tab -->
            <?php if (in_array($role, ['Admin', 'Nurse'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab == 'dispatch' ? 'active' : ''; ?>" href="modules.php?tab=dispatch"><i class="bi bi-truck-flatbed me-1"></i> 4. Staff Dispatch</a>
                </li>
            <?php endif; ?>

            <?php if ($role == 'Admin'): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab == 'notifications' ? 'active' : ''; ?>" href="modules.php?tab=notifications"><i class="bi bi-bell me-1"></i> 5. Notifications</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab == 'audit' ? 'active' : ''; ?>" href="modules.php?tab=audit"><i class="bi bi-shield-check me-1"></i> 6. Audit Logs</a>
                </li>
            <?php endif; ?>
        </ul>

        <!-- TAB 1: OT MANAGEMENT -->
        <?php if ($active_tab == 'ot' && in_array($role, ['Admin', 'Doctor'])): ?>
            <div class="card border-0 shadow-sm p-4 mb-4">
                <h5 class="fw-bold text-danger mb-3"><i class="bi bi-scissors me-2"></i>Operation Theatre (OT) Scheduling</h5>
                <form method="POST" action="modules.php?tab=ot" class="row g-3 mb-4">
                    <input type="hidden" name="action" value="book_ot">
                    
                    <?php if ($role == 'Admin'): ?>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Room Number</label>
                            <input type="text" name="room_number" class="form-control" placeholder="Room No (e.g. OT-01)" required>
                        </div>
                    <?php endif; ?>

                    <div class="<?= $role == 'Admin' ? 'col-md-3' : 'col-md-4'; ?>">
                        <label class="form-label fw-bold">Select Patient</label>
                        <select name="patient_id" class="form-select" required>
                            <option value="">Select Patient</option>
                            <?php foreach ($patients as $p): ?>
                                <option value="<?= $p['patient_id']; ?>"><?= htmlspecialchars($p['full_name']); ?> (#PAT-<?= $p['patient_id']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="<?= $role == 'Admin' ? 'col-md-3' : 'col-md-4'; ?>">
                        <label class="form-label fw-bold">Surgeon Name</label>
                        <input type="text" name="surgeon_name" class="form-control" value="<?= $role == 'Doctor' ? 'Dr. ' . htmlspecialchars($full_name) : ''; ?>" placeholder="Surgeon Name" required>
                    </div>

                    <div class="<?= $role == 'Admin' ? 'col-md-3' : 'col-md-4'; ?>">
                        <label class="form-label fw-bold">Surgery Name</label>
                        <input type="text" name="surgery_name" class="form-control" placeholder="Surgery Name" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-bold">Schedule Time</label>
                        <input type="datetime-local" name="scheduled_time" class="form-control" required>
                    </div>

                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-danger w-100 fw-bold">
                            <?= $role == 'Admin' ? 'Book OT Room' : 'Request OT Booking'; ?>
                        </button>
                    </div>
                </form>

                <h6 class="fw-bold text-secondary">Scheduled Surgeries</h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>OT#</th>
                                <th>Room</th>
                                <th>Patient</th>
                                <th>Surgery Name</th>
                                <th>Surgeon</th>
                                <th>Schedule Time</th>
                                <th>Status</th>
                                <?php if ($role == 'Admin'): ?>
                                    <th class="text-center">Action</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($ot_schedules)): foreach ($ot_schedules as $ot): ?>
                                <tr>
                                    <td>#OT-<?= $ot['ot_id']; ?></td>
                                    <td>
                                        <span class="badge <?= $ot['room_number'] == 'Pending Assignment' ? 'bg-warning text-dark' : 'bg-secondary'; ?>">
                                            <?= htmlspecialchars($ot['room_number']); ?>
                                        </span>
                                    </td>
                                    <td><strong><?= htmlspecialchars($ot['patient_name'] ?: 'N/A'); ?></strong></td>
                                    <td><?= htmlspecialchars($ot['surgery_name']); ?></td>
                                    <td><?= htmlspecialchars($ot['surgeon_name']); ?></td>
                                    <td><?= htmlspecialchars($ot['scheduled_time']); ?></td>
                                    <td>
                                        <span class="badge <?= $ot['status'] == 'Scheduled' ? 'bg-success' : ($ot['status'] == 'Pending' ? 'bg-warning text-dark' : 'bg-info text-dark'); ?>">
                                            <?= htmlspecialchars($ot['status']); ?>
                                        </span>
                                    </td>
                                    <?php if ($role == 'Admin'): ?>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-outline-primary fw-bold" data-bs-toggle="modal" data-bs-target="#assignOtModal<?= $ot['ot_id']; ?>">
                                                <i class="bi bi-pencil-square me-1"></i> Assign Room
                                            </button>

                                            <div class="modal fade text-start" id="assignOtModal<?= $ot['ot_id']; ?>" tabindex="-1">
                                                <div class="modal-dialog modal-sm">
                                                    <div class="modal-content">
                                                        <form method="POST" action="modules.php?tab=ot">
                                                            <input type="hidden" name="action" value="update_ot_room">
                                                            <input type="hidden" name="ot_id" value="<?= $ot['ot_id']; ?>">
                                                            <div class="modal-header bg-danger text-white">
                                                                <h6 class="modal-title">Assign OT Room #OT-<?= $ot['ot_id']; ?></h6>
                                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Room Number</label>
                                                                    <input type="text" name="room_number" class="form-control" value="<?= $ot['room_number'] == 'Pending Assignment' ? '' : htmlspecialchars($ot['room_number']); ?>" placeholder="e.g. OT-01" required>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Status</label>
                                                                    <select name="ot_status" class="form-select">
                                                                        <option value="Scheduled" <?= $ot['status']=='Scheduled'?'selected':''; ?>>Scheduled</option>
                                                                        <option value="Completed" <?= $ot['status']=='Completed'?'selected':''; ?>>Completed</option>
                                                                        <option value="Cancelled" <?= $ot['status']=='Cancelled'?'selected':''; ?>>Cancelled</option>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="submit" class="btn btn-sm btn-danger w-100 fw-bold">Save OT Room</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="<?= $role == 'Admin' ? '8' : '7'; ?>" class="text-center text-muted py-3">No scheduled OT surgeries found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB 2: LAB TESTS -->
        <?php if ($active_tab == 'lab' && in_array($role, ['Admin', 'Doctor', 'Lab Technician', 'Patient'])): ?>
            <div class="card border-0 shadow-sm p-4 mb-4">
                <h5 class="fw-bold text-primary mb-3"><i class="bi bi-eyedropper me-2"></i>Laboratory Diagnostic Test Orders & Reports</h5>
                
                <!-- Patient Form for Manual Test Request -->
                <?php if ($role == 'Patient'): ?>
                    <div class="card border-0 bg-light shadow-sm p-4 mb-4 border-start border-4 border-primary">
                        <h5 class="fw-bold text-primary mb-3"><i class="bi bi-journal-plus me-1"></i> Apply for Manual Laboratory Test</h5>
                        <form method="POST" action="modules.php?tab=lab">
                            <input type="hidden" name="action" value="patient_request_test">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Select Diagnostic Test <span class="text-danger">*</span></label>
                                    <select name="test_name" id="testSelect" class="form-select" required onchange="updateTestCost()">
                                        <option value="">-- Select Lab Test --</option>

                                        <!-- ১. Hematology -->
                                        <optgroup label="🩸 Hematology (রক্ত পরীক্ষা)">
                                            <option value="CBC (Complete Blood Count with ESR)" data-cost="400">CBC (Complete Blood Count with ESR) - ৳400</option>
                                            <option value="Hemoglobin (Hb%)" data-cost="150">Hemoglobin (Hb%) - ৳150</option>
                                            <option value="Blood Grouping & Rh Factor" data-cost="150">Blood Grouping & Rh Factor - ৳150</option>
                                            <option value="Platelet Count" data-cost="200">Platelet Count - ৳200</option>
                                            <option value="Peripheral Blood Film (PBF)" data-cost="500">Peripheral Blood Film (PBF) - ৳500</option>
                                            <option value="BT CT (Bleeding & Clotting Time)" data-cost="250">BT CT (Bleeding & Clotting Time) - ৳250</option>
                                        </optgroup>

                                        <!-- ২. Biochemistry -->
                                        <optgroup label="🧪 Biochemistry (বায়োকেমিস্ট্রি & সুগার)">
                                            <option value="RBS (Random Blood Sugar)" data-cost="150">RBS (Random Blood Sugar) - ৳150</option>
                                            <option value="FBS (Fasting Blood Sugar)" data-cost="150">FBS (Fasting Blood Sugar) - ৳150</option>
                                            <option value="2 Hours ABF (After Breakfast)" data-cost="150">2 Hours ABF (After Breakfast Sugar) - ৳150</option>
                                            <option value="HbA1c (Glycated Hemoglobin)" data-cost="800">HbA1c (3 Months Sugar Average) - ৳800</option>
                                            <option value="Serum Creatinine" data-cost="350">Serum Creatinine (Kidney Function) - ৳350</option>
                                            <option value="Blood Urea / BUN" data-cost="350">Blood Urea / BUN - ৳350</option>
                                            <option value="Serum Uric Acid" data-cost="400">Serum Uric Acid - ৳400</option>
                                            <option value="Lipid Profile (Full)" data-cost="1000">Lipid Profile (Cholesterol, Triglycerides) - ৳1000</option>
                                            <option value="Serum Electrolytes" data-cost="1000">Serum Electrolytes (Na+, K+, Cl-) - ৳1000</option>
                                            <option value="Liver Function Test (LFT) / SGPT" data-cost="400">SGPT / ALT (Liver Test) - ৳400</option>
                                            <option value="SGOT / AST" data-cost="400">SGOT / AST - ৳400</option>
                                            <option value="Serum Bilirubin (Total & Direct)" data-cost="350">Serum Bilirubin (Jaundice Test) - ৳350</option>
                                            <option value="Alkaline Phosphatase" data-cost="400">Alkaline Phosphatase - ৳400</option>
                                            <option value="Serum Calcium" data-cost="500">Serum Calcium - ৳500</option>
                                            <option value="Vitamin D (25-OH)" data-cost="2500">Vitamin D (25-OH) - ৳2500</option>
                                            <option value="Vitamin B12" data-cost="2000">Vitamin B12 - ৳2000</option>
                                        </optgroup>

                                        <!-- ৩. Serology, Immunology & Fever Profile -->
                                        <optgroup label="🦠 Serology & Fever Tests (জ্বর ও ভাইরাস পরীক্ষা)">
                                            <option value="Dengue NS1 Antigen" data-cost="600">Dengue NS1 Antigen (Early Dengue) - ৳600</option>
                                            <option value="Dengue Antibody IgG/IgM" data-cost="800">Dengue Antibody IgG / IgM - ৳800</option>
                                            <option value="Widal Test (Typhoid)" data-cost="400">Widal Test (Typhoid Fever) - ৳400</option>
                                            <option value="Febrile Antigen Test" data-cost="600">Febrile Antigen Test - ৳600</option>
                                            <option value="HBsAg (Hepatitis B Screen)" data-cost="400">HBsAg (Hepatitis B Screening) - ৳400</option>
                                            <option value="Anti-HCV (Hepatitis C)" data-cost="600">Anti-HCV (Hepatitis C) - ৳600</option>
                                            <option value="ICT for Malaria" data-cost="350">ICT for Malaria - ৳350</option>
                                            <option value="CRP (C-Reactive Protein)" data-cost="600">CRP (C-Reactive Protein) - ৳600</option>
                                            <option value="RA / RF Factor" data-cost="500">RA / RF Factor (Rheumatoid Arthritis) - ৳500</option>
                                            <option value="ASO Titre" data-cost="500">ASO Titre - ৳500</option>
                                            <option value="VDRL / Syphilis Test" data-cost="300">VDRL (Syphilis Screening) - ৳300</option>
                                            <option value="HIV I & II Screening" data-cost="600">HIV I & II Screening - ৳600</option>
                                        </optgroup>

                                        <!-- ৪. Hormones & Endocrinology -->
                                        <optgroup label="🧬 Hormones (হরমোন পরীক্ষা)">
                                            <option value="TSH (Thyroid Stimulating Hormone)" data-cost="600">TSH (Thyroid) - ৳600</option>
                                            <option value="FT4 (Free T4)" data-cost="700">FT4 (Free T4) - ৳700</option>
                                            <option value="FT3 (Free T3)" data-cost="700">FT3 (Free T3) - ৳700</option>
                                            <option value="Thyroid Profile (TSH, FT3, FT4)" data-cost="1500">Thyroid Profile (Complete) - ৳1500</option>
                                            <option value="Beta hCG (Pregnancy Level)" data-cost="1000">Beta hCG (Quantitative Pregnancy) - ৳1000</option>
                                            <option value="Serum Prolactin" data-cost="800">Serum Prolactin - ৳800</option>
                                            <option value="Testosterone (Total)" data-cost="1000">Testosterone Level - ৳1000</option>
                                            <option value="FSH & LH" data-cost="1400">FSH & LH (Hormone) - ৳1400</option>
                                            <option value="PSA (Prostate Specific Antigen)" data-cost="1200">PSA (Prostate Test for Male) - ৳1200</option>
                                        </optgroup>

                                        <!-- ৫. Urine & Stool Routine -->
                                        <optgroup label="🧫 Urine & Stool (ইউরিন ও মল পরীক্ষা)">
                                            <option value="Urine R/M/E" data-cost="250">Urine R/M/E (Routine Microscopic Exam) - ৳250</option>
                                            <option value="Urine Culture & Sensitivity (C/S)" data-cost="600">Urine C/S (Culture & Sensitivity) - ৳600</option>
                                            <option value="Urine Pregnancy Test (Strip)" data-cost="150">Urine Pregnancy Test (UFT) - ৳150</option>
                                            <option value="Stool R/M/E" data-cost="200">Stool R/M/E - ৳200</option>
                                            <option value="Stool Occult Blood Test (OBT)" data-cost="300">Stool OBT (Occult Blood Test) - ৳300</option>
                                        </optgroup>

                                        <!-- ৬. Radiology, Cardiology & Imaging -->
                                        <optgroup label="🩻 Radiology & Imaging (এক্স-রে, ইসিজি ও ইউএসজি)">
                                            <option value="Chest X-Ray P/A View" data-cost="500">Chest X-Ray P/A View - ৳500</option>
                                            <option value="X-Ray Lumbar Spine (B/V)" data-cost="800">X-Ray Lumbar Spine - ৳800</option>
                                            <option value="ECG (12 Lead)" data-cost="400">ECG (12 Lead Rest) - ৳400</option>
                                            <option value="Echocardiogram (2D Echo)" data-cost="2000">Echocardiography (2D Color Doppler) - ৳2000</option>
                                            <option value="USG of Whole Abdomen" data-cost="1200">USG of Whole Abdomen - ৳1200</option>
                                            <option value="USG of Lower Abdomen" data-cost="800">USG of Lower Abdomen - ৳800</option>
                                            <option value="USG of Pregnancy Profile" data-cost="1000">USG of Pregnancy / Fetal Well-being - ৳1000</option>
                                            <option value="USG of KUB (Kidney, Ureter, Bladder)" data-cost="1000">USG of KUB Region - ৳1000</option>
                                            <option value="Mammogram" data-cost="2500">Mammography (Breast Screening) - ৳2500</option>
                                            <option value="CT Scan Chest / Brain" data-cost="4500">CT Scan (Brain / Chest) - ৳4500</option>
                                            <option value="MRI Brain / Spine" data-cost="7500">MRI (Brain / Spine) - ৳7500</option>
                                        </optgroup>
                                    </select>
                                </div>

                                <div class="col-md-2">
                                    <label class="form-label fw-semibold">Test Fee (BDT)</label>
                                    <input type="text" name="cost" id="testCost" class="form-control bg-white fw-bold text-success" readonly placeholder="0.00">
                                    <small id="homeChargeNotice" class="text-danger fw-bold d-block mt-1" style="display:none; font-size: 0.75rem;">* Includes ৳200 Home Collection Fee</small>
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Sample Collection Place <span class="text-danger">*</span></label>
                                    <select name="sample_type" id="sampleType" class="form-select fw-bold" required onchange="toggleAddressField()">
                                        <option value="Hospital Visit">🏥 Hospital Visit (In-Person)</option>
                                        <option value="Home Collection">🏠 Home Collection (+৳200 Extra Charge)</option>
                                    </select>
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Payment Method <span class="text-danger">*</span></label>
                                    <select name="payment_method" id="payMethod" class="form-select" onchange="toggleTrxBox()">
                                        <option value="Cash at Counter">💵 Cash at Hospital Counter</option>
                                        <option value="bKash">📱 bKash (Merchant: <?= htmlspecialchars($bkash_num); ?>)</option>
                                        <option value="Nagad">📱 Nagad (Merchant: <?= htmlspecialchars($nagad_num); ?>)</option>
                                    </select>
                                </div>

                                <div class="col-md-8" id="addressBox" style="display: none;">
                                    <label class="form-label fw-semibold text-danger"><i class="bi bi-geo-alt-fill me-1"></i> Home Address for Sample Collection <span class="text-danger">*</span></label>
                                    <textarea name="collection_address" class="form-control" rows="2" placeholder="House no, Road no, Area, Landmark..."></textarea>
                                </div>

                                <div class="col-md-4" id="trxBox" style="display: none;">
                                    <label class="form-label fw-semibold">bKash/Nagad Transaction ID (TrxID)</label>
                                    <input type="text" name="transaction_id" class="form-control" placeholder="Enter TrxID after payment">
                                </div>

                                <div class="col-12 text-end">
                                    <button type="submit" class="btn btn-primary fw-bold px-4"><i class="bi bi-send-fill me-1"></i> Submit Test Request & Pay</button>
                                </div>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <h6 class="fw-bold text-secondary">Pathology Test Orders & Requests Queue</h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Test ID</th>
                                <th>Patient Name</th>
                                <th>Test Name</th>
                                <th>Collection Mode</th>
                                <th>Cost & Payment</th>
                                <th>Scheduled Slot / Serial</th>
                                <th>Status / Result Text</th>
                                <th class="text-center">Action / PDF Report</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($lab_tests)): foreach ($lab_tests as $lt): 
                                $lt_id = !empty($lt['lab_id']) ? $lt['lab_id'] : ($lt['test_id'] ?? 0);
                                $p_name = !empty($lt['display_patient_name']) ? $lt['display_patient_name'] : (!empty($lt['patient_name']) ? $lt['patient_name'] : 'Patient');
                                $p_phone = !empty($lt['display_patient_phone']) ? $lt['display_patient_phone'] : ($lt['phone'] ?? '');
                            ?>
                                <tr>
                                    <td>#LAB-<?= $lt_id; ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($p_name); ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($p_phone); ?></small>
                                    </td>
                                    <td><strong class="text-primary"><?= htmlspecialchars($lt['test_name'] ?? 'N/A'); ?></strong></td>
                                    <td>
                                        <?php if (($lt['sample_type'] ?? '') == 'Home Collection'): ?>
                                            <span class="badge bg-danger"><i class="bi bi-house-door-fill me-1"></i> Home Collection</span><br>
                                            <small class="text-dark"><strong>Address:</strong> <?= htmlspecialchars($lt['collection_address'] ?: 'N/A'); ?></small>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="bi bi-building me-1"></i> Hospital Visit</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong class="text-dark">৳<?= number_format($lt['cost'] ?? 0, 2); ?></strong><br>
                                        <?php if (($lt['payment_status'] ?? '') == 'Paid'): ?>
                                            <span class="badge bg-success">Paid (<?= htmlspecialchars($lt['payment_method'] ?: 'Online'); ?>)</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Unpaid (Cash Counter)</span>
                                        <?php endif; ?>
                                        <?php if (!empty($lt['transaction_id'])): ?>
                                            <br><small class="text-muted">TrxID: <?= htmlspecialchars($lt['transaction_id']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($lt['scheduled_slot'])): ?>
                                            <span class="badge bg-info text-dark fs-6"><i class="bi bi-clock me-1"></i> <?= htmlspecialchars($lt['scheduled_slot']); ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border">Slot Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                            $st = $lt['status'] ?? 'Pending';
                                            $st_bg = ($st == 'Completed') ? 'bg-success' : (($st == 'Scheduled') ? 'bg-primary' : 'bg-warning text-dark');
                                        ?>
                                        <span class="badge <?= $st_bg; ?>"><?= htmlspecialchars($st); ?></span>
                                        <?php if (!empty($lt['result'])): ?>
                                            <div class="mt-1 small bg-light p-2 rounded border border-info">
                                                <strong>Result:</strong> <?= htmlspecialchars($lt['result']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (in_array($role, ['Lab Technician', 'Admin'])): ?>
                                            <button class="btn btn-sm btn-outline-primary mb-1" data-bs-toggle="modal" data-bs-target="#scheduleLabModal<?= $lt_id; ?>">
                                                <i class="bi bi-calendar-event me-1"></i> Set Serial/Slot
                                            </button>
                                            
                                            <!-- UPDATE LAB RESULT TEXT BUTTON -->
                                            <button class="btn btn-sm btn-outline-info text-dark mb-1" data-bs-toggle="modal" data-bs-target="#updateResultModal<?= $lt_id; ?>">
                                                <i class="bi bi-pencil-square me-1"></i> Write/Edit Result
                                            </button>

                                            <button class="btn btn-sm btn-outline-success mb-1" data-bs-toggle="modal" data-bs-target="#pdfLabModal<?= $lt_id; ?>">
                                                <i class="bi bi-upload me-1"></i> Upload PDF Report
                                            </button>
                                        <?php endif; ?>

                                        <?php if (!empty($lt['report_file'])): ?>
                                            <a href="<?= htmlspecialchars($lt['report_file']); ?>" target="_blank" class="btn btn-sm btn-danger fw-bold d-block mt-1">
                                                <i class="bi bi-file-earmark-pdf-fill me-1"></i> View / Download PDF
                                            </a>
                                        <?php else: ?>
                                            <?php if ($role == 'Patient' && empty($lt['result'])): ?>
                                                <small class="text-muted d-block">Report Processing...</small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>

                                <!-- Modals for Lab Tech / Admin -->
                                <?php if (in_array($role, ['Lab Technician', 'Admin'])): ?>
                                    <!-- Modal for Slot / Schedule -->
                                    <div class="modal fade text-start" id="scheduleLabModal<?= $lt_id; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST" action="modules.php?tab=lab">
                                                    <input type="hidden" name="action" value="set_schedule_slot">
                                                    <input type="hidden" name="lab_id" value="<?= $lt_id; ?>">
                                                    <div class="modal-header bg-dark text-white">
                                                        <h6 class="modal-title">Set Serial & Time Slot (#LAB-<?= $lt_id; ?>)</h6>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Select Date</label>
                                                            <input type="date" name="slot_date" class="form-control" value="<?= date('Y-m-d'); ?>" required>
                                                        </div>

                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Select Time Slot (9:00 AM - 6:00 PM)</label>
                                                            <select name="slot_time" class="form-select" required>
                                                                <option value="09:00 AM">09:00 AM</option>
                                                                <option value="09:30 AM">09:30 AM</option>
                                                                <option value="10:00 AM">10:00 AM</option>
                                                                <option value="10:30 AM">10:30 AM</option>
                                                                <option value="11:00 AM">11:00 AM</option>
                                                                <option value="11:30 AM">11:30 AM</option>
                                                                <option value="12:00 PM">12:00 PM</option>
                                                                <option value="12:30 PM">12:30 PM</option>
                                                                <option value="01:00 PM">01:00 PM</option>
                                                                <option value="02:00 PM">02:00 PM</option>
                                                                <option value="02:30 PM">02:30 PM</option>
                                                                <option value="03:00 PM">03:00 PM</option>
                                                                <option value="03:30 PM">03:30 PM</option>
                                                                <option value="04:00 PM">04:00 PM</option>
                                                                <option value="04:30 PM">04:30 PM</option>
                                                                <option value="05:00 PM">05:00 PM</option>
                                                                <option value="05:30 PM">05:30 PM</option>
                                                                <option value="06:00 PM">06:00 PM</option>
                                                            </select>
                                                        </div>

                                                        <?php
                                                            $stmt_cnt = $pdo->query("SELECT COUNT(*) FROM laboratory_tests WHERE scheduled_slot IS NOT NULL AND scheduled_slot != ''");
                                                            $auto_serial_num = ($stmt_cnt ? $stmt_cnt->fetchColumn() : 0) + 1;
                                                            $formatted_serial = "Serial #" . str_pad($auto_serial_num, 2, '0', STR_PAD_LEFT);
                                                        ?>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Auto Serial Number</label>
                                                            <input type="text" name="serial_no" class="form-control bg-light fw-bold text-primary" value="<?= $formatted_serial; ?>" readonly>
                                                        </div>

                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Payment Status</label>
                                                            <select name="payment_status" class="form-select">
                                                                <option value="Paid" <?= ($lt['payment_status'] ?? '') == 'Paid' ? 'selected' : ''; ?>>Paid</option>
                                                                <option value="Unpaid" <?= ($lt['payment_status'] ?? '') == 'Unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Test Status</label>
                                                            <select name="status" class="form-select">
                                                                <option value="Scheduled" <?= ($lt['status'] ?? '') == 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                                                <option value="Sample Collected" <?= ($lt['status'] ?? '') == 'Sample Collected' ? 'selected' : ''; ?>>Sample Collected</option>
                                                                <option value="Completed" <?= ($lt['status'] ?? '') == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="submit" class="btn btn-primary fw-bold">Save Schedule</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- MODAL FOR DIRECT LAB RESULT TEXT ENTRY/EDIT -->
                                    <div class="modal fade text-start" id="updateResultModal<?= $lt_id; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST" action="modules.php?tab=lab">
                                                    <input type="hidden" name="action" value="update_lab_result">
                                                    <input type="hidden" name="lab_id" value="<?= $lt_id; ?>">
                                                    <div class="modal-header bg-info text-dark">
                                                        <h6 class="modal-title fw-bold"><i class="bi bi-file-text me-1"></i> Write / Edit Test Result (#LAB-<?= $lt_id; ?>)</h6>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Result / Impression Details</label>
                                                            <textarea name="lab_result" class="form-control" rows="4" placeholder="e.g. Hemoglobin: 13.5 g/dL (Normal Range: 12-16 g/dL)..." required><?= htmlspecialchars($lt['result'] ?? ''); ?></textarea>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Update Test Status</label>
                                                            <select name="status" class="form-select">
                                                                <option value="Completed" selected>Completed (সম্পন্ন হয়েছে)</option>
                                                                <option value="Sample Collected">Sample Collected</option>
                                                                <option value="Scheduled">Scheduled</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-info text-dark fw-bold"><i class="bi bi-check-circle me-1"></i> Save Result</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Modal for PDF Upload -->
                                    <div class="modal fade text-start" id="pdfLabModal<?= $lt_id; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST" action="modules.php?tab=lab" enctype="multipart/form-data">
                                                    <input type="hidden" name="action" value="upload_pdf_report">
                                                    <input type="hidden" name="lab_id" value="<?= $lt_id; ?>">
                                                    <div class="modal-header bg-success text-white">
                                                        <h6 class="modal-title">Upload Test Report PDF (#LAB-<?= $lt_id; ?>)</h6>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Choose PDF Report File</label>
                                                            <input type="file" name="pdf_file" class="form-control" accept=".pdf" required>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="submit" class="btn btn-success fw-bold"><i class="bi bi-upload me-1"></i> Upload & Finish</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                            <?php endforeach; else: ?>
                                <tr><td colspan="8" class="text-center text-muted py-4">No laboratory test requests found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB 3: BLOOD BANK -->
        <?php if ($active_tab == 'blood'): ?>
            <div class="card border-0 shadow-sm p-4 mb-4">
                <?php if (in_array($role, ['Admin', 'Receptionist'])): ?>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold text-danger mb-0"><i class="bi bi-droplet me-2"></i>Blood Bank Inventory & Request System</h5>
                        <button class="btn btn-danger btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#addBloodRecordModal">
                            <i class="bi bi-plus-circle me-1"></i> Add Stock / Donor / Request
                        </button>
                    </div>

                    <div class="row g-3 mb-4">
                        <?php foreach ($blood_stock as $bs): ?>
                            <div class="col-md-3">
                                <div class="card bg-light border-0 p-3 text-center shadow-sm">
                                    <h3 class="text-danger fw-bold mb-1"><?= htmlspecialchars($bs['blood_group']); ?></h3>
                                    <p class="text-dark fw-semibold mb-0"><?= htmlspecialchars($bs['bags_count']); ?> Bags Available</p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <h6 class="fw-bold text-secondary mb-3"><i class="bi bi-list-columns me-1"></i>Blood Bank Activity & Requests</h6>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>#ID</th>
                                    <th>Type</th>
                                    <th>Blood Group</th>
                                    <th>Name / Requester</th>
                                    <th>Phone</th>
                                    <th>Bags</th>
                                    <th>Need Date</th>
                                    <th>Status</th>
                                    <th>Created At</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($all_blood_records)): foreach ($all_blood_records as $rec): ?>
                                    <tr>
                                        <td><strong>#REC-<?= htmlspecialchars($rec['blood_record_id']); ?></strong></td>
                                        <td>
                                            <span class="badge <?= $rec['record_type'] == 'Donor Entry' ? 'bg-primary' : ($rec['record_type'] == 'Blood Request' ? 'bg-warning text-dark' : 'bg-info text-dark'); ?>">
                                                <?= htmlspecialchars($rec['record_type']); ?>
                                            </span>
                                        </td>
                                        <td><span class="badge bg-danger fs-6"><?= htmlspecialchars($rec['blood_group']); ?></span></td>
                                        <td><strong><?= htmlspecialchars($rec['full_name']); ?></strong></td>
                                        <td><?= htmlspecialchars($rec['phone'] ?: 'N/A'); ?></td>
                                        <td class="fw-bold text-danger"><?= htmlspecialchars($rec['quantity_bags']); ?> Bag(s)</td>
                                        <td><?= htmlspecialchars($rec['expiry_date'] ?: 'N/A'); ?></td>
                                        <td>
                                            <?php 
                                                $st = $rec['status'];
                                                $st_badge = 'bg-secondary';
                                                if ($st == 'Available' || $st == 'Fulfilled') $st_badge = 'bg-success';
                                                elseif ($st == 'Pending' || $st == 'Reserved') $st_badge = 'bg-warning text-dark';
                                                elseif ($st == 'Expired' || $st == 'Issued') $st_badge = 'bg-danger';
                                            ?>
                                            <span class="badge <?= $st_badge; ?>"><?= htmlspecialchars($st); ?></span>
                                        </td>
                                        <td><small class="text-muted"><?= htmlspecialchars($rec['created_at']); ?></small></td>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-outline-success fw-bold" data-bs-toggle="modal" data-bs-target="#updateStatusModal<?= $rec['blood_record_id']; ?>">
                                                <i class="bi bi-check-circle me-1"></i> Accept/Status
                                            </button>

                                            <div class="modal fade text-start" id="updateStatusModal<?= $rec['blood_record_id']; ?>" tabindex="-1">
                                                <div class="modal-dialog modal-sm">
                                                    <div class="modal-content">
                                                        <form method="POST" action="modules.php?tab=blood">
                                                            <input type="hidden" name="action" value="update_blood_status">
                                                            <input type="hidden" name="blood_record_id" value="<?= $rec['blood_record_id']; ?>">
                                                            <div class="modal-header bg-success text-white">
                                                                <h6 class="modal-title"><i class="bi bi-check-circle me-1"></i>Update #REC-<?= $rec['blood_record_id']; ?></h6>
                                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <label class="form-label fw-bold">Select New Status</label>
                                                                <select name="status" class="form-select">
                                                                    <option value="Issued" <?= $rec['status']=='Issued'?'selected':''; ?>>Issued (Accept & Issue Blood)</option>
                                                                    <option value="Fulfilled" <?= $rec['status']=='Fulfilled'?'selected':''; ?>>Fulfilled</option>
                                                                    <option value="Available" <?= $rec['status']=='Available'?'selected':''; ?>>Available</option>
                                                                    <option value="Reserved" <?= $rec['status']=='Reserved'?'selected':''; ?>>Reserved</option>
                                                                    <option value="Expired" <?= $rec['status']=='Expired'?'selected':''; ?>>Expired</option>
                                                                </select>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="submit" class="btn btn-sm btn-success w-100">Save Status</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="10" class="text-center text-muted py-4">No blood records found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="modal fade" id="addBloodRecordModal" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form method="POST" action="modules.php?tab=blood">
                                    <input type="hidden" name="action" value="add_blood_record">
                                    <div class="modal-header bg-danger text-white">
                                        <h5 class="modal-title"><i class="bi bi-droplet me-2"></i>Add Blood Record / Request</h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Record Type</label>
                                            <select name="record_type" class="form-select" required>
                                                <option value="Blood Bag Stock">Blood Bag Stock</option>
                                                <option value="Donor Entry">Donor Entry</option>
                                                <option value="Blood Request">Blood Request</option>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Blood Group</label>
                                            <select name="blood_group" class="form-select" required>
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
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Full Name</label>
                                            <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($full_name); ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Phone Number</label>
                                            <input type="text" name="phone" class="form-control" placeholder="017xxxxxxxx" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Quantity (Bags)</label>
                                            <input type="number" name="quantity_bags" class="form-control" value="1" min="1" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Need / Expiry Date</label>
                                            <input type="date" name="expiry_date" class="form-control">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Status</label>
                                            <select name="status" class="form-select">
                                                <option value="Available">Available</option>
                                                <option value="Pending">Pending</option>
                                                <option value="Fulfilled">Fulfilled</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-danger fw-bold">Submit Record</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row justify-content-center py-2">
                        <div class="col-md-10">
                            
                            <!-- Emergency Room / Staff Call Form for Admitted Patients -->
                            <?php if ($role == 'Patient'): ?>
                                <div class="card border-warning shadow-sm mb-4">
                                    <div class="card-header bg-warning text-dark py-3">
                                        <h5 class="mb-0 fw-bold"><i class="bi bi-bell-fill me-2"></i>Emergency Room / Staff Call</h5>
                                    </div>
                                    <div class="card-body p-4">
                                        <form method="POST" action="modules.php?tab=blood">
                                            <input type="hidden" name="action" value="patient_staff_call">
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Select Assistance Type</label>
                                                <select name="task_type" class="form-select" required>
                                                    <option value="Emergency Nursing">Emergency Nursing / Medical Help</option>
                                                    <option value="Ward Boy Assistance">Ward Boy Assistance / Movement</option>
                                                    <option value="Cleaning">Room Cleaning / Hygiene</option>
                                                    <option value="Equipment Transport">Equipment / Medicine Delivery</option>
                                                </select>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Urgency Level</label>
                                                <select name="urgency" class="form-select">
                                                    <option value="High">High (Immediate Need)</option>
                                                    <option value="Normal" selected>Normal</option>
                                                    <option value="Low">Low</option>
                                                </select>
                                            </div>

                                            <button type="submit" class="btn btn-warning text-dark w-100 fw-bold py-2 fs-6">
                                                <i class="bi bi-megaphone-fill me-1"></i> CALL STAFF NOW
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- REAL-TIME BLOOD BANK STOCK STATUS CARD -->
                            <div class="card border-0 shadow-sm mb-4 border-start border-4 border-danger">
                                <div class="card-header bg-danger bg-opacity-10 py-3 d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0 text-danger fw-bold">
                                        <i class="bi bi-droplet-fill me-2"></i> Current Hospital Blood Bank Stock Status
                                    </h5>
                                    <span class="badge bg-danger px-3 py-2">Live Availability</span>
                                </div>
                                <div class="card-body bg-light">
                                    <div class="row g-2 text-center">
                                        <?php foreach ($blood_stock as $bs): ?>
                                            <div class="col-6 col-sm-4 col-md-3">
                                                <div class="p-3 border rounded bg-white shadow-sm h-100">
                                                    <span class="fw-bold text-danger fs-4 d-block"><?= htmlspecialchars($bs['blood_group']); ?></span>
                                                    <?php if ($bs['bags_count'] > 0): ?>
                                                        <span class="badge bg-success fw-bold px-2 py-1">🟢 <?= $bs['bags_count']; ?> Bag(s) Available</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary fw-semibold px-2 py-1">🔴 Out of Stock</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="card border-danger shadow-sm mb-4">
                                <div class="card-header bg-danger text-white py-3">
                                    <h5 class="mb-0 fw-bold"><i class="bi bi-droplet-fill me-2"></i>Emergency Blood Request Form</h5>
                                </div>
                                <div class="card-body p-4">
                                    <form method="POST" action="modules.php?tab=blood">
                                        <input type="hidden" name="action" value="add_blood_record">
                                        <input type="hidden" name="record_type" value="Blood Request">
                                        
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Full Name (Requester / Patient)</label>
                                            <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($full_name); ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Blood Group</label>
                                            <select name="blood_group" class="form-select" required>
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
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Phone Number</label>
                                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($u_phone); ?>" placeholder="017xxxxxxxx" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Quantity (Bags Needed)</label>
                                            <input type="number" name="quantity_bags" class="form-control" value="1" min="1" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Need Date</label>
                                            <input type="date" name="expiry_date" class="form-control">
                                        </div>
                                        <button type="submit" class="btn btn-danger w-100 fw-bold py-2 fs-6">
                                            <i class="bi bi-send-fill me-1"></i> Submit Blood Request
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <div class="card border-0 shadow-sm p-4">
                                <h5 class="fw-bold text-secondary mb-3"><i class="bi bi-clock-history me-2"></i>My Blood Request History & Status</h5>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Req #</th>
                                                <th>Blood Group</th>
                                                <th>Bags</th>
                                                <th>Phone</th>
                                                <th>Need Date</th>
                                                <th>Status</th>
                                                <th>Requested At</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($my_blood_requests)): foreach ($my_blood_requests as $m_rec): ?>
                                                <tr>
                                                    <td><strong>#REQ-<?= htmlspecialchars($m_rec['blood_record_id']); ?></strong></td>
                                                    <td><span class="badge bg-danger fs-6"><?= htmlspecialchars($m_rec['blood_group']); ?></span></td>
                                                    <td class="fw-bold text-danger"><?= htmlspecialchars($m_rec['quantity_bags']); ?> Bag(s)</td>
                                                    <td><?= htmlspecialchars($m_rec['phone'] ?: 'N/A'); ?></td>
                                                    <td><?= htmlspecialchars($m_rec['expiry_date'] ?: 'N/A'); ?></td>
                                                    <td>
                                                        <?php 
                                                            $st = $m_rec['status'];
                                                            $st_badge = 'bg-secondary';
                                                            if ($st == 'Fulfilled' || $st == 'Available') $st_badge = 'bg-success';
                                                            elseif ($st == 'Pending' || $st == 'Reserved') $st_badge = 'bg-warning text-dark';
                                                            elseif ($st == 'Issued' || $st == 'Expired') $st_badge = 'bg-info text-dark';
                                                        ?>
                                                        <span class="badge <?= $st_badge; ?> fs-6"><?= htmlspecialchars($st); ?></span>
                                                    </td>
                                                    <td><small class="text-muted"><?= htmlspecialchars($m_rec['created_at']); ?></small></td>
                                                </tr>
                                            <?php endforeach; else: ?>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted py-4">No blood requests submitted yet.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- TAB 4: STAFF DISPATCH MODULE -->
        <?php if ($active_tab == 'dispatch' && in_array($role, ['Admin', 'Nurse'])): ?>
            <div class="card border-0 shadow-sm p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-warning text-dark mb-0"><i class="bi bi-truck-flatbed me-2"></i>Staff Dispatch & Task Assignment</h5>
                    <button class="btn btn-warning btn-sm fw-bold text-dark" data-bs-toggle="modal" data-bs-target="#addDispatchModal">
                        <i class="bi bi-plus-circle me-1"></i> Create Dispatch Task
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Task Type</th>
                                <th>Location / Ward</th>
                                <th>Assigned Staff</th>
                                <th>Urgency</th>
                                <th>Status</th>
                                <th>Created At</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($dispatches_list)): foreach ($dispatches_list as $dsp): ?>
                                <tr>
                                    <td><strong>#DSP-<?= $dsp['dispatch_id']; ?></strong></td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($dsp['task_type']); ?></span></td>
                                    <td><?= htmlspecialchars($dsp['location_ward']); ?></td>
                                    <td><strong><?= htmlspecialchars($dsp['staff_name'] ?? 'Unassigned'); ?></strong></td>
                                    <td>
                                        <span class="badge <?= $dsp['urgency'] == 'High' ? 'bg-danger' : ($dsp['urgency'] == 'Normal' ? 'bg-primary' : 'bg-light text-dark border'); ?>">
                                            <?= htmlspecialchars($dsp['urgency']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                            $dst = $dsp['status'];
                                            $dst_badge = 'bg-secondary';
                                            if ($dst == 'Completed') $dst_badge = 'bg-success';
                                            elseif ($dst == 'In Progress') $dst_badge = 'bg-info text-dark';
                                            elseif ($dst == 'Assigned') $dst_badge = 'bg-primary';
                                            elseif ($dst == 'Unassigned') $dst_badge = 'bg-warning text-dark';
                                        ?>
                                        <span class="badge <?= $dst_badge; ?>"><?= htmlspecialchars($dst); ?></span>
                                    </td>
                                    <td><small class="text-muted"><?= htmlspecialchars($dsp['created_at']); ?></small></td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-warning text-dark fw-bold" data-bs-toggle="modal" data-bs-target="#updateDispatchModal<?= $dsp['dispatch_id']; ?>">
                                            <i class="bi bi-pencil-square me-1"></i> Manage
                                        </button>

                                        <!-- Update Dispatch Modal -->
                                        <div class="modal fade text-start" id="updateDispatchModal<?= $dsp['dispatch_id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST" action="modules.php?tab=dispatch">
                                                        <input type="hidden" name="action" value="update_dispatch">
                                                        <input type="hidden" name="dispatch_id" value="<?= $dsp['dispatch_id']; ?>">
                                                        <div class="modal-header bg-warning text-dark">
                                                            <h5 class="modal-title"><i class="bi bi-truck-flatbed me-2"></i>Manage Task #DSP-<?= $dsp['dispatch_id']; ?></h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Assign Staff Member</label>
                                                                <select name="assigned_staff_id" class="form-select">
                                                                    <option value="">-- Unassigned --</option>
                                                                    <?php foreach ($staff_list as $st): ?>
                                                                        <option value="<?= $st['user_id']; ?>" <?= $dsp['assigned_staff_id'] == $st['user_id'] ? 'selected' : ''; ?>>
                                                                            <?= htmlspecialchars($st['full_name']); ?> (<?= htmlspecialchars($st['role']); ?>)
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Task Status</label>
                                                                <select name="status" class="form-select">
                                                                    <option value="Unassigned" <?= $dsp['status']=='Unassigned'?'selected':''; ?>>Unassigned</option>
                                                                    <option value="Assigned" <?= $dsp['status']=='Assigned'?'selected':''; ?>>Assigned</option>
                                                                    <option value="In Progress" <?= $dsp['status']=='In Progress'?'selected':''; ?>>In Progress</option>
                                                                    <option value="Completed" <?= $dsp['status']=='Completed'?'selected':''; ?>>Completed</option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-warning text-dark fw-bold">Save Changes</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="8" class="text-center text-muted py-4">No staff dispatch tasks found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Add Dispatch Task Modal -->
            <div class="modal fade" id="addDispatchModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" action="modules.php?tab=dispatch">
                            <input type="hidden" name="action" value="add_dispatch_task">
                            <div class="modal-header bg-warning text-dark">
                                <h5 class="modal-title"><i class="bi bi-truck-flatbed me-2"></i>Create New Dispatch Task</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Task Type</label>
                                    <select name="task_type" class="form-select" required>
                                        <option value="Emergency Nursing">Emergency Nursing</option>
                                        <option value="Ward Boy Assistance">Ward Boy Assistance</option>
                                        <option value="Cleaning">Cleaning</option>
                                        <option value="Equipment Transport">Equipment Transport</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Location / Ward</label>
                                    <input type="text" name="location_ward" class="form-control" placeholder="e.g. Ward 3, Cabin 202" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Urgency</label>
                                    <select name="urgency" class="form-select">
                                        <option value="Normal">Normal</option>
                                        <option value="High">High</option>
                                        <option value="Low">Low</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Assign Staff (Optional)</label>
                                    <select name="assigned_staff_id" class="form-select">
                                        <option value="">-- Keep Unassigned --</option>
                                        <?php foreach ($staff_list as $st): ?>
                                            <option value="<?= $st['user_id']; ?>">
                                                <?= htmlspecialchars($st['full_name']); ?> (<?= htmlspecialchars($st['role']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-warning text-dark fw-bold">Create Task</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB 5: NOTIFICATIONS (TARGETED BROADCAST, SMS & EMAIL) -->
        <?php if ($active_tab == 'notifications' && $role == 'Admin'): ?>
            <div class="card border-0 shadow-sm p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-warning mb-0"><i class="bi bi-bell me-2"></i>Hospital Targeted Notifications & Alerts</h5>
                    <button class="btn btn-warning btn-sm fw-bold text-dark" data-bs-toggle="modal" data-bs-target="#addNotifModal">
                        <i class="bi bi-plus-circle me-1"></i> Send New Targeted Notification
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Target Group / Role</th>
                                <th>Title</th>
                                <th>Message</th>
                                <th>Priority</th>
                                <th>Date & Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($notifications_list)): foreach ($notifications_list as $n): ?>
                                <tr>
                                    <td>#NOT-<?= $n['id']; ?></td>
                                    <td><span class="badge bg-primary fs-6"><i class="bi bi-people-fill me-1"></i> <?= htmlspecialchars($n['target_role'] ?? 'All Staff'); ?></span></td>
                                    <td><strong><?= htmlspecialchars($n['title']); ?></strong></td>
                                    <td><?= htmlspecialchars($n['message']); ?></td>
                                    <td>
                                        <span class="badge <?= $n['priority'] == 'High Priority' ? 'bg-danger' : ($n['priority'] == 'System Alert' ? 'bg-info text-dark' : 'bg-warning text-dark'); ?>">
                                            <?= htmlspecialchars($n['priority']); ?>
                                        </span>
                                    </td>
                                    <td><small class="text-muted"><i class="bi bi-clock me-1"></i> <?= htmlspecialchars($n['created_at']); ?></small></td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No broadcast notifications created yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- MODAL FOR TARGETED NOTIFICATION WITH SMS & EMAIL -->
            <div class="modal fade" id="addNotifModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <form method="POST" action="modules.php?tab=notifications" accept-charset="UTF-8">
                            <input type="hidden" name="action" value="add_notification">
                            <div class="modal-header bg-warning text-dark">
                                <h5 class="modal-title fw-bold"><i class="bi bi-megaphone-fill me-2"></i> Broadcast Targeted Notice / Meeting / Alert</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold"><i class="bi bi-people me-1"></i> Target Audience / Role <span class="text-danger">*</span></label>
                                        <select name="target_role" class="form-select fw-semibold" required>
                                            <option value="All Staff" selected>🏢 All Staff (Doctors, Nurses, Staff, Pharmacists)</option>
                                            <option value="Doctor">👨‍⚕️ Doctors Only</option>
                                            <option value="Nurse">👩‍⚕️ Nurses Only</option>
                                            <option value="Pharmacist">💊 Pharmacists Only (ফার্মাসিস্ট)</option>
                                            <option value="Receptionist">🖥️ Receptionists Only</option>
                                            <option value="Lab Technician">🧪 Lab Technicians Only</option>
                                            <option value="Accountant">💰 Accountants / Finance Staff</option>
                                            <option value="Patient">🩺 Patients Only</option>
                                            <option value="All Users (Including Patients)">🌐 All System Users (Staff + Patients)</option>
                                        </select>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label fw-bold"><i class="bi bi-exclamation-triangle me-1"></i> Priority Level</label>
                                        <select name="priority" class="form-select">
                                            <option value="Normal">Normal Notice (সাধারণ নোটিশ / ছুটির বার্তা)</option>
                                            <option value="High Priority">High Priority (জরুরী নোটিশ / সতর্কবার্তা)</option>
                                            <option value="System Alert">Meeting Alert (জরুরী মিটিং আহ্বান)</option>
                                        </select>
                                    </div>

                                    <div class="col-md-12">
                                        <label class="form-label fw-bold">Notification Title <span class="text-danger">*</span></label>
                                        <input type="text" name="title" class="form-control" placeholder="e.g. জাতীয় দিবসের ছুটি / জরুরী মিটিং / Urgent Notice" required>
                                    </div>

                                    <div class="col-md-12">
                                        <div class="p-3 bg-light rounded border">
                                            <label class="form-label fw-bold d-block mb-2 text-primary"><i class="bi bi-send-check me-1"></i> Delivery Channels (বার্তা পাঠানোর মাধ্যম):</label>
                                            <div class="form-check form-check-inline me-4">
                                                <input class="form-check-input" type="checkbox" id="app_notif" checked disabled>
                                                <label class="form-check-label fw-semibold" for="app_notif">🖥️ Notice Board / Dashboard</label>
                                            </div>
                                            <div class="form-check form-check-inline me-4">
                                                <input class="form-check-input" type="checkbox" name="send_sms" value="1" id="sendSms" checked>
                                                <label class="form-check-label fw-bold text-success" for="sendSms"><i class="bi bi-phone"></i> Send Mobile SMS (বাংলা/English)</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="checkbox" name="send_email" value="1" id="sendEmail" checked>
                                                <label class="form-check-label fw-bold text-danger" for="sendEmail"><i class="bi bi-envelope"></i> Send Email Notice</label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-12">
                                        <label class="form-label fw-bold">Message Details / Content <span class="text-danger">*</span></label>
                                        <textarea name="not_message" class="form-control" rows="4" required placeholder="Type notification details..."></textarea>
                                    </div>
                                </div>

                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-warning text-dark fw-bold px-4"><i class="bi bi-send-fill me-1"></i> Send Targeted Broadcast</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB 6: AUDIT LOGS -->
        <?php if ($active_tab == 'audit' && $role == 'Admin'): ?>
            <div class="card border-0 shadow-sm p-4 mb-4">
                <h5 class="fw-bold text-success mb-3"><i class="bi bi-shield-check me-2"></i>System Security & Audit Logs</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Log ID</th>
                                <th>User</th>
                                <th>Action Performed</th>
                                <th>Timestamp</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($audit_logs_list)): foreach ($audit_logs_list as $log): ?>
                                <tr>
                                    <td>#LOG-<?= $log['log_id']; ?></td>
                                    <td><strong><?= htmlspecialchars($log['user_name']); ?></strong></td>
                                    <td><?= htmlspecialchars($log['action']); ?></td>
                                    <td><small class="text-muted"><?= htmlspecialchars($log['created_at']); ?></small></td>
                                    <td><span class="badge bg-success"><?= htmlspecialchars($log['status']); ?></span></td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No audit logs recorded yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
function updateTestCost() {
    const select = document.getElementById('testSelect');
    if (!select) return;
    const selected = select.options[select.selectedIndex];
    let cost = parseFloat(selected.getAttribute('data-cost') || '0');

    const sampleTypeSelect = document.getElementById('sampleType');
    const homeNotice = document.getElementById('homeChargeNotice');

    if (sampleTypeSelect && sampleTypeSelect.value === 'Home Collection' && cost > 0) {
        cost += 200; 
        if (homeNotice) homeNotice.style.display = 'block';
    } else {
        if (homeNotice) homeNotice.style.display = 'none';
    }

    document.getElementById('testCost').value = cost > 0 ? cost.toFixed(2) : '0.00';
}

function toggleAddressField() {
    const typeSelect = document.getElementById('sampleType');
    const type = typeSelect ? typeSelect.value : '';
    const box = document.getElementById('addressBox');
    const payMethod = document.getElementById('payMethod');
    const cashOpt = payMethod ? payMethod.querySelector('option[value="Cash at Counter"]') : null;

    if (box) box.style.display = (type === 'Home Collection') ? 'block' : 'none';

    if (type === 'Home Collection') {
        if (cashOpt) cashOpt.style.display = 'none';
        if (payMethod && payMethod.value === 'Cash at Counter') {
            payMethod.value = 'bKash';
            toggleTrxBox();
        }
    } else {
        if (cashOpt) cashOpt.style.display = 'block';
    }
    
    updateTestCost();
}

function toggleTrxBox() {
    const method = document.getElementById('payMethod').value;
    const box = document.getElementById('trxBox');
    if (box) box.style.display = (method === 'bKash' || method === 'Nagad') ? 'block' : 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    toggleAddressField();
    toggleTrxBox();
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>