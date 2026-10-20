<?php
session_start();
require 'db_connect.php';

// ১. লগইন সিকিউরিটি চেক
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$role      = $_SESSION['role'] ?? 'Staff';
$full_name = $_SESSION['full_name'] ?? 'User';
$message   = "";
$message_type = "";

// -------------------------------------------------------------
// ২. ফর্ম সাবমিশন প্রসেসিং (Pharmacy & Inventory)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // 🚀 ওষুধ বিক্রি / ডিসপেন্স করা, স্টক আপডেট (Deduct) এবং Finance-এ Auto-Income যোগ করার লজিক
    if (isset($_POST['sell_medicine'])) {
        $med_id   = intval($_POST['medicine_id'] ?? 0);
        $sell_qty = intval($_POST['sell_quantity'] ?? 0);

        if ($med_id > 0 && $sell_qty > 0) {
            try {
                // বর্তমান স্টক ও দাম চেক করা
                $stmt = $pdo->prepare("SELECT medicine_name, stock_quantity, unit_price FROM pharmacy_medicines WHERE medicine_id = ?");
                $stmt->execute([$med_id]);
                $med = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($med) {
                    if ($med['stock_quantity'] >= $sell_qty) {
                        $new_qty = $med['stock_quantity'] - $sell_qty;
                        $total_sale_price = floatval($med['unit_price']) * $sell_qty;

                        $pdo->beginTransaction();

                        // ১. স্টক কমবে
                        $update_stmt = $pdo->prepare("UPDATE pharmacy_medicines SET stock_quantity = ? WHERE medicine_id = ?");
                        $update_stmt->execute([$new_qty, $med_id]);

                        // ২. অটোমেটিক ফাইন্যান্স প্যানেলে ইনকাম যোগ হবে (Auto Finance Income Sync)
                        $fin_head = "Pharmacy Sales - " . $med['medicine_name'] . " ($sell_qty Pcs)";
                        $fin_stmt = $pdo->prepare("INSERT INTO finance_transactions (transaction_type, account_head, amount) VALUES ('Income', ?, ?)");
                        $fin_stmt->execute([$fin_head, $total_sale_price]);

                        $pdo->commit();

                        $message = "Successfully sold " . $sell_qty . " pcs of " . htmlspecialchars($med['medicine_name']) . "! ৳" . number_format($total_sale_price, 2) . " added to Total Income.";
                        $message_type = "success";
                    } else {
                        $message = "Insufficient stock! Available stock is only " . $med['stock_quantity'] . " pcs.";
                        $message_type = "danger";
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $message = "Database Error: " . $e->getMessage();
                $message_type = "danger";
            }
        } else {
            $message = "Please enter a valid quantity to sell.";
            $message_type = "warning";
        }
    }

    // ফার্মেসির ওষুধ যোগ করা
    if (isset($_POST['add_medicine'])) {
        $med_name   = trim($_POST['medicine_name']);
        $generic    = trim($_POST['generic_name']);
        $supplier   = trim($_POST['supplier_info']);
        $batch      = trim($_POST['batch_number']);
        $qty        = intval($_POST['stock_quantity']);
        $price      = floatval($_POST['unit_price']);
        $expiry     = $_POST['expiry_date'] ?: null;
        $threshold  = intval($_POST['low_stock_threshold'] ?? 10);

        try {
            $sql = "INSERT INTO pharmacy_medicines (medicine_name, generic_name, supplier_info, batch_number, stock_quantity, unit_price, expiry_date, low_stock_threshold) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$med_name, $generic, $supplier, $batch, $qty, $price, $expiry, $threshold]);
            
            $message = "Medicine added to pharmacy inventory successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Database Error: " . $e->getMessage();
            $message_type = "danger";
        }
    }

    // জেনারেল ইনভেন্টরি আইটেম যোগ করা
    if (isset($_POST['add_inventory_item'])) {
        $item_name  = trim($_POST['item_name']);
        $category   = trim($_POST['category']);
        $supplier   = trim($_POST['supplier_info']);
        $qty        = intval($_POST['quantity_in_stock']);
        $cost       = floatval($_POST['unit_cost']);
        $reorder    = intval($_POST['reorder_level'] ?? 5);

        try {
            $sql = "INSERT INTO inventory_items (item_name, category, supplier_info, quantity_in_stock, unit_cost, reorder_level) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$item_name, $category, $supplier, $qty, $cost, $reorder]);
            
            $message = "Inventory item added successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Database Error: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// -------------------------------------------------------------
// ৩. ডাটা ফেচ করা (Pharmacy Medicines & Inventory Items)
// -------------------------------------------------------------
$medicines       = [];
$inventory_items = [];

try {
    $stmt_med = $pdo->query("SELECT * FROM pharmacy_medicines ORDER BY medicine_id DESC");
    if ($stmt_med) $medicines = $stmt_med->fetchAll(PDO::FETCH_ASSOC);

    $stmt_inv = $pdo->query("SELECT * FROM inventory_items ORDER BY item_id DESC");
    if ($stmt_inv) $inventory_items = $stmt_inv->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "Fetch Error: " . $e->getMessage();
    $message_type = "danger";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy & Inventory - Smart Hospital ERP</title>
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

<!-- 🚀 [UPDATED PART] BANGLADESH PERSPECTIVE DATALISTS FOR AUTO-SUGGEST & DROP-DOWN SELECT -->
<datalist id="bd_medicine_names">
    <option value="Napa Extra 500mg (Tablet)">
    <option value="Napa 500mg (Tablet)">
    <option value="Ace 500mg (Tablet)">
    <option value="Sergel 20mg (Capsule)">
    <option value="Seclo 20mg (Capsule)">
    <option value="Maxpro 20mg (Capsule)">
    <option value="Pantonix 20mg (Tablet)">
    <option value="Cef-3 200mg (Capsule)">
    <option value="Monas 10mg (Tablet)">
    <option value="Fenadin 120mg (Tablet)">
    <option value="Altrum 500mg (Tablet)">
    <option value="Savlon Liquid (100ml)">
    <option value="Entacyd 150mg (Tablet)">
    <option value="Bizoran 5/20 (Tablet)">
    <option value="Esonix 20mg (Capsule)">
</datalist>

<datalist id="bd_generic_names">
    <option value="Paracetamol + Caffeine">
    <option value="Paracetamol">
    <option value="Esomeprazole">
    <option value="Omeprazole">
    <option value="Pantoprazole">
    <option value="Cefixime">
    <option value="Montelukast">
    <option value="Fexofenadine">
    <option value="Azithromycin">
    <option value="Chlorhexidine + Cetrimide">
</datalist>

<datalist id="bd_pharma_suppliers">
    <option value="Square Pharmaceuticals Ltd.">
    <option value="Beximco Pharmaceuticals Ltd.">
    <option value="Incepta Pharmaceuticals Ltd.">
    <option value="Renata Limited">
    <option value="Healthcare Pharmaceuticals Ltd.">
    <option value="Opsonin Pharma Ltd.">
    <option value="ACME Laboratories Ltd.">
    <option value="SK+F (Eskayef Pharmaceuticals)">
    <option value="Aristopharma Ltd.">
    <option value="Drug International Ltd.">
</datalist>

<datalist id="bd_inventory_items">
    <option value="Surgical Gloves (Box)">
    <option value="Disposable Syringe 5ml (Box)">
    <option value="Disposable Syringe 10ml (Box)">
    <option value="Infusion Set / Saline Set">
    <option value="IV Cannula 20G / 22G">
    <option value="Normal Saline 1000ml">
    <option value="Cholera Saline 1000ml">
    <option value="Absorbent Cotton Roll (500g)">
    <option value="Gauze Bandage Roll">
    <option value="Alcohol Prep Pad (Box)">
    <option value="Adhesive Medical Tape / Leucoplast">
    <option value="Digital Thermometer">
    <option value="Face Mask 3-Ply (Box)">
</datalist>

<datalist id="bd_inventory_categories">
    <option value="Medical Equipment">
    <option value="Surgical Supplies">
    <option value="Disposable Items">
    <option value="Saline & IV Fluids">
    <option value="First Aid & Bandages">
    <option value="Cleaning & Hygiene">
    <option value="Lab & Diagnostic Consumables">
</datalist>

<div class="d-flex">
    <!-- সাইডবার -->
    <div class="sidebar p-3">
        <h4 class="text-center text-white mb-4"><i class="bi bi-hospital"></i> Hospital ERP</h4>
        <hr class="text-secondary">
        <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="pharmacy.php" class="active"><i class="bi bi-capsule"></i> Pharmacy & Inventory</a>
        <a href="patient_list.php"><i class="bi bi-people"></i> Patient Directory</a>
        <hr class="text-secondary mt-4">
        <a href="logout.php" class="text-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>

    <!-- মূল কনটেন্ট -->
    <div class="w-100">
        <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm px-4 py-3 mb-4">
            <div class="container-fluid">
                <span class="navbar-brand mb-0 h1">Pharmacy & Hospital Inventory Management</span>
                <div class="d-flex align-items-center">
                    <span class="me-3 text-muted">Welcome, <strong><?= htmlspecialchars($full_name); ?></strong></span>
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

            <!-- নেভিগেশন ট্যাব (Pharmacy vs Inventory) -->
            <ul class="nav nav-tabs mb-4" id="inventoryTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active fw-bold" id="pharmacy-tab" data-bs-toggle="tab" data-bs-target="#pharmacy-pane" type="button" role="tab">
                        <i class="bi bi-capsule text-success me-1"></i> Pharmacy Medicines (<?= count($medicines); ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold" id="inventory-tab" data-bs-toggle="tab" data-bs-target="#inventory-pane" type="button" role="tab">
                        <i class="bi bi-box-seam text-primary me-1"></i> General Inventory Items (<?= count($inventory_items); ?>)
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="inventoryTabContent">
                
                <!-- ১. ফার্মেসি মেডিসিন ট্যাব -->
                <div class="tab-pane fade show active" id="pharmacy-pane" role="tabpanel">
                    
                    <!-- ওষুধ এন্ট্রি ফর্ম -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-success text-white py-3">
                            <h6 class="mb-0 fw-bold"><i class="bi bi-plus-circle me-1"></i> Add New Medicine to Stock</h6>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" action="pharmacy.php">
                                <input type="hidden" name="add_medicine" value="1">
                                <div class="row g-3">
                                    <!-- 🚀 [UPDATED PART] Medicine Name with Auto-Suggest Datalist -->
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Medicine Name <span class="text-danger">*</span></label>
                                        <input type="text" name="medicine_name" class="form-control" list="bd_medicine_names" required placeholder="Select or type medicine name...">
                                    </div>
                                    <!-- 🚀 [UPDATED PART] Generic Name with Auto-Suggest Datalist -->
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Generic Name</label>
                                        <input type="text" name="generic_name" class="form-control" list="bd_generic_names" placeholder="Select generic name...">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Batch Number</label>
                                        <input type="text" name="batch_number" class="form-control" placeholder="e.g. B-2026-09">
                                    </div>
                                    <!-- 🚀 [UPDATED PART] Supplier Info with Auto-Suggest Datalist -->
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Supplier Info</label>
                                        <input type="text" name="supplier_info" class="form-control" list="bd_pharma_suppliers" placeholder="Select pharma company...">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Stock Quantity <span class="text-danger">*</span></label>
                                        <input type="number" name="stock_quantity" class="form-control" required min="0" value="50">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Unit Price (BDT) <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" name="unit_price" class="form-control" required placeholder="0.00">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Expiry Date</label>
                                        <input type="date" name="expiry_date" class="form-control">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Low Stock Threshold</label>
                                        <input type="number" name="low_stock_threshold" class="form-control" value="10">
                                    </div>
                                    <div class="col-12 text-end">
                                        <button type="submit" class="btn btn-success fw-bold px-4">
                                            <i class="bi bi-check-circle me-1"></i> Save Medicine
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- ওষুধ স্টক টেবিল -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-list-ul me-1"></i> Pharmacy Medicine Stock Directory</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Medicine Name</th>
                                            <th>Generic Name</th>
                                            <th>Supplier & Batch</th>
                                            <th>Stock Quantity</th>
                                            <th>Unit Price</th>
                                            <th>Expiry Date</th>
                                            <th>Status</th>
                                            <th class="text-center">Action (Sell / Dispense)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($medicines)): foreach ($medicines as $m): ?>
                                            <tr>
                                                <td>#MED-<?= $m['medicine_id']; ?></td>
                                                <td><strong><?= htmlspecialchars($m['medicine_name']); ?></strong></td>
                                                <td><small class="text-muted"><?= htmlspecialchars($m['generic_name'] ?: 'N/A'); ?></small></td>
                                                <td>
                                                    <small><?= htmlspecialchars($m['supplier_info'] ?: 'N/A'); ?></small><br>
                                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($m['batch_number'] ?: 'N/A'); ?></span>
                                                </td>
                                                <td>
                                                    <span class="fw-bold fs-6 <?= ($m['stock_quantity'] <= $m['low_stock_threshold']) ? 'text-danger' : 'text-dark'; ?>">
                                                        <?= $m['stock_quantity']; ?> Pcs
                                                    </span>
                                                </td>
                                                <td>৳<?= number_format($m['unit_price'], 2); ?></td>
                                                <td><?= htmlspecialchars($m['expiry_date'] ?: 'N/A'); ?></td>
                                                <td>
                                                    <?php if ($m['stock_quantity'] <= 0): ?>
                                                        <span class="badge bg-danger">Out of Stock</span>
                                                    <?php elseif ($m['stock_quantity'] <= $m['low_stock_threshold']): ?>
                                                        <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i> Low Stock</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">Available</span>
                                                    <?php endif; ?>
                                                </td>
                                                <!-- Sell/Dispense Option in Table Row -->
                                                <td class="text-center">
                                                    <form method="POST" action="pharmacy.php" class="d-flex align-items-center justify-content-center">
                                                        <input type="hidden" name="medicine_id" value="<?= $m['medicine_id']; ?>">
                                                        <input type="hidden" name="sell_medicine" value="1">
                                                        <input type="number" name="sell_quantity" class="form-control form-control-sm me-1" style="width: 75px;" min="1" max="<?= $m['stock_quantity']; ?>" value="1" required <?= ($m['stock_quantity'] <= 0) ? 'disabled' : ''; ?>>
                                                        <button type="submit" class="btn btn-sm btn-danger fw-bold" <?= ($m['stock_quantity'] <= 0) ? 'disabled' : ''; ?>>
                                                            <i class="bi bi-cart-check me-1"></i> Sell
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; else: ?>
                                            <tr><td colspan="9" class="text-center py-4 text-muted">No medicines found in stock.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- ২. জেনারেল ইনভেন্টরি ট্যাব -->
                <div class="tab-pane fade" id="inventory-pane" role="tabpanel">
                    
                    <!-- ইনভেন্টরি এন্ট্রি ফর্ম -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-primary text-white py-3">
                            <h6 class="mb-0 fw-bold"><i class="bi bi-plus-circle me-1"></i> Add General Hospital Inventory Item</h6>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" action="pharmacy.php">
                                <input type="hidden" name="add_inventory_item" value="1">
                                <div class="row g-3">
                                    <!-- 🚀 [UPDATED PART] Item Name with Auto-Suggest Datalist -->
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Item Name <span class="text-danger">*</span></label>
                                        <input type="text" name="item_name" class="form-control" list="bd_inventory_items" required placeholder="Select item name...">
                                    </div>
                                    <!-- 🚀 [UPDATED PART] Category with Auto-Suggest Datalist -->
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Category</label>
                                        <input type="text" name="category" class="form-control" list="bd_inventory_categories" placeholder="Select category...">
                                    </div>
                                    <!-- 🚀 [UPDATED PART] Supplier Info with Auto-Suggest Datalist -->
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Supplier Info</label>
                                        <input type="text" name="supplier_info" class="form-control" list="bd_pharma_suppliers" placeholder="Select supplier...">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Quantity In Stock <span class="text-danger">*</span></label>
                                        <input type="number" name="quantity_in_stock" class="form-control" required min="0" value="20">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Unit Cost (BDT) <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" name="unit_cost" class="form-control" required placeholder="0.00">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Reorder Level Alert</label>
                                        <input type="number" name="reorder_level" class="form-control" value="5">
                                    </div>
                                    <div class="col-12 text-end">
                                        <button type="submit" class="btn btn-primary fw-bold px-4">
                                            <i class="bi bi-check-circle me-1"></i> Save Item
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- ইনভেন্টরি স্টক টেবিল -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-list-ul me-1"></i> Hospital Inventory Directory</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Item Name</th>
                                            <th>Category</th>
                                            <th>Supplier</th>
                                            <th>Quantity</th>
                                            <th>Unit Cost</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($inventory_items)): foreach ($inventory_items as $item): ?>
                                            <tr>
                                                <td>#INV-<?= $item['item_id']; ?></td>
                                                <td><strong><?= htmlspecialchars($item['item_name']); ?></strong></td>
                                                <td><span class="badge bg-info text-dark"><?= htmlspecialchars($item['category'] ?: 'General'); ?></span></td>
                                                <td><?= htmlspecialchars($item['supplier_info'] ?: 'N/A'); ?></td>
                                                <td>
                                                    <span class="fw-bold fs-6 <?= ($item['quantity_in_stock'] <= $item['reorder_level']) ? 'text-danger' : 'text-dark'; ?>">
                                                        <?= $item['quantity_in_stock']; ?> Units
                                                    </span>
                                                </td>
                                                <td>৳<?= number_format($item['unit_cost'], 2); ?></td>
                                                <td>
                                                    <?php if ($item['quantity_in_stock'] <= $item['reorder_level']): ?>
                                                        <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i> Reorder Needed</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">In Stock</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; else: ?>
                                            <tr><td colspan="7" class="text-center py-4 text-muted">No inventory items found.</td></tr>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>