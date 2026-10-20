<?php
session_start();
require 'db_connect.php';

// ১. সিকিউরিটি ও রোল চেক (SUDHU ADMIN ACCESS PAABE, Accountant er jonno restricted)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    // Accountant ba onno kew ashle sorasori billing.php te redirect hoye jabe
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'Accountant') {
        header("Location: billing.php");
    } else {
        header("Location: login.php");
    }
    exit();
}

$role      = $_SESSION['role'] ?? 'Admin';
$full_name = $_SESSION['full_name'] ?? 'User';
$message   = "";
$message_type = "";

// 🚀 Database Schema Auto-Fix (Fixes "1054 Unknown column 'notification_id'" error)
try {
    $pdo->exec("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS notification_id INT AUTO_INCREMENT UNIQUE");
} catch (PDOException $e) {}

// -------------------------------------------------------------
// ২. ফর্ম সাবমিশন প্রসেসিং (Admin Only - Finance & Payroll)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // ক. ফাইন্যান্স ট্রানজ্যাকশন (আয়/ব্যয়) যোগ করা
    if (isset($_POST['record_transaction'])) {
        $type   = $_POST['transaction_type'];
        $head   = trim($_POST['account_head']);
        $amount = floatval($_POST['amount'] ?? 0);

        if (!empty($head) && $amount > 0) {
            try {
                $sql = "INSERT INTO finance_transactions (transaction_type, account_head, amount) VALUES (?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$type, $head, $amount]);
                
                $message = "Finance transaction recorded successfully!";
                $message_type = "success";
            } catch (PDOException $e) {
                $message = "Database Error: " . $e->getMessage();
                $message_type = "danger";
            }
        } else {
            $message = "Please provide valid account head and amount!";
            $message_type = "warning";
        }
    }

    // খ. পে-রোল (বেতন) জেনারেট করা
    if (isset($_POST['generate_payroll'])) {
        $staff_id   = intval($_POST['staff_id'] ?? 0);
        $month_year = trim($_POST['month_year'] ?? '');
        $basic      = floatval($_POST['basic_salary'] ?? 0);
        $net        = floatval($_POST['net_salary'] ?? 0);
        $status     = $_POST['status'] ?? 'Generated';

        if ($staff_id > 0 && !empty($month_year) && $basic > 0 && $net > 0) {
            try {
                $sql = "INSERT INTO payroll (staff_id, month_year, basic_salary, net_salary, status) VALUES (?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$staff_id, $month_year, $basic, $net, $status]);

                if ($status === 'Paid') {
                    $u_stmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
                    $u_stmt->execute([$staff_id]);
                    $st_name = $u_stmt->fetchColumn() ?: 'Staff';
                    $head = "Staff Salary ($month_year) - " . $st_name;

                    $ins_exp = $pdo->prepare("INSERT INTO finance_transactions (transaction_type, account_head, amount) VALUES ('Expense', ?, ?)");
                    $ins_exp->execute([$head, $net]);
                }
                
                $message = "Payroll generated successfully for staff!";
                $message_type = "success";
            } catch (PDOException $e) {
                $message = "Database Error: " . $e->getMessage();
                $message_type = "danger";
            }
        } else {
            $message = "Please select valid staff, month/year, and salary details!";
            $message_type = "warning";
        }
    }

    // গ. পে-রোল স্ট্যাটাস আপডেট করা
    if (isset($_POST['update_payroll_status'])) {
        $payroll_id = intval($_POST['payroll_id']);
        $new_status = $_POST['status'];

        try {
            $check_stmt = $pdo->prepare("SELECT status FROM payroll WHERE payroll_id = ?");
            $check_stmt->execute([$payroll_id]);
            $old_status = $check_stmt->fetchColumn();

            $stmt = $pdo->prepare("UPDATE payroll SET status = ? WHERE payroll_id = ?");
            $stmt->execute([$new_status, $payroll_id]);

            if ($new_status === 'Paid' && $old_status !== 'Paid') {
                $pr_stmt = $pdo->prepare("SELECT pr.net_salary, pr.month_year, u.full_name 
                                           FROM payroll pr 
                                           JOIN users u ON pr.staff_id = u.user_id 
                                           WHERE pr.payroll_id = ?");
                $pr_stmt->execute([$payroll_id]);
                $pr_data = $pr_stmt->fetch(PDO::FETCH_ASSOC);

                if ($pr_data) {
                    $head    = "Staff Salary (" . $pr_data['month_year'] . ") - " . $pr_data['full_name'];
                    $net_amt = floatval($pr_data['net_salary']);

                    $ins_exp = $pdo->prepare("INSERT INTO finance_transactions (transaction_type, account_head, amount) VALUES ('Expense', ?, ?)");
                    $ins_exp->execute([$head, $net_amt]);
                }
                $message = "Payroll marked as 'Paid' and ৳" . number_format($net_amt, 2) . " successfully added to Expenses!";
                $message_type = "success";
            } else {
                $message = "Payroll status updated to '$new_status' successfully!";
                $message_type = "info";
            }

        } catch (PDOException $e) {
            $message = "Update Error: " . $e->getMessage();
            $message_type = "danger";
        }
    }

    // ঘ. স্টাফ উইথড্রয়াল রিকোয়েস্ট অ্যাকশন
    if (isset($_POST['action_withdrawal'])) {
        $withdrawal_notif_id = intval($_POST['withdrawal_notif_id'] ?? 0);
        $w_action = $_POST['w_action'] ?? '';
        $w_amount = floatval($_POST['w_amount'] ?? 0);
        $w_staff_name = trim($_POST['w_staff_name'] ?? 'Staff');

        if ($withdrawal_notif_id > 0) {
            try {
                if ($w_action === 'approve') {
                    $pdo->prepare("UPDATE notifications SET priority = 'Completed', target_role = 'Approved' WHERE id = ?")->execute([$withdrawal_notif_id]);

                    $head = "Salary Bank Withdrawal Disbursed - " . $w_staff_name;
                    $pdo->prepare("INSERT INTO finance_transactions (transaction_type, account_head, amount) VALUES ('Expense', ?, ?)")->execute([$head, $w_amount]);

                    $message = "Bank Withdrawal of ৳" . number_format($w_amount, 2) . " Approved & Disbursed successfully for $w_staff_name!";
                    $message_type = "success";
                } elseif ($w_action === 'reject') {
                    $pdo->prepare("UPDATE notifications SET priority = 'Rejected', target_role = 'Rejected' WHERE id = ?")->execute([$withdrawal_notif_id]);
                    $message = "Withdrawal request rejected.";
                    $message_type = "warning";
                }
            } catch (PDOException $e) {
                $message = "Withdrawal Error: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }

    // 🚀 ঙ. ফার্মাসিস্টের সাপ্লায়ার বিল এপ্রুভ ও পে করার অ্যাকশন (Fixed WHERE Query)
    if (isset($_POST['action_supplier_bill'])) {
        $supplier_notif_id = intval($_POST['supplier_notif_id'] ?? 0);
        $s_action = $_POST['s_action'] ?? '';
        $s_amount = floatval($_POST['s_amount'] ?? 0);
        $s_title  = trim($_POST['s_title'] ?? 'Medicine Stock Purchase');

        if ($supplier_notif_id > 0) {
            try {
                if ($s_action === 'approve') {
                    $pdo->prepare("UPDATE notifications SET priority = 'Completed', target_role = 'Approved' WHERE id = ?")->execute([$supplier_notif_id]);

                    // Total Expense-এ অটো যোগ হওয়া
                    $head = "Medicine Stock Purchase - " . $s_title;
                    $pdo->prepare("INSERT INTO finance_transactions (transaction_type, account_head, amount) VALUES ('Expense', ?, ?)")->execute([$head, $s_amount]);

                    $message = "Supplier Bill of ৳" . number_format($s_amount, 2) . " Approved & Paid! Added to Total Expense.";
                    $message_type = "success";
                } elseif ($s_action === 'reject') {
                    $pdo->prepare("UPDATE notifications SET priority = 'Rejected', target_role = 'Rejected' WHERE id = ?")->execute([$supplier_notif_id]);
                    $message = "Supplier Bill request rejected.";
                    $message_type = "warning";
                }
            } catch (PDOException $e) {
                $message = "Supplier Bill Action Error: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }
}

// -------------------------------------------------------------
// ৩. ডাটা ফেচ করা (Admin Dashboard)
// -------------------------------------------------------------
$transactions     = [];
$payrolls         = [];
$active_staff     = [];
$withdrawals      = [];
$supplier_bills   = [];
$total_income     = 0.00;
$total_expense    = 0.00;

try {
    $stmt_tr = $pdo->query("SELECT * FROM finance_transactions ORDER BY transaction_id DESC");
    if ($stmt_tr) $transactions = $stmt_tr->fetchAll(PDO::FETCH_ASSOC);

    // ১. Billing Invoices (Paid Cash Collection) থেকে আয়
    $invoice_income = 0.00;
    try {
        $check_inv = $pdo->query("SHOW TABLES LIKE 'billing_invoices'");
        if ($check_inv && $check_inv->rowCount() > 0) {
            $stmt_inv = $pdo->query("SELECT COALESCE(SUM(net_payable), 0) FROM billing_invoices WHERE payment_status = 'Paid'");
            if ($stmt_inv) $invoice_income = floatval($stmt_inv->fetchColumn() ?: 0);
        }
    } catch (PDOException $e) {}

    // ২. Pharmacy Sales টেবিল থাকলে সেখান থেকে অতিরিক্ত বিক্রির টাকা ফেচ করা
    $pharmacy_direct_sales = 0.00;
    try {
        $check_ps = $pdo->query("SHOW TABLES LIKE 'pharmacy_sales'");
        if ($check_ps && $check_ps->rowCount() > 0) {
            $stmt_ps = $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM pharmacy_sales");
            if ($stmt_ps) $pharmacy_direct_sales = floatval($stmt_ps->fetchColumn() ?: 0);
        }
    } catch (PDOException $e) {}
// 🚀 FIXED: শুধুমাত্র finance_transactions থেকে সঠিক মোট আয় হিসাব (Double Count বন্ধ)
    $stmt_inc = $pdo->query("SELECT COALESCE(SUM(amount), 0.00) FROM finance_transactions WHERE transaction_type = 'Income'");
    if ($stmt_inc) {
        $total_income = floatval($stmt_inc->fetchColumn() ?: 0);
    }

    // 🚀 মোট ব্যয় (Expense) হিসাব
    $stmt_exp = $pdo->query("SELECT COALESCE(SUM(amount), 0.00) FROM finance_transactions WHERE transaction_type = 'Expense'");
    if ($stmt_exp) {
        $total_expense = floatval($stmt_exp->fetchColumn() ?: 0);
    }

    // স্টাফ পে-রোল লিস্ট ফেচ করা
    $stmt_pr = $pdo->query("SELECT pr.*, u.full_name AS staff_name, u.role AS staff_role 
                            FROM payroll pr 
                            JOIN users u ON pr.staff_id = u.user_id 
                            ORDER BY pr.payroll_id DESC");
    if ($stmt_pr) $payrolls = $stmt_pr->fetchAll(PDO::FETCH_ASSOC);

    // অ্যাক্টিভ স্টাফ লিস্ট ফেচ করা
    $stmt_st = $pdo->query("SELECT user_id, full_name, role, monthly_salary, bank_name, account_number FROM users WHERE status IN ('Active', 'Present') AND role NOT IN ('Patient') ORDER BY full_name ASC");
    if ($stmt_st) $active_staff = $stmt_st->fetchAll(PDO::FETCH_ASSOC);

    // উইথড্রয়াল রিকোয়েস্ট নোটিফিকেশন ফেচ করা
    try {
        $stmt_w = $pdo->query("SELECT * FROM notifications WHERE title LIKE '%Salary Withdrawal%' OR title LIKE '%Withdrawal%' ORDER BY created_at DESC");
        if ($stmt_w) $withdrawals = $stmt_w->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // ৪. ফার্মাসিস্টের পাঠানো সাপ্লায়ার বিল ও রিকোয়েস্ট ফেচ করা
    try {
        $stmt_s = $pdo->query("SELECT * FROM notifications WHERE title LIKE '%Supplier%' OR title LIKE '%Stock Purchase%' OR message LIKE '%Supplier%' ORDER BY created_at DESC");
        if ($stmt_s) $supplier_bills = $stmt_s->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

} catch (PDOException $e) {
    $message = "Fetch Error: " . $e->getMessage();
    $message_type = "danger";
}

$net_balance = $total_income - $total_expense;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance & Payroll - Smart Hospital ERP</title>
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
    <!-- সাইডবার -->
    <div class="sidebar p-3">
        <h4 class="text-center text-white mb-4"><i class="bi bi-hospital"></i> Hospital ERP</h4>
        <hr class="text-secondary">
        <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        
        <div class="nav-category">Hospital Management</div>
        <a href="finance.php" class="active"><i class="bi bi-cash-coin"></i> Payroll & Finance</a>
        <a href="billing.php"><i class="bi bi-receipt"></i> Billing & Invoices</a>
        <a href="patient_list.php"><i class="bi bi-people"></i> Patient Directory</a>

        <hr class="text-secondary mt-4">
        <a href="logout.php" class="text-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>

    <!-- মূল কনটেন্ট -->
    <div class="w-100">
        <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm px-4 py-3 mb-4">
            <div class="container-fluid">
                <span class="navbar-brand mb-0 h1">Hospital Finance & Payroll Management</span>
                <div class="d-flex align-items-center">
                    <span class="me-3 text-muted">Welcome, <strong><?= htmlspecialchars($full_name); ?></strong> (<?= htmlspecialchars($role); ?>)</span>
                    <a href="logout.php" class="btn btn-sm btn-outline-danger">Logout</a>
                </div>
            </div>
        </nav>

        <div class="container-fluid px-4">

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $message_type; ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- নেভিগেশন ট্যাব -->
            <ul class="nav nav-tabs mb-4" id="financeTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active fw-bold" id="transactions-tab" data-bs-toggle="tab" data-bs-target="#transactions-pane" type="button" role="tab">
                        <i class="bi bi-cash-stack text-success me-1"></i> Income & Expenses (<?= count($transactions); ?>)
                    </button>
                </li>
                <!-- Supplier Bills Tab -->
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold text-warning text-dark" id="supplier-tab" data-bs-toggle="tab" data-bs-target="#supplier-pane" type="button" role="tab">
                        <i class="bi bi-truck me-1"></i> Supplier Bills (<?= count($supplier_bills); ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold" id="payroll-tab" data-bs-toggle="tab" data-bs-target="#payroll-pane" type="button" role="tab">
                        <i class="bi bi-wallet2 text-primary me-1"></i> Staff Payroll (<?= count($payrolls); ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold text-danger" id="withdrawal-tab" data-bs-toggle="tab" data-bs-target="#withdrawal-pane" type="button" role="tab">
                        <i class="bi bi-bank me-1"></i> Bank Withdrawals (<?= count($withdrawals); ?>)
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="financeTabContent">
                
                <!-- ১. ফাইন্যান্স ট্রানজ্যাকশন ট্যাব -->
                <div class="tab-pane fade show active" id="transactions-pane" role="tabpanel">
                    
                    <!-- ফাইন্যান্স সামারি কার্ডস -->
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div class="card bg-success text-white p-3 shadow-sm border-0">
                                <h6 class="text-white-50">Total Income</h6>
                                <h3 class="fw-bold mb-0">৳<?= number_format($total_income, 2); ?></h3>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card bg-danger text-white p-3 shadow-sm border-0">
                                <h6 class="text-white-50">Total Expense</h6>
                                <h3 class="fw-bold mb-0">৳<?= number_format($total_expense, 2); ?></h3>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card <?= ($net_balance >= 0) ? 'bg-primary' : 'bg-warning text-dark'; ?> text-white p-3 shadow-sm border-0">
                                <h6 class="opacity-75">Net Operating Balance</h6>
                                <h3 class="fw-bold mb-0">৳<?= number_format($net_balance, 2); ?></h3>
                            </div>
                        </div>
                    </div>

                    <!-- ট্রানজ্যাকশন এন্ট্রি ফর্ম -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-success text-white py-3">
                            <h6 class="mb-0 fw-bold"><i class="bi bi-plus-circle me-1"></i> Record Income / Expense Transaction</h6>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" action="finance.php">
                                <input type="hidden" name="record_transaction" value="1">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Transaction Type <span class="text-danger">*</span></label>
                                        <select name="transaction_type" class="form-select" required>
                                            <option value="Income">Income (+)</option>
                                            <option value="Expense">Expense (-)</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-5">
                                        <label class="form-label fw-semibold">Account Head / Purpose <span class="text-danger">*</span></label>
                                        <select name="account_head" class="form-select" required>
                                            <option value="">-- Select Account Head / Purpose --</option>
                                            <optgroup label="Income Categories">
                                                <option value="Hospital Bill Collection">Hospital Bill Collection</option>
                                                <option value="Patient Consultation Fee">Patient Consultation Fee</option>
                                                <option value="Lab Test Fee">Lab Test Fee</option>
                                                <option value="Pharmacy Sales">Pharmacy Sales</option>
                                                <option value="Emergency Ambulance Fee">Emergency Ambulance Fee</option>
                                                <option value="Other Income">Other Income</option>
                                            </optgroup>
                                            <optgroup label="Expense Categories">
                                                <option value="Staff Salary">Staff Salary</option>
                                                <option value="Utility Electricity Bill">Utility Electricity Bill</option>
                                                <option value="Medicine Stock Purchase">Medicine Stock Purchase</option>
                                                <option value="Hospital Maintenance">Hospital Maintenance</option>
                                                <option value="Other Expense">Other Expense</option>
                                            </optgroup>
                                        </select>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Amount (BDT) <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" name="amount" class="form-control" required placeholder="0.00" min="0.01">
                                    </div>
                                    <div class="col-12 text-end">
                                        <button type="submit" class="btn btn-success fw-bold px-4">
                                            <i class="bi bi-check-circle me-1"></i> Record Transaction
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- ট্রানজ্যাকশন ডিরেক্টরি টেবিল -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-list-ul me-1"></i> Transaction History Logs</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Type</th>
                                            <th>Account Head</th>
                                            <th>Amount</th>
                                            <th>Transaction Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($transactions)): foreach ($transactions as $tr): ?>
                                            <tr>
                                                <td>#TXN-<?= $tr['transaction_id']; ?></td>
                                                <td>
                                                    <span class="badge <?= ($tr['transaction_type'] == 'Income') ? 'bg-success' : 'bg-danger'; ?>">
                                                        <?= $tr['transaction_type']; ?>
                                                    </span>
                                                </td>
                                                <td><strong><?= htmlspecialchars($tr['account_head']); ?></strong></td>
                                                <td class="fw-bold <?= ($tr['transaction_type'] == 'Income') ? 'text-success' : 'text-danger'; ?>">
                                                    <?= ($tr['transaction_type'] == 'Income') ? '+' : '-'; ?>৳<?= number_format($tr['amount'], 2); ?>
                                                </td>
                                                <td><small class="text-muted"><?= date('d M Y, h:i A', strtotime($tr['transaction_date'])); ?></small></td>
                                            </tr>
                                        <?php endforeach; else: ?>
                                            <tr><td colspan="5" class="text-center py-4 text-muted">No finance transactions recorded yet.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- ২. ফার্মাসিস্টের পাঠানো সাপ্লায়ার বিল ট্যাব -->
                <div class="tab-pane fade" id="supplier-pane" role="tabpanel">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-warning text-dark py-3 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold"><i class="bi bi-truck me-2"></i> Pharmacist Supplier Requisitions & Bills</h6>
                            <span class="badge bg-dark text-white fw-bold"><?= count($supplier_bills); ?> Requests</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Bill ID</th>
                                            <th>Supplier / Request Title</th>
                                            <th>Medicine & Bill Details</th>
                                            <th>Date & Time</th>
                                            <th>Status</th>
                                            <th class="text-center">Action (Approve & Pay)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($supplier_bills)): foreach ($supplier_bills as $sb): 
                                            $s_id = $sb['id'] ?? 0;
                                            $s_st = $sb['priority'] ?? 'Normal';
                                            
                                            if (preg_match('/Total Bill Amount:\s*BDT\s*([0-9.,]+)/i', $sb['message'] ?? '', $s_amt_m)) {
                                                                $s_amt_val = floatval(str_replace(',', '', $s_amt_m[1]));
                                                            } else if (preg_match('/BDT\s*([0-9.,]+)/i', $sb['message'] ?? '', $s_amt_m)) {
                                                                $s_amt_val = floatval(str_replace(',', '', $s_amt_m[1]));
                                                            } else {
                                                                $s_amt_val = 0.00;
                                                            }
                                            
                                        ?>
                                            <tr>
                                                <td><span class="badge bg-secondary">#SUP-<?= $s_id; ?></span></td>
                                                <td>
                                                    <strong class="text-dark"><?= htmlspecialchars($sb['title']); ?></strong>
                                                </td>
                                                <td>
                                                    <p class="mb-0 text-muted small" style="white-space: pre-line;"><?= htmlspecialchars($sb['message']); ?></p>
                                                </td>
                                                <td><small class="text-muted"><?= htmlspecialchars($sb['created_at']); ?></small></td>
                                                <td>
                                                    <?php if ($s_st === 'Completed' || $sb['target_role'] === 'Approved'): ?>
                                                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Paid & Expense Recorded</span>
                                                    <?php elseif ($s_st === 'Rejected' || $sb['target_role'] === 'Rejected'): ?>
                                                        <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i> Rejected</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i> Pending Payment</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($s_st !== 'Completed' && $sb['target_role'] !== 'Approved'): ?>
                                                        <form method="POST" class="d-inline-flex gap-1">
                                                            <input type="hidden" name="supplier_notif_id" value="<?= $s_id; ?>">
                                                            <input type="hidden" name="s_amount" value="<?= $s_amt_val; ?>">
                                                            <input type="hidden" name="s_title" value="<?= htmlspecialchars($sb['title']); ?>">
                                                            <input type="hidden" name="action_supplier_bill" value="1">
                                                            
                                                            <button type="submit" name="s_action" value="approve" class="btn btn-sm btn-success fw-bold" onclick="return confirm('Confirm payment of ৳<?= number_format($s_amt_val, 2); ?> to supplier?');">
                                                                <i class="bi bi-check-lg"></i> Approve & Pay
                                                            </button>
                                                            <button type="submit" name="s_action" value="reject" class="btn btn-sm btn-outline-danger" onclick="return confirm('Reject this supplier bill?');">
                                                                <i class="bi bi-x-lg"></i>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-success small fw-bold"><i class="bi bi-check-all fs-5"></i> Paid</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; else: ?>
                                            <tr><td colspan="6" class="text-center py-4 text-muted">No pending supplier bills found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ৩. স্টাফ পে-রোল ট্যাব -->
                <div class="tab-pane fade" id="payroll-pane" role="tabpanel">
                    
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-primary text-white py-3">
                            <h6 class="mb-0 fw-bold"><i class="bi bi-person-workspace me-1"></i> Generate Monthly Staff Payroll</h6>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" action="finance.php">
                                <input type="hidden" name="generate_payroll" value="1">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Select Staff Member <span class="text-danger">*</span></label>
                                        <select name="staff_id" id="staff_select" class="form-select" onchange="autoFillSalary()" required>
                                            <option value="">-- Choose Staff --</option>
                                            <?php foreach ($active_staff as $st): ?>
                                                <option value="<?= $st['user_id']; ?>" data-salary="<?= $st['monthly_salary'] ?? 0; ?>">
                                                    <?= htmlspecialchars($st['full_name']); ?> (<?= htmlspecialchars($st['role']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Month-Year <span class="text-danger">*</span></label>
                                        <input type="month" name="month_year" class="form-control" required value="<?= date('Y-m'); ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">Basic Salary (BDT)</label>
                                        <input type="number" step="0.01" name="basic_salary" id="basic_salary" class="form-control" required placeholder="0.00">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">Net Payable Salary</label>
                                        <input type="number" step="0.01" name="net_salary" id="net_salary" class="form-control" required placeholder="0.00">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">Initial Status</label>
                                        <select name="status" class="form-select">
                                            <option value="Generated">Generated</option>
                                            <option value="Approved">Approved</option>
                                            <option value="Paid">Paid</option>
                                        </select>
                                    </div>
                                    <div class="col-12 text-end">
                                        <button type="submit" class="btn btn-primary fw-bold px-4">
                                            <i class="bi bi-check-circle me-1"></i> Process Payroll
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-list-check me-1"></i> Staff Salary Payroll Records</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Payroll ID</th>
                                            <th>Staff Name & Role</th>
                                            <th>Month / Year</th>
                                            <th>Basic Salary</th>
                                            <th>Net Salary</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($payrolls)): foreach ($payrolls as $pr): ?>
                                            <tr>
                                                <td>#PAY-<?= $pr['payroll_id']; ?></td>
                                                <td>
                                                    <strong><?= htmlspecialchars($pr['staff_name']); ?></strong><br>
                                                    <span class="badge bg-secondary"><?= htmlspecialchars($pr['staff_role']); ?></span>
                                                </td>
                                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($pr['month_year']); ?></span></td>
                                                <td>৳<?= number_format($pr['basic_salary'], 2); ?></td>
                                                <td class="fw-bold text-success">৳<?= number_format($pr['net_salary'], 2); ?></td>
                                                <td>
                                                    <?php
                                                        $pr_bg = ($pr['status'] == 'Paid') ? 'bg-success' : (($pr['status'] == 'Approved') ? 'bg-primary' : 'bg-warning text-dark');
                                                    ?>
                                                    <span class="badge <?= $pr_bg; ?>"><?= $pr['status']; ?></span>
                                                </td>
                                                <td>
                                                    <form method="POST" class="d-flex gap-1">
                                                        <input type="hidden" name="payroll_id" value="<?= $pr['payroll_id']; ?>">
                                                        <select name="status" class="form-select form-select-sm" style="width: 110px;">
                                                            <option value="Generated" <?= $pr['status'] == 'Generated' ? 'selected' : ''; ?>>Generated</option>
                                                            <option value="Approved" <?= $pr['status'] == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                                                            <option value="Paid" <?= $pr['status'] == 'Paid' ? 'selected' : ''; ?>>Paid</option>
                                                        </select>
                                                        <button type="submit" name="update_payroll_status" class="btn btn-sm btn-outline-secondary">Save</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; else: ?>
                                            <tr><td colspan="7" class="text-center py-4 text-muted">No staff payroll records generated yet.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- ৪. ব্যাংক উইথড্রয়াল রিকোয়েস্ট সেশন -->
                <div class="tab-pane fade" id="withdrawal-pane" role="tabpanel">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-danger text-white py-3 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold"><i class="bi bi-bank me-2"></i> Staff & Doctor Bank Withdrawal Requests</h6>
                            <span class="badge bg-light text-danger fw-bold"><?= count($withdrawals); ?> Requests</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Req ID</th>
                                            <th>Notification / Request Info</th>
                                            <th>Message & Bank Details</th>
                                            <th>Date & Time</th>
                                            <th>Current Status</th>
                                            <th class="text-center">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($withdrawals)): foreach ($withdrawals as $w): 
                                            $nid = $w['id'] ?? 0;
                                            $st_val = $w['priority'] ?? 'Normal';
                                            
                                            preg_match('/BDT\s*([0-9.]+)/i', $w['message'] ?? '', $m_amt);
                                            preg_match('/from\s*([^:]+)/i', $w['title'] ?? '', $m_name);
                                            
                                            $amt_val = !empty($m_amt[1]) ? floatval($m_amt[1]) : 0.00;
                                            $st_name_val = !empty($m_name[1]) ? trim($m_name[1]) : 'Staff Member';
                                        ?>
                                            <tr>
                                                <td><span class="badge bg-dark">#REQ-<?= $nid; ?></span></td>
                                                <td>
                                                    <strong class="text-primary"><?= htmlspecialchars($w['title']); ?></strong><br>
                                                    <span class="badge bg-info text-dark"><?= htmlspecialchars($w['target_role']); ?></span>
                                                </td>
                                                <td>
                                                    <p class="mb-0 text-dark small" style="white-space: pre-line;"><?= htmlspecialchars($w['message']); ?></p>
                                                </td>
                                                <td><small class="text-muted"><?= htmlspecialchars($w['created_at']); ?></small></td>
                                                <td>
                                                    <?php if ($st_val === 'Completed' || $w['target_role'] === 'Approved'): ?>
                                                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Disbursed</span>
                                                    <?php elseif ($st_val === 'Rejected' || $w['target_role'] === 'Rejected'): ?>
                                                        <span class="badge bg-secondary"><i class="bi bi-x-circle me-1"></i> Rejected</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i> Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($st_val !== 'Completed' && $w['target_role'] !== 'Approved'): ?>
                                                        <form method="POST" class="d-inline-flex gap-1">
                                                            <input type="hidden" name="withdrawal_notif_id" value="<?= $nid; ?>">
                                                            <input type="hidden" name="w_amount" value="<?= $amt_val; ?>">
                                                            <input type="hidden" name="w_staff_name" value="<?= htmlspecialchars($st_name_val); ?>">
                                                            <input type="hidden" name="action_withdrawal" value="1">
                                                            
                                                            <button type="submit" name="w_action" value="approve" class="btn btn-sm btn-success fw-bold" onclick="return confirm('Confirm bank transfer and disburse ৳<?= number_format($amt_val, 2); ?>?');">
                                                                <i class="bi bi-check-lg"></i> Approve & Disburse
                                                            </button>
                                                            <button type="submit" name="w_action" value="reject" class="btn btn-sm btn-outline-danger" onclick="return confirm('Reject this withdrawal request?');">
                                                                <i class="bi bi-x-lg"></i>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-success small fw-bold"><i class="bi bi-check-all fs-5"></i> Settled</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; else: ?>
                                            <tr><td colspan="6" class="text-center py-4 text-muted">No pending salary withdrawal requests found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
function autoFillSalary() {
    var select = document.getElementById('staff_select');
    var selectedOption = select.options[select.selectedIndex];
    var salary = selectedOption.getAttribute('data-salary');
    if (salary) {
        document.getElementById('basic_salary').value = salary;
        document.getElementById('net_salary').value = salary;
    }
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>