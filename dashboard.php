<?php
session_start();
require 'db_connect.php';

// ১. লগইন সিকিউরিটি চেক
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'Patient';
$full_name = $_SESSION['full_name'] ?? 'User';
$message   = "";
$message_type = "";

// emergency_note ও doctor_id কলাম না থাকলে অটো তৈরি করার সেইফ চেক
try {
    $pdo->exec("ALTER TABLE cabins_beds ADD COLUMN emergency_note TEXT NULL");
} catch (PDOException $e) {}
try {
    $pdo->exec("ALTER TABLE cabins_beds ADD COLUMN doctor_id INT UNSIGNED NULL");
} catch (PDOException $e) {}

// Auto-fix: users টেবিলে ব্যাংকিং ও স্যালারি কলাম এবং notifications টেবিল চেক/এড করা
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN bank_name VARCHAR(100) NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN account_number VARCHAR(50) NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN account_holder VARCHAR(100) NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN branch_name VARCHAR(100) NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN monthly_salary DECIMAL(10,2) DEFAULT 0.00");
} catch (PDOException $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        target_role VARCHAR(100) DEFAULT 'Admin',
        priority VARCHAR(50) DEFAULT 'Normal',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {}

// রিয়েল-টাইম ডাটাবেজ থেকে স্ট্যাটাস এবং ডিগ্রি/রেজিস্ট্রেশন তথ্য রিড
try {
    $stmt_curr = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt_curr->execute([$user_id]);
    $user_data = $stmt_curr->fetch(PDO::FETCH_ASSOC);

    $user_status = $user_data['status'] ?? 'Inactive';
    $has_info    = !empty($user_data['registration_number']) || !empty($user_data['degree']);
} catch (PDOException $e) {
    $user_status = 'Inactive';
    $has_info    = false;
}

// ডাক্তারের ক্ষেত্রে doctors টেবিল থেকেও স্ট্যাটাস চেক করা
$doctor_approved = true;
$doctor = null;
if ($role == 'Doctor') {
    $stmt_d = $pdo->prepare("SELECT * FROM doctors WHERE user_id = ?");
    $stmt_d->execute([$user_id]);
    $doctor = $stmt_d->fetch(PDO::FETCH_ASSOC);
    
    $has_info = !empty($doctor['license_number']);
    $doctor_approved = ($doctor && $doctor['status'] === 'Approved');
}

// এপ্রুভাল লজিক
if ($role === 'Patient' || $role === 'Admin') {
    $is_approved = true;
} else {
    $is_approved = ($user_status === 'Active' || $user_status === 'Present') && $has_info && $doctor_approved;
}

// -------------------------------------------------------------
// ২. পোস্ট অ্যাকশন
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Pharmacist Supplier Bill Requisition Submission Logic
    if (isset($_POST['submit_supplier_requisition']) && in_array($role, ['Pharmacist', 'Admin'])) {
        $med_name   = trim($_POST['req_medicine_name'] ?? '');
        $supplier   = trim($_POST['req_supplier_name'] ?? 'Supplier');
        $qty        = intval($_POST['req_quantity'] ?? 0);
        $unit_price = floatval($_POST['req_unit_price'] ?? 0);
        $total_cost = $qty * $unit_price;

        if (!empty($med_name) && $qty > 0 && $total_cost > 0) {
            try {
                $title = "Supplier Bill Request: " . $supplier . " ($med_name)";
                $notif_msg = "Medicine Item: $med_name\nSupplier/Company: $supplier\nQuantity: $qty Pcs\nUnit Purchase Price: BDT " . number_format($unit_price, 2) . "\nTotal Bill Amount: BDT " . number_format($total_cost, 2);

                $ins_notif = $pdo->prepare("INSERT INTO notifications (title, message, target_role, priority) VALUES (?, ?, 'Admin', 'Pending')");
                $ins_notif->execute([$title, $notif_msg]);

                $message = "Supplier Requisition Bill of ৳" . number_format($total_cost, 2) . " for '$med_name' sent to Admin successfully!";
                $message_type = "success";
            } catch (PDOException $e) {
                $message = "Supplier Bill Submission Error: " . $e->getMessage();
                $message_type = "danger";
            }
        } else {
            $message = "Please fill in medicine name, quantity, and unit price correctly.";
            $message_type = "warning";
        }
    }

    // স্টাফ ও ডাক্তারদের ব্যাংক উইথড্রয়াল রিকোয়েস্ট সেভ লজিক
    if (isset($_POST['submit_salary_withdrawal']) && $role != 'Patient') {
        $w_amount   = floatval($_POST['withdraw_amount'] ?? 0);
        $w_bank     = trim($_POST['bank_name'] ?? '');
        $w_acc_num  = trim($_POST['account_number'] ?? '');
        $w_acc_name = trim($_POST['account_holder'] ?? '');
        $w_branch   = trim($_POST['branch_name'] ?? '');

        if ($w_amount > 0 && !empty($w_bank) && !empty($w_acc_num)) {
            try {
                $upd_b = $pdo->prepare("UPDATE users SET bank_name = ?, account_number = ?, account_holder = ?, branch_name = ? WHERE user_id = ?");
                $upd_b->execute([$w_bank, $w_acc_num, $w_acc_name, $w_branch, $user_id]);

                $title = "Salary Withdrawal Request from " . $full_name . " (" . $role . ")";
                $notif_msg = "Staff Name: $full_name ($role)\nRequested Amount: BDT " . number_format($w_amount, 2) . "\nBank Name: $w_bank\nAccount No: $w_acc_num\nAccount Holder: $w_acc_name\nBranch: $w_branch";
                
                $ins_notif = $pdo->prepare("INSERT INTO notifications (title, message, target_role, priority) VALUES (?, ?, 'Admin', 'High Priority')");
                $ins_notif->execute([$title, $notif_msg]);

                $message = "Your salary withdrawal request of ৳" . number_format($w_amount, 2) . " has been submitted to Admin!";
                $message_type = "success";
            } catch (PDOException $e) {
                $message = "Withdrawal Request Error: " . $e->getMessage();
                $message_type = "danger";
            }
        } else {
            $message = "Please fill in all required bank details and amount!";
            $message_type = "warning";
        }
    }

    // Admin bKash/Nagad Gateway Number Update Logic
    if ($role == 'Admin' && isset($_POST['update_gateway_numbers'])) {
        $bkash_num = trim($_POST['bkash_number'] ?? '');
        $nagad_num = trim($_POST['nagad_number'] ?? '');

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS hospital_settings (
                setting_key VARCHAR(50) PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB;");

            $pdo->prepare("INSERT INTO hospital_settings (setting_key, setting_value) VALUES ('bkash_number', ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$bkash_num, $bkash_num]);
            $pdo->prepare("INSERT INTO hospital_settings (setting_key, setting_value) VALUES ('nagad_number', ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$nagad_num, $nagad_num]);

            $message = "bKash and Nagad merchant numbers updated successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Error updating gateway numbers: " . $e->getMessage();
            $message_type = "danger";
        }
    }

    // ডাক্তার প্রেসেন্স / অ্যাটেন্ডেন্স স্ট্যাটাস আপডেট
    if (in_array($role, ['Admin', 'Receptionist']) && isset($_POST['update_doc_status_id'], $_POST['new_doc_status'])) {
        $update_doc_id = intval($_POST['update_doc_status_id']);
        $new_doc_status = trim($_POST['new_doc_status']);

        try {
            $pdo->prepare("UPDATE doctors SET availability_status = ? WHERE doctor_id = ?")->execute([$new_doc_status, $update_doc_id]);
            $message = "Doctor availability status updated to '$new_doc_status' successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Error updating doctor status: " . $e->getMessage();
            $message_type = "danger";
        }
    }

    // স্টাফ প্রেসেন্স / অ্যাটেন্ডেন্স স্ট্যাটাস আপডেট
    if (in_array($role, ['Admin', 'Receptionist']) && isset($_POST['update_staff_status_id'], $_POST['new_staff_status'])) {
        $update_staff_id = intval($_POST['update_staff_status_id']);
        $new_staff_status = trim($_POST['new_staff_status']);

        try {
            $pdo->prepare("UPDATE users SET status = ? WHERE user_id = ?")->execute([$new_staff_status, $update_staff_id]);
            $message = "Staff presence status updated to '$new_staff_status' successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Error updating staff status: " . $e->getMessage();
            $message_type = "danger";
        }
    }

    // অ্যাম্বুলেন্স ডিসপ্যাচ
    if (in_array($role, ['Admin', 'Receptionist']) && isset($_POST['dispatch_id'], $_POST['action_dispatch'])) {
        $disp_id = intval($_POST['dispatch_id']);
        try {
            $pdo->prepare("UPDATE ambulance_dispatches SET status = 'Dispatched' WHERE dispatch_id = ?")->execute([$disp_id]);
            $message = "Ambulance #AMB-$disp_id has been Dispatched & Sent to Location successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "danger";
        }
    }

    // ব্লাড রিকোয়েস্ট এপ্রুভ
    if (in_array($role, ['Admin', 'Receptionist']) && isset($_POST['approve_blood_id'])) {
        $b_rec_id = intval($_POST['approve_blood_id']);
        try {
            $pdo->prepare("UPDATE blood_bank SET status = 'Issued' WHERE id = ?")->execute([$b_rec_id]);
            $message = "Blood Request #REC-$b_rec_id has been Issued & Approved successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "danger";
        }
    }

    // অ্যাপয়েন্টমেন্ট এপ্রুভ বা ক্যানসেল
    if (in_array($role, ['Admin', 'Receptionist']) && isset($_POST['dash_app_id'], $_POST['dash_app_action'])) {
        $app_id = intval($_POST['dash_app_id']);
        $act    = $_POST['dash_app_action'];

        try {
            if (in_array($act, ['Confirmed', 'Approved'])) {
                $stmt_app = $pdo->prepare("SELECT a.*, p.full_name AS patient_name, p.phone AS patient_phone FROM appointments a JOIN patients p ON a.patient_id = p.patient_id WHERE a.appointment_id = ?");
                $stmt_app->execute([$app_id]);
                $app_data = $stmt_app->fetch(PDO::FETCH_ASSOC);

                if ($app_data) {
                    $pay_method = $app_data['payment_method'] ?? 'Cash at Counter';
                    $is_cash = in_array($pay_method, ['Cash at Counter', 'Cash at Hospital Counter (Offline)', 'Cash']);

                    $app_pay_st = $is_cash ? 'Unpaid' : 'Paid';
                    $inv_pay_st = $is_cash ? 'Due' : 'Paid';

                    $pdo->prepare("UPDATE appointments SET status = ?, payment_status = ? WHERE appointment_id = ?")->execute([$act, $app_pay_st, $app_id]);

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
                        ? "Appointment #APPT-$app_id Approved! Invoice added to Accounts as Due (Collect Cash from Billing)." 
                        : "Appointment #APPT-$app_id Approved & Online Payment Confirmed!";
                } else {
                    $pdo->prepare("UPDATE appointments SET status = ? WHERE appointment_id = ?")->execute([$act, $app_id]);
                    $message = "Appointment #APPT-$app_id has been $act successfully!";
                }
            } else {
                $pdo->prepare("UPDATE appointments SET status = ? WHERE appointment_id = ?")->execute([$act, $app_id]);
                $message = "Appointment #APPT-$app_id has been $act successfully!";
            }
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "danger";
        }
    }

    // অ্যাডমিনের ডাক্তার অ্যাপ্রুভাল
    if ($role == 'Admin' && isset($_POST['doctor_user_id'], $_POST['doc_action'])) {
        $doc_uid = intval($_POST['doctor_user_id']);
        $d_action = $_POST['doc_action'];
        $approved_salary = floatval($_POST['approved_monthly_salary'] ?? 0);

        try {
            if ($d_action == 'approve') {
                $pdo->prepare("UPDATE users SET status = 'Active', monthly_salary = ? WHERE user_id = ?")->execute([$approved_salary, $doc_uid]);
                
                $chk_doc = $pdo->prepare("SELECT doctor_id FROM doctors WHERE user_id = ?");
                $chk_doc->execute([$doc_uid]);
                if ($chk_doc->rowCount() > 0) {
                    $pdo->prepare("UPDATE doctors SET status = 'Approved' WHERE user_id = ?")->execute([$doc_uid]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO doctors (user_id, department_name, specialization, qualification, status) VALUES (?, 'General', 'General Practitioner', 'MBBS', 'Approved')");
                    $ins->execute([$doc_uid]);
                }

                $message = "Doctor application approved with Fixed Salary ৳" . number_format($approved_salary, 2) . " successfully!";
                $message_type = "success";
            } elseif ($d_action == 'reject') {
                $pdo->prepare("UPDATE users SET status = 'Rejected' WHERE user_id = ?")->execute([$doc_uid]);
                $pdo->prepare("UPDATE doctors SET status = 'Rejected' WHERE user_id = ?")->execute([$doc_uid]);
                $message = "Doctor application rejected!";
                $message_type = "warning";
            }
        } catch (PDOException $e) {
            $message = "Database Error: " . $e->getMessage();
            $message_type = "danger";
        }
    }

    // অ্যাডমিনের স্টাফ অ্যাপ্রুভাল
    if ($role == 'Admin' && isset($_POST['staff_action'], $_POST['staff_user_id'])) {
        $staff_uid = intval($_POST['staff_user_id']);
        $s_action  = $_POST['staff_action'];
        $approved_salary = floatval($_POST['approved_monthly_salary'] ?? 0);

        try {
            if ($s_action == 'approve') {
                $pdo->prepare("UPDATE users SET status = 'Active', monthly_salary = ? WHERE user_id = ?")->execute([$approved_salary, $staff_uid]);
                $message = "Staff profile approved with Fixed Salary ৳" . number_format($approved_salary, 2) . " successfully!";
                $message_type = "success";
            } elseif ($s_action == 'reject') {
                $pdo->prepare("UPDATE users SET status = 'Rejected' WHERE user_id = ?")->execute([$staff_uid]);
                $message = "Staff request rejected!";
                $message_type = "warning";
            }
        } catch (PDOException $e) {
            $message = "Database Error: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// -------------------------------------------------------------
// ৩. ড্যাশবোর্ডের পরিসংখ্যান ও ডাটা সংগ্রহ
// -------------------------------------------------------------
$total_patients          = 0;
$approved_doctors        = 0;
$pending_doctors         = 0;
$pending_labs            = 0;
$completed_labs          = 0;
$pending_blood_requests  = 0;
$pending_appointments    = 0;
$total_beds              = 0;
$occupied_beds           = 0;
$total_medicines         = 0;
$low_stock_medicines     = 0;
$out_stock_medicines     = 0;
$pending_unpaid_bills    = 0;
$today_collection        = 0;
$my_appointments         = 0;
$pending_supplier_requisitions = 0;

$pending_blood_list      = [];
$active_ambulances_list  = [];
$pending_appointments_rec= [];
$recent_patients         = [];
$pending_doctor_requests = [];
$pending_staff_requests  = [];
$approved_staff_list     = [];
$approved_doctor_list    = [];
$active_emergencies      = [];
$dashboard_notifications = [];

// স্টাফ ও ডাক্তারদের স্যালারি তথ্য ফেচ
$my_salary_amount = floatval($user_data['monthly_salary'] ?? 0.00);
$my_salary_status = 'Unpaid';
$my_payroll_data  = null;

try {
    $stmt_pr = $pdo->prepare("SELECT * FROM payroll WHERE staff_id = ? ORDER BY payroll_id DESC LIMIT 1");
    $stmt_pr->execute([$user_id]);
    $my_payroll_data = $stmt_pr->fetch(PDO::FETCH_ASSOC);

    if ($my_payroll_data) {
        $my_salary_status = $my_payroll_data['status'] ?? 'Unpaid';
        if (!empty($my_payroll_data['net_salary'])) {
            $my_salary_amount = floatval($my_payroll_data['net_salary']);
        }
    }
} catch (PDOException $e) {}

// 🚀 [UPDATED] ইউজার রোল অনুযায়ী নোটিফিকেশন ফেচ করা (Payment korle dashboard notice theke chole jabe)
try {
    $stmt_notif = $pdo->prepare("
        SELECT * FROM notifications 
        WHERE (target_role = 'All Users (Including Patients)'
           OR target_role = ? 
           OR (target_role = 'All Staff' AND ? != 'Patient'))
          AND (priority = 'Pending' OR priority = 'Normal' OR priority = 'High Priority' OR priority = 'System Alert')
          AND target_role != 'Approved'
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt_notif->execute([$role, $role]);
    $dashboard_notifications = $stmt_notif->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Fetch current merchant numbers
$settings = [];
try {
    $stmt_set = $pdo->query("SELECT setting_key, setting_value FROM hospital_settings");
    if ($stmt_set) {
        while ($s_row = $stmt_set->fetch(PDO::FETCH_ASSOC)) {
            $settings[$s_row['setting_key']] = $s_row['setting_value'];
        }
    }
} catch (PDOException $e) {}

$current_bkash = $settings['bkash_number'] ?? '01700000000';
$current_nagad = $settings['nagad_number'] ?? '01700000000';

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM patients");
    if ($stmt) $total_patients = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM blood_bank WHERE record_type = 'Blood Request' AND (status = 'Pending' OR status IS NULL OR status = '')");
    if ($stmt) $pending_blood_requests = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'Pending' OR status IS NULL OR status = ''");
    if ($stmt) $pending_appointments = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM cabins_beds");
    if ($stmt) $total_beds = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM cabins_beds WHERE LOWER(TRIM(availability_status)) = 'occupied'");
    if ($stmt) $occupied_beds = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM users u INNER JOIN doctors d ON u.user_id = d.user_id WHERE u.role = 'Doctor' AND u.status IN ('Active', 'Present') AND d.status = 'Approved'");
    if ($stmt) $approved_doctors = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(DISTINCT u.user_id) FROM users u LEFT JOIN doctors d ON u.user_id = d.user_id WHERE u.role = 'Doctor' AND (u.status = 'Inactive' OR d.status = 'Pending' OR d.status IS NULL)");
    if ($stmt) $pending_doctors = $stmt->fetchColumn();

    $check_lab = $pdo->query("SHOW TABLES LIKE 'laboratory_tests'");
    if ($check_lab && $check_lab->rowCount() > 0) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM laboratory_tests WHERE (status != 'Completed' AND status != 'Cancelled') OR status IS NULL OR status = ''");
        if ($stmt) $pending_labs = $stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM laboratory_tests WHERE status = 'Completed'");
        if ($stmt) $completed_labs = $stmt->fetchColumn();
    }

    $check_pharm = $pdo->query("SHOW TABLES LIKE 'pharmacy_medicines'");
    if ($check_pharm && $check_pharm->rowCount() > 0) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM pharmacy_medicines");
        if ($stmt) $total_medicines = $stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM pharmacy_medicines WHERE stock_quantity <= low_stock_threshold AND stock_quantity > 0");
        if ($stmt) $low_stock_medicines = $stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM pharmacy_medicines WHERE stock_quantity <= 0");
        if ($stmt) $out_stock_medicines = $stmt->fetchColumn();
    }

    // 🚀 [UPDATED] Supplier Bills Pending Count
    try {
        $stmt_sup_cnt = $pdo->query("SELECT COUNT(*) FROM notifications WHERE (title LIKE '%Supplier%' OR title LIKE '%Stock Purchase%' OR message LIKE '%Supplier%') AND (priority != 'Completed' AND target_role != 'Approved')");
        if ($stmt_sup_cnt) $pending_supplier_requisitions = $stmt_sup_cnt->fetchColumn();
    } catch (PDOException $e) {}

    $check_bill = $pdo->query("SHOW TABLES LIKE 'billing_invoices'");
    if ($check_bill && $check_bill->rowCount() > 0) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM billing_invoices WHERE payment_status IN ('Due', 'Partial')");
        if ($stmt) $pending_unpaid_bills = $stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COALESCE(SUM(net_payable), 0) FROM billing_invoices WHERE payment_status = 'Paid' AND DATE(invoice_date) = CURDATE()");
        if ($stmt) $today_collection = $stmt->fetchColumn();
    }

    if ($role == 'Doctor' && !empty($doctor['doctor_id'])) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND (status = 'Approved' OR status = 'Confirmed')");
        $stmt->execute([$doctor['doctor_id']]);
        $my_appointments = $stmt->fetchColumn();
    }

    if ($role == 'Patient') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments a JOIN patients p ON a.patient_id = p.patient_id WHERE p.user_id = ?");
        $stmt->execute([$user_id]);
        $my_appointments = $stmt->fetchColumn();
    }

} catch (PDOException $e) {}

if (in_array($role, ['Nurse', 'Doctor', 'Admin'])) {
    try {
        $stmt_em = $pdo->query("SELECT cb.*, p.full_name AS patient_name, p.phone AS patient_phone FROM cabins_beds cb JOIN patients p ON cb.patient_id = p.patient_id WHERE cb.emergency_note IS NOT NULL AND cb.emergency_note != ''");
        if ($stmt_em) $active_emergencies = $stmt_em->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

if (in_array($role, ['Admin', 'Receptionist'])) {
    try {
        $stmt_b_list = $pdo->query("SELECT * FROM blood_bank WHERE record_type = 'Blood Request' AND (LOWER(TRIM(status)) = 'pending' OR status IS NULL OR status = '') ORDER BY id DESC");
        if ($stmt_b_list) $pending_blood_list = $stmt_b_list->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    try {
        $check_amb = $pdo->query("SHOW TABLES LIKE 'ambulance_dispatches'");
        if ($check_amb && $check_amb->rowCount() > 0) {
            $stmt_amb = $pdo->query("SELECT * FROM ambulance_dispatches WHERE status IN ('Pending', 'Dispatched') ORDER BY dispatch_id DESC LIMIT 10");
            if ($stmt_amb) $active_ambulances_list = $stmt_amb->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {}

    try {
        $stmt_p_app = $pdo->query("SELECT a.*, 
                                          COALESCE(p.full_name, 'Patient ID: ' || a.patient_id) AS patient_name, 
                                          COALESCE(p.phone, 'N/A') AS patient_phone, 
                                          COALESCE(u_doc.full_name, 'Doctor Assigned') AS doctor_name 
                                   FROM appointments a 
                                   LEFT JOIN patients p ON a.patient_id = p.patient_id 
                                   LEFT JOIN doctors d ON a.doctor_id = d.doctor_id 
                                   LEFT JOIN users u_doc ON d.user_id = u_doc.user_id 
                                   WHERE LOWER(TRIM(a.status)) = 'pending' OR a.status IS NULL OR a.status = '' OR a.status = '0' 
                                   ORDER BY a.appointment_id DESC");
        if ($stmt_p_app) {
            $pending_appointments_rec = $stmt_p_app->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {}

    try {
        $stmt = $pdo->query("SELECT d.doctor_id, d.department_name, d.specialization, d.availability_status, u.user_id, u.full_name, u.phone_number FROM users u INNER JOIN doctors d ON u.user_id = d.user_id WHERE u.role = 'Doctor' AND u.status IN ('Active', 'Present') AND d.status = 'Approved' ORDER BY u.user_id DESC LIMIT 15");
        if ($stmt) $approved_doctor_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt_a_s = $pdo->query("SELECT user_id, username, full_name, email, phone_number, role, status, degree, education_qualification, registration_number FROM users WHERE status IN ('Active', 'Present', 'Absent', 'On Leave') AND role NOT IN ('Patient', 'Doctor', 'Admin') ORDER BY user_id DESC LIMIT 15");
        if ($stmt_a_s) $approved_staff_list = $stmt_a_s->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

if ($role == 'Admin') {
    try {
        $stmt_pd = $pdo->query("SELECT u.user_id, u.full_name, u.email, u.phone_number, u.monthly_salary, u.status AS user_status, d.doctor_id, d.department_name, d.specialization, d.qualification, d.license_number, d.status AS doc_status FROM users u LEFT JOIN doctors d ON u.user_id = d.user_id WHERE u.role = 'Doctor' AND (u.status = 'Inactive' OR d.status = 'Pending' OR d.status IS NULL) ORDER BY u.user_id DESC");
        if ($stmt_pd) $pending_doctor_requests = $stmt_pd->fetchAll(PDO::FETCH_ASSOC);

        $stmt_s = $pdo->query("SELECT user_id, username, full_name, email, phone_number, role, degree, education_qualification, registration_number, monthly_salary FROM users WHERE status = 'Inactive' AND role NOT IN ('Patient', 'Doctor', 'Admin') ORDER BY user_id DESC");
        if ($stmt_s) $pending_staff_requests = $stmt_s->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->query("SELECT patient_id, full_name, gender, phone, blood_group, national_id FROM patients ORDER BY patient_id DESC LIMIT 10");
        if ($stmt) $recent_patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Smart Hospital ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f7f6; margin: 0; }
        .sidebar { min-height: 100vh; background-color: #1e293b; color: white; width: 260px; flex-shrink: 0; }
        .sidebar a { color: #94a3b8; text-decoration: none; padding: 10px 15px; display: block; border-radius: 6px; margin-bottom: 4px; font-weight: 500; }
        .sidebar a:hover, .sidebar a.active { background-color: #334155; color: #ffffff; }
        .sidebar i { margin-right: 10px; }
        .nav-category { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 1px; color: #64748b; margin-top: 18px; margin-bottom: 6px; padding-left: 15px; font-weight: 700; }
        .stat-card { border-radius: 10px; transition: transform 0.2s ease; }
        .stat-card:hover { transform: translateY(-3px); }
    </style>
</head>
<body>

<datalist id="dash_med_list">
    <option value="Napa Extra 500mg">
    <option value="Sergel 20mg Capsule">
    <option value="Seclo 20mg Capsule">
    <option value="Ace 500mg Tablet">
    <option value="Cef-3 200mg Capsule">
    <option value="Monas 10mg Tablet">
</datalist>

<datalist id="dash_supp_list">
    <option value="Square Pharmaceuticals Ltd.">
    <option value="Beximco Pharmaceuticals Ltd.">
    <option value="Incepta Pharmaceuticals Ltd.">
    <option value="Healthcare Pharmaceuticals Ltd.">
    <option value="Renata Limited">
</datalist>

<div class="d-flex">
    <!-- সাইডবার -->
    <div class="sidebar p-3">
        <h4 class="text-center text-white mb-4"><i class="bi bi-hospital"></i> Hospital ERP</h4>
        <hr class="text-secondary">
        
        <a href="dashboard.php" class="active"><i class="bi bi-speedometer2"></i> Dashboard</a>

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
            <a href="modules.php"><i class="bi bi-cpu"></i> Modules</a>
        <?php endif; ?>

        <?php if ($role == 'Receptionist' && $is_approved): ?>
            <div class="nav-category">Patient & Frontdesk</div>
            <a href="patient_registration.php"><i class="bi bi-person-plus"></i> Patient Registration</a>
            <a href="patient_list.php"><i class="bi bi-people"></i> Patient Directory</a>
            <a href="book_appointment.php"><i class="bi bi-calendar-check"></i> Appointments</a>
            <a href="cabins.php"><i class="bi bi-building"></i> Cabins & Beds</a>
            <a href="assign_bed.php"><i class="bi bi-journal-plus"></i> Assign Bed</a>
            <a href="modules.php?tab=blood"><i class="bi bi-droplet-fill text-danger"></i> Blood Bank Mgmt</a>
            <a href="emergency_dispatch.php" class="text-danger fw-bold"><i class="bi bi-truck text-danger"></i> Emergency Dispatch</a>
        <?php endif; ?>

        <?php if ($role == 'Lab Technician' && $is_approved): ?>
            <div class="nav-category">Lab Station</div>
            <a href="modules.php"><i class="bi bi-eyedropper text-danger"></i> Modules</a>
            <a href="patient_list.php"><i class="bi bi-people"></i> Patient Directory</a>
        <?php endif; ?>

        <?php if ($role == 'Pharmacist' && $is_approved): ?>
            <div class="nav-category">Pharmacy Station</div>
            <a href="pharmacy.php"><i class="bi bi-capsule text-success"></i> Pharmacy & Inventory</a>
        <?php endif; ?>

        <?php if ($role == 'Accountant' && $is_approved): ?>
            <div class="nav-category">Accounts & Finance</div>
            <a href="billing.php"><i class="bi bi-receipt"></i> Billing & Invoices</a>
            <a href="finance.php"><i class="bi bi-cash-coin"></i> Payroll & Finance</a>

            <div class="nav-category">Patient Management</div>
            <a href="patient_list.php"><i class="bi bi-people"></i> Patient Directory</a>
        <?php endif; ?>

        <?php if ($role == 'Nurse' && $is_approved): ?>
            <div class="nav-category">Nurse Station</div>
            <a href="cabins.php"><i class="bi bi-door-open-fill"></i> Cabin & Bed Status</a>
            <a href="patient_list.php"><i class="bi bi-person-lines-fill"></i> Patient Directory</a>
            <a href="appointments.php"><i class="bi bi-journal-medical"></i> Doctor Schedule</a>
            <a href="emergency_dispatch.php"><i class="bi bi-truck"></i> Emergency Dispatch</a>
        <?php endif; ?>

        <?php if ($role == 'Doctor' && $is_approved): ?>
            <div class="nav-category">Doctor Portal</div>
            <a href="appointments.php"><i class="bi bi-journal-medical"></i> My Appointments</a>
            <a href="add_prescription.php"><i class="bi bi-prescription2"></i> Write Prescription</a>
            <a href="modules.php?tab=blood" class="text-danger fw-bold"><i class="bi bi-droplet-fill text-danger"></i> Request Blood</a>
            <a href="modules.php"><i class="bi bi-cpu"></i> Modules</a>
        <?php endif; ?>

        <?php if ($role == 'Patient'): ?>
            <div class="nav-category">Patient Portal</div>
            <a href="book_appointment.php"><i class="bi bi-calendar-plus"></i> Book Appointment</a>
            <a href="appointments.php"><i class="bi bi-calendar-check"></i> My Appointments</a>
            <a href="modules.php?tab=lab"><i class="bi bi-eyedropper text-primary"></i> Request Lab Test</a>
            <a href="edit_profile.php"><i class="bi bi-person-badge"></i> Complete My Profile</a>
            <a href="billing.php"><i class="bi bi-receipt-cutoff"></i> My Bills</a>
            <a href="modules.php?tab=blood" class="text-danger fw-bold"><i class="bi bi-droplet-fill text-danger"></i> Request Blood</a>
            <a href="emergency_dispatch.php" class="text-danger fw-bold"><i class="bi bi-truck text-danger"></i> Emergency Ambulance</a>
        <?php endif; ?>

        <?php if (in_array($role, ['Nurse', 'Pharmacist', 'Lab Technician', 'Accountant', 'Receptionist', 'Doctor'])): ?>
            <div class="nav-category">Staff Portal</div>
            <a href="edit_profile.php"><i class="bi bi-person-badge"></i> Edit My Profile</a>
        <?php endif; ?>
        
        <hr class="text-secondary mt-4">
        <a href="logout.php" class="text-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>

    <!-- মূল কনটেন্ট -->
    <div class="w-100">
        <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm px-4 py-3 mb-4">
            <div class="container-fluid">
                <span class="navbar-brand mb-0 h1 fw-bold text-primary"><i class="bi bi-speedometer2 me-2"></i>Dashboard - <?= htmlspecialchars($role); ?> Portal</span>
                <div class="d-flex align-items-center">
                    <span class="me-3 text-muted">
                        Welcome, <strong><?= htmlspecialchars($full_name); ?></strong> 
                        (<span class="badge bg-primary"><?= htmlspecialchars($role); ?></span>)
                    </span>
                    <a href="logout.php" class="btn btn-sm btn-outline-danger">Logout</a>
                </div>
            </div>
        </nav>

        <div class="container-fluid px-4">

            <?php if ($role != 'Patient' && $role != 'Admin' && $is_approved): ?>
                <div class="card border-0 shadow-sm mb-4 border-start border-4 border-success bg-white">
                    <div class="card-body p-3">
                        <div class="row align-items-center">
                            <div class="col-md-6 mb-2 mb-md-0">
                                <h6 class="text-muted mb-1 fw-semibold"><i class="bi bi-wallet2 text-success me-2"></i>My Monthly Salary & Balance</h6>
                                <h3 class="fw-bold text-dark mb-0">
                                    ৳<?= number_format($my_salary_amount, 2); ?>
                                    <span class="badge <?= ($my_salary_status == 'Paid') ? 'bg-success' : 'bg-warning text-dark'; ?> fs-6 ms-2">
                                        <?= ($my_salary_status == 'Paid') ? '🟢 Paid / Credited' : '🟡 Unpaid / Pending'; ?>
                                    </span>
                                </h3>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <?php if ($my_salary_status == 'Paid'): ?>
                                    <button type="button" class="btn btn-success fw-bold px-4 py-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#withdrawModal">
                                        <i class="bi bi-bank me-1"></i> Withdraw to Bank Account
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-secondary fw-bold px-4 py-2" disabled>
                                        <i class="bi bi-clock-history me-1"></i> Withdrawal Available Once Paid
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal fade" id="withdrawModal" tabindex="-1" aria-labelledby="withdrawModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content border-0 shadow">
                            <div class="modal-header bg-success text-white">
                                <h5 class="modal-header-title mb-0 fw-bold" id="withdrawModalLabel"><i class="bi bi-bank me-2"></i>Withdraw Salary to Bank Account</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form method="POST" action="dashboard.php">
                                <div class="modal-body p-4">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Withdrawal Amount (BDT)</label>
                                        <input type="number" step="0.01" name="withdraw_amount" class="form-control form-control-lg fw-bold text-success" value="<?= $my_salary_amount; ?>" readonly required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Bank Name <span class="text-danger">*</span></label>
                                        <input type="text" name="bank_name" class="form-control" value="<?= htmlspecialchars($user_data['bank_name'] ?? ''); ?>" placeholder="e.g. Dutch-Bangla Bank / Islami Bank" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Account Number <span class="text-danger">*</span></label>
                                        <input type="text" name="account_number" class="form-control" value="<?= htmlspecialchars($user_data['account_number'] ?? ''); ?>" placeholder="e.g. 151.120.xxxxx" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Account Holder Name <span class="text-danger">*</span></label>
                                        <input type="text" name="account_holder" class="form-control" value="<?= htmlspecialchars($user_data['account_holder'] ?? $full_name); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Branch Name / District</label>
                                        <input type="text" name="branch_name" class="form-control" value="<?= htmlspecialchars($user_data['branch_name'] ?? ''); ?>" placeholder="e.g. Dhanmondi Branch">
                                    </div>
                                </div>
                                <div class="modal-footer bg-light">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" name="submit_salary_withdrawal" class="btn btn-success fw-bold px-4"><i class="bi bi-send-check me-1"></i> Submit Withdrawal Request</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- 📢 HOSPITAL BROADCAST NOTICE BOARD -->
            <?php if (!empty($dashboard_notifications)): ?>
                <div class="card border-0 shadow-sm mb-4 border-start border-4 border-warning">
                    <div class="card-header bg-warning bg-opacity-10 d-flex justify-content-between align-items-center py-3">
                        <h5 class="mb-0 fw-bold text-dark">
                            <i class="bi bi-megaphone-fill text-warning me-2 fs-5"></i> Hospital Notice & Broadcast Announcements
                        </h5>
                        <span class="badge bg-warning text-dark fw-bold"><?= count($dashboard_notifications); ?> New Notice</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($dashboard_notifications as $notif): ?>
                                <div class="list-group-item p-3">
                                    <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                                        <h6 class="mb-1 fw-bold text-primary">
                                            <i class="bi bi-bell-fill me-1"></i> <?= htmlspecialchars($notif['title']); ?>
                                        </h6>
                                        <small class="text-muted"><i class="bi bi-clock me-1"></i> <?= htmlspecialchars($notif['created_at']); ?></small>
                                    </div>
                                    <p class="mb-2 text-secondary" style="white-space: pre-line;"><?= htmlspecialchars($notif['message']); ?></p>
                                    <div>
                                        <span class="badge bg-info text-dark me-1">Target: <?= htmlspecialchars($notif['target_role']); ?></span>
                                        <?php 
                                            $p_bg = ($notif['priority'] == 'High Priority') ? 'bg-danger' : (($notif['priority'] == 'System Alert') ? 'bg-warning text-dark' : 'bg-secondary');
                                        ?>
                                        <span class="badge <?= $p_bg; ?>"><?= htmlspecialchars($notif['priority']); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $message_type; ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (!$is_approved): ?>
                <div class="card border-0 shadow-sm p-4 bg-white text-center my-4">
                    <div class="py-4">
                        <i class="bi bi-shield-lock-fill text-warning display-1 mb-3"></i>
                        <h3 class="fw-bold text-dark">Account Pending Admin Approval</h3>
                        <p class="text-muted fs-5 col-md-8 mx-auto">
                            <?php if (!$has_info): ?>
                                Apnar account-er kaj shuru korte prothome <a href="edit_profile.php" class="fw-bold text-primary">Edit My Profile</a>-e giye shikkhagoto joggota o registration/license number update korun.
                            <?php else: ?>
                                Apnar joma dewa profile info Admin review korchen. Admin approve na kora porjonto apnar dashboard tools o stat box-gulo bondho thakbe.
                            <?php endif; ?>
                        </p>
                        <a href="edit_profile.php" class="btn btn-primary btn-lg fw-bold px-4"><i class="bi bi-person-badge me-2"></i>Edit My Profile</a>
                    </div>
                </div>
            <?php else: ?>

                <!-- 1. ADMIN TOP BOXES -->
                <?php if ($role == 'Admin'): ?>
                    <div class="row mb-4">
                        <div class="col-md-2 mb-3">
                            <div class="card bg-primary text-white p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-white-50"><i class="bi bi-people me-1"></i> Total Patients</h6>
                                <h2 class="fw-bold mb-0"><?= $total_patients; ?></h2>
                            </div>
                        </div>
                        <div class="col-md-2 mb-3">
                            <div class="card bg-success text-white p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-white-50"><i class="bi bi-heart-pulse me-1"></i> Active Doctors</h6>
                                <h2 class="fw-bold mb-0"><?= $approved_doctors; ?></h2>
                            </div>
                        </div>
                        <div class="col-md-2 mb-3">
                            <div class="card bg-warning text-dark p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-dark-50"><i class="bi bi-clock-history me-1"></i> Pending Doctors</h6>
                                <h2 class="fw-bold mb-0"><?= $pending_doctors; ?></h2>
                            </div>
                        </div>
                        <div class="col-md-2 mb-3">
                            <div class="card bg-secondary text-white p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-white-50"><i class="bi bi-eyedropper me-1"></i> Pending Lab Tests</h6>
                                <h2 class="fw-bold mb-0"><?= $pending_labs; ?></h2>
                            </div>
                        </div>
                        <div class="col-md-2 mb-3">
                            <div class="card bg-danger text-white p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-white-50"><i class="bi bi-droplet-fill me-1"></i> Pending Blood Requests</h6>
                                <h2 class="fw-bold mb-0"><?= $pending_blood_requests; ?></h2>
                            </div>
                        </div>
                        <!-- Pending Supplier Bills Box for Admin -->
                        <div class="col-md-2 mb-3">
                            <a href="finance.php" class="text-decoration-none">
                                <div class="card bg-dark text-white p-3 shadow-sm border-0 stat-card h-100 border-start border-4 border-warning">
                                    <h6 class="text-warning"><i class="bi bi-truck me-1"></i> Supplier Bills</h6>
                                    <h2 class="fw-bold mb-0 text-warning"><?= $pending_supplier_requisitions; ?> Pending</h2>
                                </div>
                            </a>
                        </div>
                    </div>

                    <!-- Gateway Merchant Numbers Settings Card -->
                    <div class="card shadow-sm border-0 mb-4 border-start border-4 border-dark">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-gear-fill me-2"></i>Online Payment Gateway Settings (bKash & Nagad)</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" class="row g-3 align-items-end">
                                <div class="col-md-5">
                                    <label class="form-label fw-semibold">bKash Merchant Number</label>
                                    <input type="text" name="bkash_number" class="form-control" value="<?= htmlspecialchars($current_bkash); ?>" required>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label fw-semibold">Nagad Merchant Number</label>
                                    <input type="text" name="nagad_number" class="form-control" value="<?= htmlspecialchars($current_nagad); ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" name="update_gateway_numbers" class="btn btn-primary w-100 fw-bold">
                                        <i class="bi bi-check-circle me-1"></i> Save
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                <!-- 2. RECEPTIONIST TOP BOXES -->
                <?php elseif ($role == 'Receptionist'): ?>
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3">
                            <div class="card bg-warning text-dark p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-dark-50"><i class="bi bi-clock-history me-1"></i> Pending Appointments</h6>
                                <h2 class="fw-bold mb-0"><?= $pending_appointments; ?></h2>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card bg-danger text-white p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-white-50"><i class="bi bi-droplet-fill me-1"></i> Pending Blood Requests</h6>
                                <h2 class="fw-bold mb-0"><?= $pending_blood_requests; ?></h2>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card bg-primary text-white p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-white-50"><i class="bi bi-people me-1"></i> Total Registered Patients</h6>
                                <h2 class="fw-bold mb-0"><?= $total_patients; ?></h2>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card bg-success text-white p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-white-50"><i class="bi bi-door-open me-1"></i> Occupied Beds / Cabins</h6>
                                <h2 class="fw-bold mb-0"><?= $occupied_beds; ?> / <?= $total_beds; ?></h2>
                            </div>
                        </div>
                    </div>

                <!-- 3. DOCTOR TOP BOXES -->
                <?php elseif ($role == 'Doctor'): ?>
                    <div class="row mb-4">
                        <div class="col-md-4 mb-3">
                            <div class="card bg-primary text-white p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-white-50"><i class="bi bi-calendar-check me-1"></i> My Appointments</h6>
                                <h2 class="fw-bold mb-0"><?= $my_appointments; ?></h2>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card bg-danger text-white p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-white-50"><i class="bi bi-bell-fill me-1"></i> Urgent Emergency Calls</h6>
                                <h2 class="fw-bold mb-0"><?= count($active_emergencies); ?></h2>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card bg-success text-white p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-white-50"><i class="bi bi-people me-1"></i> Total Patients</h6>
                                <h2 class="fw-bold mb-0"><?= $total_patients; ?></h2>
                            </div>
                        </div>
                    </div>

                <!-- 4. NURSE TOP BOXES -->
                <?php elseif ($role == 'Nurse'): ?>
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3">
                            <div class="card bg-danger text-white p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-white-50"><i class="bi bi-exclamation-triangle-fill me-1"></i> Patient Emergency Calls</h6>
                                <h2 class="fw-bold mb-0"><?= count($active_emergencies); ?></h2>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card bg-warning text-dark p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-dark-50"><i class="bi bi-door-open me-1"></i> Occupied Cabins/Beds</h6>
                                <h2 class="fw-bold mb-0"><?= $occupied_beds; ?></h2>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card bg-primary text-white p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-white-50"><i class="bi bi-building me-1"></i> Total Hospital Beds</h6>
                                <h2 class="fw-bold mb-0"><?= $total_beds; ?></h2>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card bg-success text-white p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-white-50"><i class="bi bi-heart-pulse me-1"></i> Active Doctors</h6>
                                <h2 class="fw-bold mb-0"><?= $approved_doctors; ?></h2>
                            </div>
                        </div>
                    </div>

                <!-- 5. PHARMACIST TOP BOXES & REQUISITION FORM -->
                <?php elseif ($role == 'Pharmacist'): ?>
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3">
                            <div class="card bg-success text-white p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-white-50"><i class="bi bi-capsule me-1"></i> Total Medicine Items</h6>
                                <h2 class="fw-bold mb-0"><?= $total_medicines; ?> Items</h2>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card bg-warning text-dark p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-dark-50"><i class="bi bi-exclamation-triangle-fill me-1"></i> Low Stock Alert</h6>
                                <h2 class="fw-bold mb-0"><?= $low_stock_medicines; ?> Items</h2>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card bg-danger text-white p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-white-50"><i class="bi bi-x-circle-fill me-1"></i> Out of Stock</h6>
                                <h2 class="fw-bold mb-0"><?= $out_stock_medicines; ?> Items</h2>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card bg-primary text-white p-3 shadow-sm border-0 stat-card h-100 border-start border-4 border-warning">
                                <h6 class="text-white-50"><i class="bi bi-truck me-1"></i> Supplier Requisitions</h6>
                                <h2 class="fw-bold mb-0"><?= $pending_supplier_requisitions; ?> Pending Alert</h2>
                            </div>
                        </div>
                    </div>

                    <!-- Pharmacist Send Supplier Bill Form -->
                    <div class="card border-0 shadow-sm mb-4 border-start border-4 border-success">
                        <div class="card-header bg-success text-white py-3">
                            <h6 class="mb-0 fw-bold"><i class="bi bi-plus-circle me-1"></i> Send Missing Stock Requisition & Bill to Admin</h6>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" action="dashboard.php">
                                <input type="hidden" name="submit_supplier_requisition" value="1">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Medicine Name *</label>
                                        <input type="text" name="req_medicine_name" class="form-control" list="dash_med_list" required placeholder="Select or type medicine...">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Supplier / Company *</label>
                                        <input type="text" name="req_supplier_name" class="form-control" list="dash_supp_list" required placeholder="Select pharma company...">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Quantity Needed *</label>
                                        <input type="number" name="req_quantity" class="form-control" required min="1" value="50">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Unit Purchase Price (BDT) *</label>
                                        <input type="number" step="0.01" name="req_unit_price" class="form-control" required placeholder="0.00">
                                    </div>
                                    <div class="col-12 text-end">
                                        <button type="submit" class="btn btn-success fw-bold px-4">
                                            <i class="bi bi-send-check me-1"></i> Submit Bill Requisition to Admin
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- 6. LAB TECHNICIAN TOP BOXES -->
                <?php if ($role == 'Lab Technician'): ?>
                    <div class="row mb-4">
                        <div class="col-md-6 mb-3">
                            <div class="card bg-warning text-dark p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-dark-50"><i class="bi bi-hourglass-split me-1"></i> Pending Lab Tests</h6>
                                <h2 class="fw-bold mb-0"><?= $pending_labs; ?> Orders</h2>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="card bg-success text-white p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-white-50"><i class="bi bi-check-circle-fill me-1"></i> Completed Reports</h6>
                                <h2 class="fw-bold mb-0"><?= $completed_labs; ?> Reports</h2>
                            </div>
                        </div>
                    </div>

                <!-- 7. ACCOUNTANT TOP BOXES -->
                <?php elseif ($role == 'Accountant'): ?>
                    <div class="row mb-4">
                        <div class="col-md-6 mb-3">
                            <div class="card bg-warning text-dark p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-dark-50"><i class="bi bi-clock me-1"></i> Pending Unpaid Bills</h6>
                                <h2 class="fw-bold mb-0"><?= $pending_unpaid_bills; ?> Invoices</h2>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="card bg-success text-white p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-white-50"><i class="bi bi-cash-stack me-1"></i> Today's Cash Collection</h6>
                                <h2 class="fw-bold mb-0">৳<?= number_format($today_collection, 2); ?></h2>
                            </div>
                        </div>
                    </div>

                <!-- 8. PATIENT TOP BOXES -->
                <?php elseif ($role == 'Patient'): ?>
                    <div class="row mb-4">
                        <div class="col-md-4 mb-3">
                            <div class="card bg-primary text-white p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-white-50"><i class="bi bi-calendar-check me-1"></i> My Appointments</h6>
                                <h2 class="fw-bold mb-0"><?= $my_appointments; ?></h2>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card bg-danger text-white p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-white-50"><i class="bi bi-droplet-fill me-1"></i> Active Blood Requests</h6>
                                <h2 class="fw-bold mb-0"><?= $pending_blood_requests; ?></h2>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card bg-success text-white p-3 shadow-sm border-0 stat-card h-100">
                                <h6 class="text-white-50"><i class="bi bi-hospital me-1"></i> Active Doctors Available</h6>
                                <h2 class="fw-bold mb-0"><?= $approved_doctors; ?></h2>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Emergency Alert Banner for Nurse & Doctor -->
                <?php if (in_array($role, ['Nurse', 'Doctor']) && !empty($active_emergencies)): ?>
                    <div class="alert alert-danger shadow-sm border-2 border-danger mb-4 p-3" role="alert">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h5 class="alert-heading fw-bold mb-0 text-danger">
                                <i class="bi bi-exclamation-triangle-fill me-2 fs-4"></i> 
                                <?= ($role == 'Nurse') ? 'ACTIVE PATIENT CALLS FOR NURSE' : 'URGENT DOCTOR CALLS FROM NURSE'; ?> (<?= count($active_emergencies); ?>)
                            </h5>
                            <a href="cabins.php" class="btn btn-sm btn-danger fw-bold"><i class="bi bi-door-open me-1"></i> Go To Cabins & Resolve</a>
                        </div>
                        <ul class="mb-0 ps-3">
                            <?php foreach ($active_emergencies as $em): ?>
                                <li>
                                    <strong>Bed/Cabin: <?= htmlspecialchars($em['bed_number']); ?> (<?= htmlspecialchars($em['cabin_type']); ?>)</strong> - 
                                    Patient: <strong><?= htmlspecialchars($em['patient_name']); ?></strong> (Phone: <?= htmlspecialchars($em['patient_phone']); ?>) | 
                                    <span class="badge bg-dark"><?= htmlspecialchars($em['emergency_note']); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- ১. অ্যাডমিন ড্যাশবোর্ড কন্টেন্ট -->
                <?php if ($role == 'Admin'): ?>

                    <!-- Pending Blood Requests Table for Admin -->
                    <?php if (!empty($pending_blood_list)): ?>
                        <div class="card shadow-sm border-0 mb-4 border-start border-4 border-danger">
                            <div class="card-header bg-danger bg-opacity-10 d-flex justify-content-between align-items-center py-3">
                                <h5 class="mb-0 fw-bold text-danger"><i class="bi bi-droplet-half me-2"></i>Pending Patient Blood Requests</h5>
                                <a href="modules.php?tab=blood" class="btn btn-sm btn-danger fw-bold"><i class="bi bi-box-arrow-up-right me-1"></i> Open Blood Bank</a>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>#ID</th>
                                                <th>Requester Name</th>
                                                <th>Phone</th>
                                                <th>Blood Group</th>
                                                <th>Bags Needed</th>
                                                <th>Need Date</th>
                                                <th class="text-center">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pending_blood_list as $bl): ?>
                                                <tr>
                                                    <td><strong>#REC-<?= $bl['id']; ?></strong></td>
                                                    <td><strong><?= htmlspecialchars($bl['full_name'] ?: 'N/A'); ?></strong></td>
                                                    <td><?= htmlspecialchars($bl['phone'] ?: 'N/A'); ?></td>
                                                    <td><span class="badge bg-danger fs-6"><?= htmlspecialchars($bl['blood_group']); ?></span></td>
                                                    <td><span class="badge bg-dark"><?= htmlspecialchars($bl['bags_count']); ?> Bag(s)</span></td>
                                                    <td><?= htmlspecialchars($bl['need_date'] ?: 'Urgent'); ?></td>
                                                    <td class="text-center">
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="approve_blood_id" value="<?= $bl['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-success fw-bold me-1">
                                                                <i class="bi bi-check-circle me-1"></i> Accept & Issue Blood
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- পেন্ডিং ডাক্তার অ্যাপ্রুভাল রিকোয়েস্ট -->
                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-warning bg-opacity-25 d-flex justify-content-between align-items-center py-3">
                            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-clock-history me-2"></i>Pending Doctor Approval Requests</h5>
                            <span class="badge bg-warning text-dark"><?= count($pending_doctor_requests); ?> Pending</span>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>SL#</th>
                                            <th>Doctor Name & Email</th>
                                            <th>Department & Specialty</th>
                                            <th>Qualification & License</th>
                                            <th>Expected Salary</th>
                                            <th>Phone</th>
                                            <th class="text-center">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($pending_doctor_requests)): $sl = 1; ?>
                                            <?php foreach ($pending_doctor_requests as $doc): ?>
                                                <tr>
                                                    <td><?= $sl++; ?></td>
                                                    <td>
                                                        <strong>Dr. <?= htmlspecialchars($doc['full_name']); ?></strong><br>
                                                        <small class="text-muted"><?= htmlspecialchars($doc['email']); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-info text-dark"><?= htmlspecialchars($doc['department_name'] ?: 'Not Specified'); ?></span><br>
                                                        <small><?= htmlspecialchars($doc['specialization'] ?: ''); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($doc['qualification'] ?: 'MBBS'); ?></span><br>
                                                        <small class="text-danger fw-semibold">License: <?= htmlspecialchars($doc['license_number'] ?: 'N/A'); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="fw-bold text-primary">৳<?= number_format($doc['monthly_salary'] ?? 0, 2); ?></span>
                                                    </td>
                                                    <td><?= htmlspecialchars($doc['phone_number'] ?: 'N/A'); ?></td>
                                                    <td class="text-center">
                                                        <button type="button" class="btn btn-sm btn-success me-1 fw-bold" data-bs-toggle="modal" data-bs-target="#approveDocModal_<?= $doc['user_id']; ?>">
                                                            <i class="bi bi-check-circle"></i> Approve & Set Salary
                                                        </button>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="doctor_user_id" value="<?= $doc['user_id']; ?>">
                                                            <input type="hidden" name="doc_action" value="reject">
                                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Reject this doctor?');">
                                                                <i class="bi bi-x-circle"></i> Reject
                                                            </button>
                                                        </form>

                                                        <div class="modal fade" id="approveDocModal_<?= $doc['user_id']; ?>" tabindex="-1" aria-hidden="true">
                                                            <div class="modal-dialog modal-dialog-centered modal-sm">
                                                                <div class="modal-content border-0 shadow">
                                                                    <div class="modal-header bg-success text-white py-2">
                                                                        <h6 class="modal-title mb-0 fw-bold"><i class="bi bi-check-circle me-1"></i>Approve Doctor Salary</h6>
                                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                                    </div>
                                                                    <form method="POST" action="dashboard.php">
                                                                        <div class="modal-body p-3 text-start">
                                                                            <input type="hidden" name="doctor_user_id" value="<?= $doc['user_id']; ?>">
                                                                            <input type="hidden" name="doc_action" value="approve">
                                                                            <div class="mb-2">
                                                                                <label class="form-label small fw-semibold text-muted">Doctor Expected Salary:</label>
                                                                                <input type="text" class="form-control form-control-sm bg-light fw-bold text-primary" value="৳<?= number_format($doc['monthly_salary'] ?? 0, 2); ?>" readonly>
                                                                            </div>
                                                                            <div class="mb-2">
                                                                                <label class="form-label small fw-semibold">Admin Fixed Salary (BDT) <span class="text-danger">*</span></label>
                                                                                <input type="number" step="0.01" name="approved_monthly_salary" class="form-control form-control-sm" value="<?= $doc['monthly_salary'] ?? '0.00'; ?>" required>
                                                                                <small class="text-muted d-block mt-1">Expected kam/beshi hole change kore final amount din.</small>
                                                                            </div>
                                                                        </div>
                                                                        <div class="modal-footer bg-light py-2">
                                                                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                            <button type="submit" class="btn btn-sm btn-success fw-bold"><i class="bi bi-check-lg"></i> Confirm & Approve</button>
                                                                        </div>
                                                                    </form>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-muted py-3">No pending doctor approval requests found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- পেন্ডিং স্টাফ অ্যাপ্রুভাল রিকোয়েস্ট -->
                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-info bg-opacity-25 d-flex justify-content-between align-items-center py-3">
                            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-person-check me-2"></i>Pending Staff Approval Requests</h5>
                            <span class="badge bg-info text-dark"><?= count($pending_staff_requests); ?> Pending</span>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>SL#</th>
                                            <th>Staff Name & Role</th>
                                            <th>Degree & Qualification</th>
                                            <th>License/Reg No</th>
                                            <th>Expected Salary</th>
                                            <th>Phone</th>
                                            <th class="text-center">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($pending_staff_requests)): $sl = 1; ?>
                                            <?php foreach ($pending_staff_requests as $st): ?>
                                                <tr>
                                                    <td><?= $sl++; ?></td>
                                                    <td>
                                                        <strong><?= htmlspecialchars($st['full_name']); ?></strong><br>
                                                        <span class="badge bg-secondary"><?= htmlspecialchars($st['role']); ?></span>
                                                        <small class="text-muted d-block"><?= htmlspecialchars($st['email']); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($st['degree'] ?: 'N/A'); ?></span><br>
                                                        <small><?= htmlspecialchars($st['education_qualification'] ?: ''); ?></small>
                                                    </td>
                                                    <td class="text-primary fw-semibold"><?= htmlspecialchars($st['registration_number'] ?: 'N/A'); ?></td>
                                                    <td>
                                                        <span class="fw-bold text-primary">৳<?= number_format($st['monthly_salary'] ?? 0, 2); ?></span>
                                                    </td>
                                                    <td><?= htmlspecialchars($st['phone_number'] ?: 'N/A'); ?></td>
                                                    <td class="text-center">
                                                        <button type="button" class="btn btn-sm btn-success me-1 fw-bold" data-bs-toggle="modal" data-bs-target="#approveStaffModal_<?= $st['user_id']; ?>">
                                                            <i class="bi bi-check-circle"></i> Approve & Set Salary
                                                        </button>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="staff_user_id" value="<?= $st['user_id']; ?>">
                                                            <input type="hidden" name="staff_action" value="reject">
                                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Reject this staff request?');">
                                                                <i class="bi bi-x-circle"></i> Reject
                                                            </button>
                                                        </form>

                                                        <div class="modal fade" id="approveStaffModal_<?= $st['user_id']; ?>" tabindex="-1" aria-hidden="true">
                                                            <div class="modal-dialog modal-dialog-centered modal-sm">
                                                                <div class="modal-content border-0 shadow">
                                                                    <div class="modal-header bg-success text-white py-2">
                                                                        <h6 class="modal-title mb-0 fw-bold"><i class="bi bi-check-circle me-1"></i>Approve Staff Salary</h6>
                                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                                    </div>
                                                                    <form method="POST" action="dashboard.php">
                                                                        <div class="modal-body p-3 text-start">
                                                                            <input type="hidden" name="staff_user_id" value="<?= $st['user_id']; ?>">
                                                                            <input type="hidden" name="staff_action" value="approve">
                                                                            <div class="mb-2">
                                                                                <label class="form-label small fw-semibold text-muted">Staff Expected Salary:</label>
                                                                                <input type="text" class="form-control form-control-sm bg-light fw-bold text-primary" value="৳<?= number_format($st['monthly_salary'] ?? 0, 2); ?>" readonly>
                                                                            </div>
                                                                            <div class="mb-2">
                                                                                <label class="form-label small fw-semibold">Admin Fixed Salary (BDT) <span class="text-danger">*</span></label>
                                                                                <input type="number" step="0.01" name="approved_monthly_salary" class="form-control form-control-sm" value="<?= $st['monthly_salary'] ?? '0.00'; ?>" required>
                                                                                <small class="text-muted d-block mt-1">Salary bashi mone hole adjust kore din.</small>
                                                                            </div>
                                                                        </div>
                                                                        <div class="modal-footer bg-light py-2">
                                                                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                            <button type="submit" class="btn btn-sm btn-success fw-bold"><i class="bi bi-check-lg"></i> Confirm & Approve</button>
                                                                        </div>
                                                                    </form>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-muted py-3">No pending staff profiles to approve.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- অ্যাপ্রুভড ডাক্তারদের ডিরেক্টরি -->
                    <div class="card shadow-sm border-0 mb-4 border-start border-4 border-success">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                            <h5 class="mb-0 fw-bold text-success"><i class="bi bi-person-badge me-2"></i>Approved Doctors & Attendance Status</h5>
                            <a href="doctor_list.php" class="btn btn-sm btn-outline-success">View All Doctors</a>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>SL#</th>
                                            <th>Doctor Name</th>
                                            <th>Department</th>
                                            <th>Phone</th>
                                            <th>Current Presence Status</th>
                                            <th class="text-center">Update Attendance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($approved_doctor_list)): $sl = 1; ?>
                                            <?php foreach ($approved_doctor_list as $doc): ?>
                                                <tr>
                                                    <td><?= $sl++; ?></td>
                                                    <td><strong>Dr. <?= htmlspecialchars($doc['full_name']); ?></strong></td>
                                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($doc['department_name'] ?: 'General'); ?></span></td>
                                                    <td><?= htmlspecialchars($doc['phone_number'] ?: 'N/A'); ?></td>
                                                    <td>
                                                        <?php 
                                                            $avail = $doc['availability_status'] ?? 'Present';
                                                            if (in_array($avail, ['Available', 'Present'])) {
                                                                echo '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> 🟢 Present / Available</span>';
                                                            } elseif ($avail == 'Absent') {
                                                                echo '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i> 🔴 Absent</span>';
                                                            } elseif ($avail == 'On Leave') {
                                                                echo '<span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i> 🟡 On Leave</span>';
                                                            } else {
                                                                echo '<span class="badge bg-secondary">' . htmlspecialchars($avail) . '</span>';
                                                            }
                                                        ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <form method="POST" class="d-flex justify-content-center align-items-center gap-1">
                                                            <input type="hidden" name="update_doc_status_id" value="<?= $doc['doctor_id']; ?>">
                                                            <select name="new_doc_status" class="form-select form-select-sm w-auto">
                                                                <option value="Present" <?= (in_array($avail, ['Available', 'Present'])) ? 'selected' : ''; ?>>🟢 Present</option>
                                                                <option value="Absent" <?= ($avail == 'Absent') ? 'selected' : ''; ?>>🔴 Absent</option>
                                                                <option value="On Leave" <?= ($avail == 'On Leave') ? 'selected' : ''; ?>>🟡 On Leave</option>
                                                                <option value="Busy in OT" <?= ($avail == 'Busy in OT') ? 'selected' : ''; ?>>🏥 Busy in OT</option>
                                                            </select>
                                                            <button type="submit" class="btn btn-sm btn-primary fw-bold">Update</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="6" class="text-center text-muted py-3">No approved doctors found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- অ্যাপ্রুভড স্টাফদের ডিরেক্টরি -->
                    <div class="card shadow-sm border-0 mb-4 border-start border-4 border-primary">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                            <h5 class="mb-0 fw-bold text-primary"><i class="bi bi-people-fill me-2"></i>Approved Hospital Staff & Attendance Status</h5>
                            <div>
                                <span class="badge bg-success me-2"><?= count($approved_staff_list); ?> Staff Members</span>
                                <a href="manage_staff.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-list-ul me-1"></i> View All Staff</a>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>SL#</th>
                                            <th>Staff Name</th>
                                            <th>Role</th>
                                            <th>Qualification</th>
                                            <th>Phone</th>
                                            <th>Current Status</th>
                                            <th class="text-center">Update Presence</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($approved_staff_list)): $sl = 1; ?>
                                            <?php foreach ($approved_staff_list as $ast): ?>
                                                <tr>
                                                    <td><?= $sl++; ?></td>
                                                    <td>
                                                        <strong><?= htmlspecialchars($ast['full_name']); ?></strong><br>
                                                        <small class="text-muted"><?= htmlspecialchars($ast['email']); ?></small>
                                                    </td>
                                                    <td><span class="badge bg-info text-dark"><?= htmlspecialchars($ast['role']); ?></span></td>
                                                    <td>
                                                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($ast['degree'] ?: 'N/A'); ?></span>
                                                    </td>
                                                    <td><?= htmlspecialchars($ast['phone_number'] ?: 'N/A'); ?></td>
                                                    <td>
                                                        <?php 
                                                            $s_st = $ast['status'] ?? 'Active';
                                                            if (in_array($s_st, ['Active', 'Present'])) {
                                                                echo '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> 🟢 Present</span>';
                                                            } elseif ($s_st == 'Absent') {
                                                                echo '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i> 🔴 Absent</span>';
                                                            } elseif ($s_st == 'On Leave') {
                                                                echo '<span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i> 🟡 On Leave</span>';
                                                            } else {
                                                                echo '<span class="badge bg-secondary">' . htmlspecialchars($s_st) . '</span>';
                                                            }
                                                        ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <form method="POST" class="d-flex justify-content-center align-items-center gap-1">
                                                            <input type="hidden" name="update_staff_status_id" value="<?= $ast['user_id']; ?>">
                                                            <select name="new_staff_status" class="form-select form-select-sm w-auto">
                                                                <option value="Present" <?= (in_array($s_st, ['Active', 'Present'])) ? 'selected' : ''; ?>>🟢 Present</option>
                                                                <option value="Absent" <?= ($s_st == 'Absent') ? 'selected' : ''; ?>>🔴 Absent</option>
                                                                <option value="On Leave" <?= ($s_st == 'On Leave') ? 'selected' : ''; ?>>🟡 On Leave</option>
                                                            </select>
                                                            <button type="submit" class="btn btn-sm btn-primary fw-bold">Update</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-muted py-3">No approved hospital staff found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- নিবন্ধিত রোগীদের তালিকা -->
                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-people me-2 text-primary"></i>Registered Patients Directory</h5>
                            <a href="patient_list.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-list-ul"></i> View All Patients</a>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>SL#</th>
                                            <th>Patient ID</th>
                                            <th>Full Name</th>
                                            <th>Gender</th>
                                            <th>Phone</th>
                                            <th>Blood Group</th>
                                            <th>National ID</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($recent_patients)): $sl = 1; ?>
                                            <?php foreach ($recent_patients as $row): ?>
                                                <tr>
                                                    <td><?= $sl++; ?></td>
                                                    <td>#PAT-<?= htmlspecialchars($row['patient_id']); ?></td>
                                                    <td><strong><?= htmlspecialchars($row['full_name'] ?? 'N/A'); ?></strong></td>
                                                    <td><?= htmlspecialchars($row['gender'] ?? 'N/A'); ?></td>
                                                    <td><?= htmlspecialchars($row['phone'] ?? 'N/A'); ?></td>
                                                    <td><span class="badge bg-danger"><?= htmlspecialchars($row['blood_group'] ?? 'N/A'); ?></span></td>
                                                    <td><?= htmlspecialchars($row['national_id'] ?? 'N/A'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-muted py-3">No registered patients found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                <!-- পেশেন্ট ড্যাশবোর্ড স্ক্রিন -->
                <?php elseif ($role == 'Patient'): ?>
                    <div class="card border-0 shadow-sm bg-white p-4">
                        <?php
                            $my_cabin = null;
                            try {
                                $stmt_u = $pdo->prepare("SELECT phone_number, full_name FROM users WHERE user_id = ?");
                                $stmt_u->execute([$user_id]);
                                $curr_u  = $stmt_u->fetch(PDO::FETCH_ASSOC);
                                
                                $u_phone = trim($curr_u['phone_number'] ?? '');
                                $u_name  = trim($curr_u['full_name'] ?? $full_name);

                                if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['patient_sos_call'])) {
                                    $b_id = intval($_POST['bed_id'] ?? 0);
                                    if ($b_id > 0) {
                                        $sos_msg = "🚨 NURSE NEEDED: Patient called from bed!";
                                        $pdo->prepare("UPDATE cabins_beds SET emergency_note = ? WHERE bed_id = ?")->execute([$sos_msg, $b_id]);
                                        $message = "Emergency alert sent to the Nurse Station successfully!";
                                        $message_type = "danger";
                                    }
                                }

                                if (!empty($u_phone)) {
                                    $pdo->prepare("UPDATE patients SET user_id = ? WHERE phone = ? OR REPLACE(phone,' ','') = REPLACE(?,' ','')")->execute([$user_id, $u_phone, $u_phone]);
                                }
                                if (!empty($u_name)) {
                                    $pdo->prepare("UPDATE patients SET user_id = ? WHERE LOWER(TRIM(full_name)) = LOWER(TRIM(?))")->execute([$user_id, $u_name]);
                                }

                                $p_id = 0;
                                $stmt_find_p = $pdo->prepare("SELECT patient_id FROM patients WHERE user_id = ? OR (full_name IS NOT NULL AND LOWER(TRIM(full_name)) = LOWER(TRIM(?))) OR (phone IS NOT NULL AND phone != '' AND REPLACE(phone, ' ', '') = REPLACE(?, ' ', '')) LIMIT 1");
                                $stmt_find_p->execute([$user_id, $u_name, $u_phone]);
                                $p_row = $stmt_find_p->fetch(PDO::FETCH_ASSOC);

                                if ($p_row) {
                                    $p_id = intval($p_row['patient_id']);
                                }

                                if ($p_id > 0) {
                                    $stmt_b = $pdo->prepare("SELECT cb.bed_id, cb.bed_number, cb.cabin_type, cb.daily_rate, cb.availability_status, cb.patient_id, cb.emergency_note, p.full_name AS patient_name, p.phone AS patient_phone 
                                                             FROM cabins_beds cb 
                                                             JOIN patients p ON cb.patient_id = p.patient_id 
                                                             WHERE cb.patient_id = ? AND cb.availability_status = 'Occupied' 
                                                             ORDER BY cb.bed_id DESC LIMIT 1");
                                    $stmt_b->execute([$p_id]);
                                    $my_cabin = $stmt_b->fetch(PDO::FETCH_ASSOC);
                                }

                            } catch (PDOException $e) {
                                $my_cabin = null;
                            }
                        ?>
                        
                        <?php if ($my_cabin): ?>
                            <div class="card border-danger shadow-sm mb-4">
                                <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center py-2">
                                    <h5 class="mb-0"><i class="bi bi-building me-2"></i>My Admitted Bed & Cabin Details</h5>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="bed_id" value="<?= $my_cabin['bed_id']; ?>">
                                        <input type="hidden" name="patient_sos_call" value="1">
                                        <button type="submit" class="btn btn-light btn-sm text-danger fw-bold shadow-sm" onclick="return confirm('Send urgent emergency signal to the nurses?');">
                                            <i class="bi bi-bell-fill me-1"></i> 🚨 CALL NURSE (EMERGENCY)
                                        </button>
                                    </form>
                                </div>
                                <div class="card-body bg-light">
                                    <div class="row g-3">
                                        <div class="col-md-3">
                                            <span class="text-muted d-block small">Bed ID (`bed_id`)</span>
                                            <strong>#<?= htmlspecialchars($my_cabin['bed_id']); ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <span class="text-muted d-block small">Bed / Room No (`bed_number`)</span>
                                            <span class="badge bg-primary fs-6"><?= htmlspecialchars($my_cabin['bed_number']); ?></span>
                                        </div>
                                        <div class="col-md-3">
                                            <span class="text-muted d-block small">Cabin Type (`cabin_type`)</span>
                                            <span class="badge bg-secondary fs-6"><?= htmlspecialchars($my_cabin['cabin_type']); ?></span>
                                        </div>
                                        <div class="col-md-3">
                                            <span class="text-muted d-block small">Daily Rate (`daily_rate`)</span>
                                            <strong class="text-success">৳<?= number_format($my_cabin['daily_rate'], 2); ?> / day</strong>
                                        </div>
                                        <div class="col-md-3">
                                            <span class="text-muted d-block small">Availability Status</span>
                                            <span class="badge bg-danger"><?= htmlspecialchars($my_cabin['availability_status']); ?></span>
                                        </div>
                                        <div class="col-md-3">
                                            <span class="text-muted d-block small">Assigned Patient ID (`patient_id`)</span>
                                            <strong>#PAT-<?= htmlspecialchars($my_cabin['patient_id']); ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <span class="text-muted d-block small">Patient Name</span>
                                            <strong><?= htmlspecialchars($my_cabin['patient_name']); ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <span class="text-muted d-block small">Emergency Alert Status</span>
                                            <?= !empty($my_cabin['emergency_note']) ? '<span class="badge bg-warning text-dark fw-bold">🚨 Alert Active</span>' : '<span class="badge bg-success">Normal</span>'; ?>
                                        </div>
                                    </div>

                                    <?php if (!empty($my_cabin['emergency_note'])): ?>
                                        <div class="alert alert-danger border-danger mt-3 mb-0 p-2 fw-bold small">
                                            <i class="bi bi-exclamation-triangle-fill me-1"></i> Status: <?= htmlspecialchars($my_cabin['emergency_note']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="fw-bold text-primary mb-0"><i class="bi bi-person-circle me-2"></i>Welcome to Patient Portal, <?= htmlspecialchars($full_name); ?>!</h4>
                            <div>
                                <a href="modules.php?tab=lab" class="btn btn-primary fw-bold me-2"><i class="bi bi-eyedropper me-1"></i> Request Lab Test</a>
                                <a href="modules.php?tab=blood" class="btn btn-danger fw-bold me-2"><i class="bi bi-droplet-fill me-1"></i> Request Blood</a>
                                <a href="emergency_dispatch.php" class="btn btn-outline-danger fw-bold"><i class="bi bi-truck me-1"></i> Request Ambulance</a>
                            </div>
                        </div>
                        <p class="text-muted">You can book appointments, apply for lab diagnostic tests, request emergency blood or ambulance, and view hospital bills from your portal.</p>
                        
                        <div class="row g-3 mt-2">
                            <div class="col-md-3">
                                <div class="card bg-light border-0 p-3 text-center h-100 shadow-sm">
                                    <i class="bi bi-calendar-check fs-1 text-primary mb-2"></i>
                                    <h5>Book Appointment</h5>
                                    <p class="small text-muted mb-3">Schedule a visit with specialist doctors.</p>
                                    <a href="book_appointment.php" class="btn btn-sm btn-outline-primary mt-auto fw-bold">Book Now</a>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-light border-0 p-3 text-center h-100 shadow-sm">
                                    <i class="bi bi-journal-medical fs-1 text-success mb-2"></i>
                                    <h5>My Appointments</h5>
                                    <p class="small text-muted mb-3">Check your booked appointments and status.</p>
                                    <a href="appointments.php" class="btn btn-sm btn-outline-success mt-auto fw-bold">View Appointments</a>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-light border-0 p-3 text-center h-100 shadow-sm border-start border-4 border-info">
                                    <i class="bi bi-eyedropper fs-1 text-info mb-2"></i>
                                    <h5 class="text-info fw-bold">Request Lab Test</h5>
                                    <p class="small text-muted mb-3">Apply for lab test (In-Person / Home Sample).</p>
                                    <a href="modules.php?tab=lab" class="btn btn-sm btn-info text-white fw-bold mt-auto">Apply for Test</a>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-light border-0 p-3 text-center h-100 shadow-sm">
                                    <i class="bi bi-droplet-fill fs-1 text-danger mb-2"></i>
                                    <h5 class="text-danger fw-bold">Request Blood</h5>
                                    <p class="small text-muted mb-3">Submit emergency blood request to blood bank.</p>
                                    <a href="modules.php?tab=blood" class="btn btn-sm btn-danger fw-bold mt-auto">Request Now</a>
                                </div>
                            </div>
                        </div>
                    </div>

                <!-- সাধারণ স্টাফ ড্যাশবোর্ড -->
                <?php elseif (in_array($role, ['Nurse', 'Pharmacist', 'Lab Technician', 'Accountant', 'Receptionist'])): ?>
                    <div class="card border-0 shadow-sm bg-white p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="text-primary fw-bold mb-0">Welcome, <?= htmlspecialchars($full_name); ?>!</h4>
                            <a href="edit_profile.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-person-gear me-1"></i> Edit My Profile</a>
                        </div>

                        <?php if ($role == 'Nurse'): ?>
                            <h5 class="fw-bold mb-3 text-primary border-bottom pb-2 mt-4">১. আমাদের Hospital ERP সিস্টেমে আপনার কাজ:</h5>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <div class="card bg-light border-0 p-4 text-center h-100 shadow-sm">
                                        <i class="bi bi-people fs-1 text-info mb-2"></i>
                                        <h6 class="fw-bold">রোগীদের তথ্য দেখা (Patient Directory)</h6>
                                        <p class="small text-muted mb-3">হাসপাতালে নিবন্ধিত এবং ভর্তি থাকা রোগীদের তালিকা ও তথ্য পর্যবেক্ষণ করা।</p>
                                        <a href="patient_list.php" class="btn btn-sm btn-info text-white fw-bold mt-auto">View Patients</a>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-light border-0 p-4 text-center h-100 shadow-sm">
                                        <i class="bi bi-journal-medical fs-1 text-primary mb-2"></i>
                                        <h6 class="fw-bold">ডাক্তারের শিডিউল (Appointments)</h6>
                                        <p class="small text-muted mb-3">ডাক্তারদের ভিজিটিং সময়, সিরিয়াল এবং অ্যাপয়েন্টমেন্টের তালিকা দেখা।</p>
                                        <a href="appointments.php" class="btn btn-sm btn-primary fw-bold mt-auto">Check Appointments</a>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-light border-0 p-4 text-center h-100 shadow-sm">
                                        <i class="bi bi-door-open fs-1 text-warning mb-2"></i>
                                        <h6 class="fw-bold">কেবিন ও বেড (Cabin & Bed Status)</h6>
                                        <p class="small text-muted mb-3">হাসপাতালের কোন কেবিন বা বেডে কোন রোগী আছেন তা চেক করা এবং ফাঁকা বেডের হিসাব রাখা।</p>
                                        <a href="cabins.php" class="btn btn-sm btn-warning text-dark fw-bold mt-auto">View Beds & Cabins</a>
                                    </div>
                                </div>
                            </div>
                        <?php elseif ($role == 'Receptionist'): ?>
                            
                            <?php if (!empty($pending_blood_list)): ?>
                                <div class="card border-0 shadow-sm mb-4 border-start border-4 border-danger">
                                    <div class="card-header bg-danger bg-opacity-10 d-flex justify-content-between align-items-center py-3">
                                        <h5 class="mb-0 fw-bold text-danger"><i class="bi bi-droplet-half me-2"></i>Pending Patient Blood Requests</h5>
                                        <a href="modules.php?tab=blood" class="btn btn-sm btn-danger fw-bold"><i class="bi bi-box-arrow-up-right me-1"></i> Open Blood Bank</a>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-hover align-middle">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>#ID</th>
                                                        <th>Requester Name</th>
                                                        <th>Phone</th>
                                                        <th>Blood Group</th>
                                                        <th>Bags Needed</th>
                                                        <th>Need Date</th>
                                                        <th class="text-center">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($pending_blood_list as $bl): ?>
                                                        <tr>
                                                            <td><strong>#REC-<?= $bl['id']; ?></strong></td>
                                                            <td><strong><?= htmlspecialchars($bl['full_name'] ?: 'N/A'); ?></strong></td>
                                                            <td><?= htmlspecialchars($bl['phone'] ?: 'N/A'); ?></td>
                                                            <td><span class="badge bg-danger fs-6"><?= htmlspecialchars($bl['blood_group']); ?></span></td>
                                                            <td><span class="badge bg-dark"><?= htmlspecialchars($bl['bags_count']); ?> Bag(s)</span></td>
                                                            <td><?= htmlspecialchars($bl['need_date'] ?: 'Urgent'); ?></td>
                                                            <td class="text-center">
                                                                <form method="POST" class="d-inline">
                                                                    <input type="hidden" name="approve_blood_id" value="<?= $bl['id']; ?>">
                                                                    <button type="submit" class="btn btn-sm btn-success fw-bold me-1">
                                                                        <i class="bi bi-check-circle me-1"></i> Accept & Issue Blood
                                                                    </button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($active_ambulances_list)): ?>
                                <div class="card border-0 shadow-sm mb-4 border-start border-4 border-danger">
                                    <div class="card-header bg-danger bg-opacity-10 d-flex justify-content-between align-items-center py-3">
                                        <h5 class="mb-0 text-danger fw-bold">
                                            <i class="bi bi-truck me-2"></i>Incoming Patient Ambulance Calls & Requests
                                        </h5>
                                        <a href="emergency_dispatch.php" class="btn btn-sm btn-danger fw-bold"><i class="bi bi-list-ul me-1"></i> View All Calls & Dispatch</a>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-hover align-middle mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Req #</th>
                                                        <th>Caller / Patient</th>
                                                        <th>Phone</th>
                                                        <th>Pickup Location Address</th>
                                                        <th>Severity</th>
                                                        <th>Status</th>
                                                        <th class="text-center">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($active_ambulances_list as $amb): ?>
                                                        <tr>
                                                            <td><span class="badge bg-dark">#AMB-<?= $amb['dispatch_id']; ?></span></td>
                                                            <td><strong><?= htmlspecialchars($amb['caller_name'] ?? 'N/A'); ?></strong></td>
                                                            <td class="text-primary fw-bold"><?= htmlspecialchars($amb['caller_phone'] ?? 'N/A'); ?></td>
                                                            <td>
                                                                <i class="bi bi-geo-alt-fill text-danger me-1"></i>
                                                                <strong><?= htmlspecialchars($amb['pickup_location'] ?? 'N/A'); ?></strong>
                                                            </td>
                                                            <td>
                                                                <?php if (strpos(($amb['severity_level'] ?? ''), 'Critical') !== false): ?>
                                                                    <span class="badge bg-danger"><?= htmlspecialchars($amb['severity_level']); ?></span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-warning text-dark"><?= htmlspecialchars($amb['severity_level'] ?? 'Normal'); ?></span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php if (($amb['status'] ?? 'Pending') == 'Pending'): ?>
                                                                    <span class="badge bg-danger">🚨 Pending Call</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-success">Dispatched / On the Way</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="text-center">
                                                                <?php if (($amb['status'] ?? 'Pending') == 'Pending'): ?>
                                                                    <form method="POST" class="d-inline me-1">
                                                                        <input type="hidden" name="dispatch_id" value="<?= $amb['dispatch_id']; ?>">
                                                                        <input type="hidden" name="action_dispatch" value="1">
                                                                        <button type="submit" class="btn btn-sm btn-danger fw-bold">
                                                                            <i class="bi bi-send-fill me-1"></i> Quick Dispatch
                                                                        </button>
                                                                    </form>
                                                                    <a href="emergency_dispatch.php?dispatch_id=<?= $amb['dispatch_id']; ?>" class="btn btn-sm btn-outline-danger fw-bold">
                                                                        Assign Driver
                                                                    </a>
                                                                <?php else: ?>
                                                                    <span class="text-success small fw-bold"><i class="bi bi-check-circle-fill"></i> Dispatched</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="card border-0 shadow-sm mb-4">
                                <div class="card-header bg-warning bg-opacity-25 d-flex justify-content-between align-items-center py-3">
                                    <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-clock-history me-2"></i>Pending Patient Appointments to Approve</h5>
                                    <span class="badge bg-warning text-dark"><?= count($pending_appointments_rec); ?> Pending</span>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Appt #</th>
                                                    <th>Patient Details</th>
                                                    <th>Assigned Doctor</th>
                                                    <th>Date & Mode</th>
                                                    <th>Fee, Method & TrxID</th>
                                                    <th class="text-center">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!empty($pending_appointments_rec)): foreach ($pending_appointments_rec as $pa): ?>
                                                    <tr>
                                                        <td><strong>#APPT-<?= $pa['appointment_id']; ?></strong></td>
                                                        <td>
                                                            <strong><?= htmlspecialchars($pa['patient_name'] ?? 'N/A'); ?></strong><br>
                                                            <small class="text-muted"><?= htmlspecialchars($pa['patient_phone'] ?? 'No Phone'); ?></small>
                                                        </td>
                                                        <td><strong>Dr. <?= htmlspecialchars($pa['doctor_name'] ?? 'N/A'); ?></strong></td>
                                                        <td>
                                                            <span class="badge bg-info text-dark"><?= htmlspecialchars($pa['appointment_date'] ?? $pa['scheduled_time'] ?? ''); ?></span><br>
                                                            <small class="text-dark fw-semibold"><?= htmlspecialchars($pa['appointment_time'] ?? ''); ?></small><br>
                                                            <?php if (($pa['consultation_type'] ?? '') == 'Online'): ?>
                                                                <span class="badge bg-primary mt-1"><i class="bi bi-globe me-1"></i>Online Call</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary mt-1"><i class="bi bi-building me-1"></i>In-Person</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <strong class="text-primary fw-bold">৳<?= number_format($pa['consultation_fee'] ?? 1000, 2); ?></strong><br>
                                                            <?php if (!empty($pa['payment_method'])): ?>
                                                                <small class="d-block text-dark fw-semibold">
                                                                    💳 <?= htmlspecialchars($pa['payment_method']); ?>
                                                                </small>
                                                            <?php endif; ?>
                                                            <?php if (!empty($pa['transaction_id'])): ?>
                                                                <span class="badge bg-warning text-dark border border-dark mt-1" style="font-family: monospace;">
                                                                    TrxID: <strong><?= htmlspecialchars($pa['transaction_id']); ?></strong>
                                                                </span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <form method="POST" class="d-inline">
                                                                <input type="hidden" name="dash_app_id" value="<?= $pa['appointment_id']; ?>">
                                                                <input type="hidden" name="dash_app_action" value="Confirmed">
                                                                <button type="submit" class="btn btn-sm btn-success fw-bold me-1" onclick="return confirm('TrxID ba Cash taka shothik bhabe jachai korechhen? Confirm korte OK chapun.');">
                                                                    <i class="bi bi-check-circle me-1"></i> Approve & Send to Doctor
                                                                </button>
                                                            </form>
                                                            <form method="POST" class="d-inline">
                                                                <input type="hidden" name="dash_app_id" value="<?= $pa['appointment_id']; ?>">
                                                                <input type="hidden" name="dash_app_action" value="Cancelled">
                                                                <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Reject this appointment?');">
                                                                    <i class="bi bi-x-circle me-1"></i> Cancel
                                                                </button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; else: ?>
                                                    <tr>
                                                        <td colspan="6" class="text-center text-muted py-3">No pending online appointments to approve.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="card shadow-sm border-0 mb-4 border-start border-4 border-success">
                                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                                    <h5 class="mb-0 fw-bold text-success"><i class="bi bi-person-badge me-2"></i>Doctors Attendance Status Control</h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>SL#</th>
                                                    <th>Doctor Name</th>
                                                    <th>Department</th>
                                                    <th>Phone</th>
                                                    <th>Current Status</th>
                                                    <th class="text-center">Update Attendance</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!empty($approved_doctor_list)): $sl = 1; ?>
                                                    <?php foreach ($approved_doctor_list as $doc): ?>
                                                        <tr>
                                                            <td><?= $sl++; ?></td>
                                                            <td><strong>Dr. <?= htmlspecialchars($doc['full_name']); ?></strong></td>
                                                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($doc['department_name'] ?: 'General'); ?></span></td>
                                                            <td><?= htmlspecialchars($doc['phone_number'] ?: 'N/A'); ?></td>
                                                            <td>
                                                                <?php 
                                                                    $avail = $doc['availability_status'] ?? 'Present';
                                                                    if (in_array($avail, ['Available', 'Present'])) {
                                                                        echo '<span class="badge bg-success">🟢 Present</span>';
                                                                    } elseif ($avail == 'Absent') {
                                                                        echo '<span class="badge bg-danger">🔴 Absent</span>';
                                                                    } elseif ($avail == 'On Leave') {
                                                                        echo '<span class="badge bg-warning text-dark">🟡 On Leave</span>';
                                                                    } else {
                                                                        echo '<span class="badge bg-secondary">' . htmlspecialchars($avail) . '</span>';
                                                                    }
                                                                ?>
                                                            </td>
                                                            <td class="text-center">
                                                                <form method="POST" class="d-flex justify-content-center align-items-center gap-1">
                                                                    <input type="hidden" name="update_doc_status_id" value="<?= $doc['doctor_id']; ?>">
                                                                    <select name="new_doc_status" class="form-select form-select-sm w-auto">
                                                                        <option value="Present" <?= (in_array($avail, ['Available', 'Present'])) ? 'selected' : ''; ?>>🟢 Present</option>
                                                                        <option value="Absent" <?= ($avail == 'Absent') ? 'selected' : ''; ?>>🔴 Absent</option>
                                                                        <option value="On Leave" <?= ($avail == 'On Leave') ? 'selected' : ''; ?>>🟡 On Leave</option>
                                                                        <option value="Busy in OT" <?= ($avail == 'Busy in OT') ? 'selected' : ''; ?>>🏥 Busy in OT</option>
                                                                    </select>
                                                                    <button type="submit" class="btn btn-sm btn-primary fw-bold">Save</button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="6" class="text-center text-muted py-3">No active doctors found.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-3">
                                    <div class="card bg-light border-0 p-3 text-center h-100">
                                        <i class="bi bi-person-plus fs-1 text-primary mb-2"></i>
                                        <h5>Patient Registration</h5>
                                        <p class="small text-muted mb-2">Register new patients at frontdesk.</p>
                                        <a href="patient_registration.php" class="btn btn-sm btn-primary">Register Patient</a>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-light border-0 p-3 text-center h-100">
                                        <i class="bi bi-people fs-1 text-info mb-2"></i>
                                        <h5>Patient Directory</h5>
                                        <p class="small text-muted mb-2">View registered patient details.</p>
                                        <a href="patient_list.php" class="btn btn-sm btn-info text-white">View Patients</a>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-light border-0 p-3 text-center h-100">
                                        <i class="bi bi-calendar-check fs-1 text-success mb-2"></i>
                                        <h5>Appointments</h5>
                                        <p class="small text-muted mb-2">Manage doctor appointment schedules.</p>
                                        <a href="book_appointment.php" class="btn btn-sm btn-success">Manage Appointments</a>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-light border-0 p-3 text-center h-100">
                                        <i class="bi bi-door-open fs-1 text-warning mb-2"></i>
                                        <h5>Cabin & Bed Mgmt</h5>
                                        <p class="small text-muted mb-2">Assign and manage hospital beds.</p>
                                        <a href="assign_bed.php" class="btn btn-sm btn-warning text-dark fw-bold">Assign Bed</a>
                                    </div>
                                </div>
                            </div>
                        <?php elseif ($role == 'Pharmacist'): ?>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="card bg-light border-0 p-3 text-center h-100">
                                        <i class="bi bi-capsule fs-1 text-success mb-2"></i>
                                        <h5>Pharmacy & Medicine</h5>
                                        <p class="small text-muted mb-2">Manage medicine inventory and dispensing.</p>
                                        <a href="pharmacy.php" class="btn btn-sm btn-success">Open Pharmacy</a>
                                    </div>
                                </div>
                            </div>
                        <?php elseif ($role == 'Lab Technician'): ?>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="card bg-light border-0 p-3 text-center h-100">
                                        <i class="bi bi-eyedropper fs-1 text-danger mb-2"></i>
                                        <h5>Lab Tests</h5>
                                        <p class="small text-muted mb-2">Manage pathology lab reports and test orders.</p>
                                        <a href="modules.php" class="btn btn-sm btn-danger">Manage Lab Tests</a>
                                    </div>
                                </div>
                            </div>
                        <?php elseif ($role == 'Accountant'): ?>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="card bg-light border-0 p-4 text-center h-100 shadow-sm">
                                        <i class="bi bi-receipt fs-1 text-success mb-2"></i>
                                        <h5 class="fw-bold">Billing & Invoices</h5>
                                        <p class="small text-muted mb-3">Collect consultation fees, issue invoices, and manage patient bills.</p>
                                        <a href="billing.php" class="btn btn-sm btn-success fw-bold px-4">Manage Billing</a>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-light border-0 p-4 text-center h-100 shadow-sm">
                                        <i class="bi bi-cash-coin fs-1 text-primary mb-2"></i>
                                        <h5 class="fw-bold">Payroll & Accounts</h5>
                                        <p class="small text-muted mb-3">Manage hospital staff payroll and accounting reports.</p>
                                        <a href="finance.php" class="btn btn-sm btn-primary fw-bold px-4">Payroll & Finance</a>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-light border-0 p-4 text-center h-100 shadow-sm">
                                        <i class="bi bi-people fs-1 text-info mb-2"></i>
                                        <h5 class="fw-bold">Patient Directory</h5>
                                        <p class="small text-muted mb-3">View patient services, prescriptions & generate bills directly.</p>
                                        <a href="patient_list.php" class="btn btn-sm btn-info text-white fw-bold px-4">View Patient Services</a>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <p class="text-muted mt-4 mb-0">Use the left sidebar menu to edit your qualifications/degree or navigate to hospital modules.</p>
                    </div>

                <!-- ডাক্তার ড্যাশবোর্ড -->
                <?php elseif ($role == 'Doctor'): ?>
                    <div class="card border-0 shadow-sm bg-white p-4">
                        <h4 class="text-primary fw-bold mb-3"><i class="bi bi-hospital me-2"></i>Welcome, Dr. <?= htmlspecialchars($full_name); ?></h4>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <div class="card bg-light border-0 p-3 text-center h-100 shadow-sm">
                                    <i class="bi bi-journal-medical fs-1 text-primary mb-2"></i>
                                    <h5>My Appointments</h5>
                                    <p class="small text-muted mb-2">Check patient appointment requests.</p>
                                    <a href="appointments.php" class="btn btn-sm btn-primary mt-auto">View Appointments</a>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-light border-0 p-3 text-center h-100 shadow-sm">
                                    <i class="bi bi-prescription2 fs-1 text-success mb-2"></i>
                                    <h5>Write Prescription</h5>
                                    <p class="small text-muted mb-2">Create new prescriptions for patients.</p>
                                    <a href="add_prescription.php" class="btn btn-sm btn-success mt-auto">Write Prescription</a>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-light border-0 p-3 text-center h-100 shadow-sm">
                                    <i class="bi bi-droplet-fill fs-1 text-danger mb-2"></i>
                                    <h5 class="text-danger fw-bold">Request Blood</h5>
                                    <p class="small text-muted mb-2">Submit urgent blood requirements for patients.</p>
                                    <a href="modules.php?tab=blood" class="btn btn-sm btn-danger fw-bold mt-auto">Request Blood</a>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-light border-0 p-3 text-center h-100 shadow-sm">
                                    <i class="bi bi-scissors fs-1 text-dark mb-2"></i>
                                    <h5>OT & Lab Orders</h5>
                                    <p class="small text-muted mb-2">Schedule surgeries and lab tests.</p>
                                    <a href="modules.php" class="btn btn-sm btn-dark mt-auto">Open Modules</a>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <div class="alert alert-secondary shadow-sm p-4">
                        <h4 class="fw-bold">Welcome, <?= htmlspecialchars($full_name); ?>!</h4>
                        <p class="mb-0">Logged in with role: <strong><?= htmlspecialchars($role); ?></strong>.</p>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>