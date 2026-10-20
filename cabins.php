<?php
session_start();
require 'db_connect.php';

// Pharmacist role হলে cabins.php পেজে প্রবেশ করতে পারবে না (Dashboard-এ পাঠাবে)
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') === 'Pharmacist') {
    header("Location: dashboard.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'Patient';
$full_name = $_SESSION['full_name'] ?? 'User';

// ১. কলাম অটো-ক্রিয়েশন (জরুরি নোট কলাম না থাকলে তৈরি করবে)
try {
    $pdo->exec("ALTER TABLE cabins_beds ADD COLUMN emergency_note TEXT NULL");
} catch (PDOException $e) {}

$message = "";
$message_type = "";

// -------------------------------------------------------------
// ২. অ্যাকশন হ্যান্ডলিং (Discharge, Add Bed, Emergency Escalation & Clear)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ক. পেশেন্ট ডিসচার্জ করা (Only Admin & Receptionist)
    if ($action == 'discharge' && in_array($role, ['Admin', 'Receptionist'])) {
        $bed_id = intval($_POST['bed_id'] ?? 0);
        if ($bed_id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE cabins_beds SET availability_status = 'Available', patient_id = NULL, emergency_note = NULL WHERE bed_id = ?");
                $stmt->execute([$bed_id]);

                $message = "Bed/Cabin released and patient discharged successfully!";
                $message_type = "success";
            } catch (PDOException $e) {
                $message = "Update Error: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }

    // খ. নতুন বেড যোগ করা (Only Admin & Receptionist)
    if ($action == 'add_bed' && in_array($role, ['Admin', 'Receptionist'])) {
        $bed_num = trim($_POST['bed_number'] ?? '');
        $c_type  = trim($_POST['cabin_type'] ?? 'General Ward');
        $d_rate  = floatval($_POST['daily_rate'] ?? 0);

        if (!empty($bed_num)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO cabins_beds (bed_number, cabin_type, daily_rate, availability_status) VALUES (?, ?, ?, 'Available')");
                $stmt->execute([$bed_num, $c_type, $d_rate]);
                $message = "New Cabin/Bed added successfully!";
                $message_type = "success";
            } catch (PDOException $e) {
                $message = "Error adding bed: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }

    // গ. নার্স কর্তৃক ডাক্তারকে ইমার্জেন্সি অ্যালার্ট পাঠানো (Escalate to Doctor)
    if ($action == 'escalate_to_doctor') {
        $bed_id = intval($_POST['bed_id'] ?? 0);
        if ($bed_id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE cabins_beds SET emergency_note = '🔥 CRITICAL: Patient condition critical! Doctor required immediately!' WHERE bed_id = ?");
                $stmt->execute([$bed_id]);
                $message = "Emergency escalated to On-Duty Doctors!";
                $message_type = "warning";
            } catch (PDOException $e) {
                $message = "Error escalating alert: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }

    // ঘ. ইমার্জেন্সি অ্যালার্ট রিসলভ/ক্লিয়ার করা
    if ($action == 'clear_emergency') {
        $bed_id = intval($_POST['bed_id'] ?? 0);
        if ($bed_id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE cabins_beds SET emergency_note = NULL WHERE bed_id = ?");
                $stmt->execute([$bed_id]);
                $message = "Emergency alert resolved and cleared.";
                $message_type = "success";
            } catch (PDOException $e) {
                $message = "Error clearing alert: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }
}

// -------------------------------------------------------------
// ৩. ডাটা ফেচিং (সকল কেবিন ও বেডের তালিকা)
// -------------------------------------------------------------
$beds = [];
$total_beds = 0;
$available_beds = 0;
$occupied_beds = 0;

try {
    $sql = "SELECT cb.*, p.full_name AS patient_name, p.phone AS patient_phone 
            FROM cabins_beds cb 
            LEFT JOIN patients p ON cb.patient_id = p.patient_id 
            ORDER BY cb.bed_id DESC";
    $stmt = $pdo->query($sql);
    if ($stmt) {
        $beds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $total_beds = count($beds);
    foreach ($beds as $b) {
        if (($b['availability_status'] ?? '') == 'Available') $available_beds++;
        if (($b['availability_status'] ?? '') == 'Occupied') $occupied_beds++;
    }
} catch (PDOException $e) {
    $message = "Data Fetch Error: " . $e->getMessage();
    $message_type = "danger";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cabin & Bed Management - Smart Hospital ERP</title>
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
    <!-- Side Navigation -->
    <div class="sidebar p-3">
        <h4 class="text-center text-white mb-4"><i class="bi bi-hospital"></i> Hospital ERP</h4>
        <hr class="text-secondary">
        <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="cabins.php" class="active"><i class="bi bi-building"></i> Cabins & Beds</a>
        
        <?php if (in_array($role, ['Admin', 'Receptionist'])): ?>
            <a href="assign_bed.php"><i class="bi bi-journal-plus"></i> Assign Bed</a>
        <?php endif; ?>

        <a href="patient_list.php"><i class="bi bi-people"></i> Patient Directory</a>
        <hr class="text-secondary mt-4">
        <a href="logout.php" class="text-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>

    <!-- Main Content Area -->
    <div class="w-100 p-4">
        <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded shadow-sm">
            <h4 class="mb-0 text-primary fw-bold"><i class="bi bi-building me-2"></i>Cabin & Bed Management System</h4>
            <span class="text-muted">User: <strong><?= htmlspecialchars($full_name); ?></strong> (<?= htmlspecialchars($role); ?>)</span>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $message_type; ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Stat Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white p-3 border-0 shadow-sm">
                    <h6>Total Beds</h6>
                    <h2 class="fw-bold mb-0"><?= $total_beds; ?></h2>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white p-3 border-0 shadow-sm">
                    <h6>Available Beds</h6>
                    <h2 class="fw-bold mb-0"><?= $available_beds; ?></h2>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-danger text-white p-3 border-0 shadow-sm">
                    <h6>Occupied Beds</h6>
                    <h2 class="fw-bold mb-0"><?= $occupied_beds; ?></h2>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-dark p-3 border-0 shadow-sm">
                    <h6>Maintenance</h6>
                    <h2 class="fw-bold mb-0">0</h2>
                </div>
            </div>
        </div>

        <!-- Table Card -->
        <div class="card border-0 shadow-sm p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-primary mb-0"><i class="bi bi-list-stars me-2"></i>Hospital Cabins & Beds Directory</h5>
                <?php if (in_array($role, ['Admin', 'Receptionist'])): ?>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addBedModal">
                        <i class="bi bi-plus-circle me-1"></i> Add New Bed / Cabin
                    </button>
                <?php endif; ?>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Bed / Room No</th>
                            <th>Cabin Type</th>
                            <th>Daily Rate (BDT)</th>
                            <th>Availability Status</th>
                            <th>Assigned Patient</th>
                            <th class="text-center">Action / Alert Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($beds)): foreach ($beds as $b): ?>
                            <tr>
                                <td>#<?= $b['bed_id']; ?></td>
                                <td><strong><?= htmlspecialchars($b['bed_number']); ?></strong></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($b['cabin_type']); ?></span></td>
                                <td>৳<?= number_format($b['daily_rate'], 2); ?></td>
                                <td>
                                    <?php if ($b['availability_status'] == 'Occupied'): ?>
                                        <span class="badge bg-danger"><i class="bi bi-person-fill me-1"></i> Occupied</span>
                                    <?php else: ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Available</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($b['patient_name'])): ?>
                                        <strong><?= htmlspecialchars($b['patient_name']); ?></strong><br>
                                        <small class="text-muted"><i class="bi bi-telephone"></i> <?= htmlspecialchars($b['patient_phone'] ?? 'N/A'); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted small">None</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <!-- ১. ইমার্জেন্সি অ্যালার্ট অ্যাক্টিভ থাকলে প্রদর্শনের অংশ -->
                                    <?php if (!empty($b['emergency_note'])): ?>
                                        <div class="p-2 mb-2 rounded <?= (strpos($b['emergency_note'], 'CRITICAL') !== false) ? 'bg-danger text-white fw-bold' : 'bg-warning text-dark fw-bold'; ?> small">
                                            <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= htmlspecialchars($b['emergency_note']); ?>
                                        </div>

                                        <div class="d-flex gap-1 justify-content-center">
                                            <!-- ক্রাইটেরিয়া পূরণ সাপেক্ষে ডাক্তার ডাকার বাটন -->
                                            <?php if (in_array($role, ['Nurse', 'Admin', 'Doctor']) && strpos($b['emergency_note'], 'CRITICAL') === false): ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="escalate_to_doctor">
                                                    <input type="hidden" name="bed_id" value="<?= $b['bed_id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger fw-bold">
                                                        <i class="bi bi-telephone-outbound me-1"></i> Call Doctor
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- অ্যালার্ট ক্লিয়ার/রিসলভ করার বাটন -->
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="clear_emergency">
                                                <input type="hidden" name="bed_id" value="<?= $b['bed_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-dark fw-bold">
                                                    <i class="bi bi-check-circle me-1"></i> Resolve
                                                </button>
                                            </form>
                                        </div>

                                    <!-- ২. কোনো ইমার্জেন্সি অ্যালার্ট না থাকলে সাধারণ অ্যাকশন বাটন -->
                                    <?php else: ?>
                                        <?php if ($b['availability_status'] == 'Occupied'): ?>
                                            <?php if (in_array($role, ['Admin', 'Receptionist'])): ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to discharge this patient and release the bed?');">
                                                    <input type="hidden" name="action" value="discharge">
                                                    <input type="hidden" name="bed_id" value="<?= $b['bed_id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger fw-bold">
                                                        <i class="bi bi-box-arrow-right me-1"></i> Discharge & Release
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Occupied (Monitoring)</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-light text-success border">Ready for Patient</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No beds or cabins found in directory.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Bed Modal -->
<div class="modal fade" id="addBedModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_bed">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-square me-2"></i>Add New Bed / Cabin</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Bed / Room Number</label>
                        <input type="text" name="bed_number" class="form-control" placeholder="e.g. Bed_103 or Cabin-A1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Cabin Type</label>
                        <select name="cabin_type" class="form-select">
                            <option value="General Ward">General Ward</option>
                            <option value="Semi-Cabin">Semi-Cabin</option>
                            <option value="VIP Cabin">VIP Cabin</option>
                            <option value="ICU">ICU</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Daily Rate (BDT)</label>
                        <input type="number" step="0.01" name="daily_rate" class="form-control" placeholder="e.g. 500.00" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Save Bed</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>