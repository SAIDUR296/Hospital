<?php
session_start();
require 'db_connect.php';

// ১. লগইন চেক
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// ২. ইনভয়েস আইডি রিসিভ করা
$invoice_id = intval($_GET['id'] ?? $_GET['invoice_id'] ?? 0);
$invoice = null;

if ($invoice_id > 0) {
    try {
        // billing_invoices টেবিল থেকে ডাটা ফেচ
        $stmt = $pdo->prepare("SELECT b.*, p.full_name AS p_name, p.phone AS p_phone 
                               FROM billing_invoices b 
                               LEFT JOIN patients p ON b.patient_id = p.patient_id 
                               WHERE b.invoice_id = ?");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Database Error: " . $e->getMessage());
    }
}

if (!$invoice) {
    die("<div style='text-align:center; padding: 50px;'><h3>Invoice not found!</h3><a href='billing.php' class='btn btn-primary'>Back to Billing</a></div>");
}

// পেশেন্টের নাম ও ফোন নম্বর (টেবিল বা JOIN থেকে ব্যাকআপ)
$patient_name  = !empty($invoice['patient_name']) ? $invoice['patient_name'] : ($invoice['p_name'] ?? 'N/A');
$patient_phone = !empty($invoice['phone']) ? $invoice['phone'] : ($invoice['p_phone'] ?? 'N/A');

// ডাটাবেজের সঠিক কলাম অনুযায়ী ভ্যারিয়েবল সেট
$total_amount = floatval($invoice['total_amount'] ?? 0);
$discount     = floatval($invoice['discount'] ?? 0);
$net_payable  = floatval($invoice['net_payable'] ?? $total_amount);
$status       = $invoice['payment_status'] ?? 'Paid';
$invoice_date = date('d M Y, h:i A', strtotime($invoice['invoice_date'] ?? 'now'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #INV-<?= $invoice['invoice_id']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .receipt-card { max-width: 550px; margin: 40px auto; border-radius: 12px; background: #ffffff; border: none; }
        @media print {
            .no-print { display: none !important; }
            body { background: #ffffff; }
            .receipt-card { border: none !important; box-shadow: none !important; margin: 0 auto; width: 100%; }
        }
    </style>
</head>
<body>

<div class="card receipt-card shadow p-4">
    <div class="text-center mb-3">
        <h3 class="fw-bold text-primary mb-1">SMART HOSPITAL ERP</h3>
        <p class="text-muted small mb-0">Official Payment Receipt</p>
    </div>
    <hr>

    <div class="row mb-3">
        <div class="col-7">
            <strong>Invoice:</strong> #INV-<?= $invoice['invoice_id']; ?><br>
            <strong>Patient Name:</strong> <?= htmlspecialchars($patient_name); ?><br>
            <strong>Phone:</strong> <?= htmlspecialchars($patient_phone); ?>
        </div>
        <div class="col-5 text-end">
            <strong>Date:</strong> <?= $invoice_date; ?><br>
            <strong>Status:</strong> <span class="badge bg-success"><?= htmlspecialchars($status); ?></span>
        </div>
    </div>

    <table class="table table-bordered align-middle">
        <thead class="table-light">
            <tr>
                <th>Description</th>
                <th class="text-end">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Hospital Services Total Charge</td>
                <td class="text-end">৳<?= number_format($total_amount, 2); ?></td>
            </tr>
            <?php if ($discount > 0): ?>
            <tr>
                <td class="text-danger">Discount Allowed</td>
                <td class="text-end text-danger">-৳<?= number_format($discount, 2); ?></td>
            </tr>
            <?php endif; ?>
            <tr class="table-success fw-bold">
                <td>Paid Amount (Net Payable):</td>
                <td class="text-end">৳<?= number_format($net_payable, 2); ?></td>
            </tr>
        </tbody>
    </table>

    <div class="d-flex justify-content-center gap-2 mt-3 no-print">
        <button onclick="window.print()" class="btn btn-success fw-bold"><i class="bi bi-printer"></i> Print</button>
        <a href="billing.php" class="btn btn-secondary fw-bold">Back to Billing</a>
    </div>
</div>

</body>
</html>