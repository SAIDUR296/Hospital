<?php
session_start();
require 'db_connect.php';

// ১. সিকিউরিটি ও রোল চেক
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Ward Boy', 'Cleaner', 'Support Staff'])) {
    header("Location: login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Staff Member';
$role      = $_SESSION['role'] ?? 'Staff';

$message = "";
$message_type = "";

// ২. টাস্ক স্ট্যাটাস আপডেট হ্যান্ডেলিং (Accept Duty / Start Work / Mark Complete)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_status') {
    $dispatch_id = intval($_POST['dispatch_id'] ?? 0);
    $new_status  = $_POST['status'] ?? 'In Progress';

    if ($dispatch_id > 0) {
        try {
            // Unassigned টাস্ক গ্রহণ করলে নিজের ID সেট হবে
            $stmt = $pdo->prepare("UPDATE staff_dispatches SET status = ?, assigned_staff_id = ? WHERE dispatch_id = ? AND (assigned_staff_id = ? OR assigned_staff_id IS NULL)");
            $stmt->execute([$new_status, $user_id, $dispatch_id, $user_id]);
            
            $message = "Task status updated to '$new_status'!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// ৩. এই স্টাফের জন্য অ্যাসাইন করা বা রোল অনুযায়ী Unassigned কাজসমূহ ফেচ করা
$my_tasks = [];
try {
    // রোল অনুযায়ী আন-অ্যাসাইনড ফিল্টার
    $role_filter = "";
    if ($role == 'Cleaner') {
        $role_filter = "OR (assigned_staff_id IS NULL AND task_type = 'Cleaning')";
    } elseif ($role == 'Ward Boy') {
        $role_filter = "OR (assigned_staff_id IS NULL AND task_type IN ('Ward Boy Assistance', 'Equipment Transport'))";
    } else {
        $role_filter = "OR (assigned_staff_id IS NULL)";
    }

    $stmt = $pdo->prepare("SELECT * FROM staff_dispatches WHERE (assigned_staff_id = ? $role_filter) ORDER BY FIELD(status, 'Unassigned', 'Assigned', 'In Progress', 'Completed'), created_at DESC");
    $stmt->execute([$user_id]);
    $my_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($role); ?> Duty Portal - Hospital ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f1f5f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .task-card { border-radius: 12px; border: none; transition: transform 0.2s; }
        .urgency-High { border-left: 6px solid #dc3545 !important; }
        .urgency-Normal { border-left: 6px solid #0d6efd !important; }
        .urgency-Low { border-left: 6px solid #6c757d !important; }
    </style>
</head>
<body>

<div class="container py-3" style="max-width: 600px;">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center bg-white p-3 rounded-3 shadow-sm mb-3">
        <div>
            <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-person-badge me-1"></i> <?= htmlspecialchars($full_name); ?></h5>
            <span class="badge bg-secondary"><?= htmlspecialchars($role); ?></span>
        </div>
        <a href="logout.php" class="btn btn-outline-danger btn-sm fw-bold"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $message_type; ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <h6 class="fw-bold text-secondary mb-3"><i class="bi bi-list-task me-1"></i> Duty Calls & Tasks</h6>

    <?php if (!empty($my_tasks)): foreach ($my_tasks as $task): ?>
        <div class="card task-card shadow-sm p-3 mb-3 urgency-<?= htmlspecialchars($task['urgency']); ?> bg-white">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="badge bg-primary text-uppercase"><?= htmlspecialchars($task['task_type']); ?></span>
                <span class="badge <?= $task['urgency'] == 'High' ? 'bg-danger' : 'bg-info text-dark'; ?>">
                    <?= htmlspecialchars($task['urgency']); ?> Urgency
                </span>
            </div>

            <h5 class="fw-bold text-dark mb-1"><i class="bi bi-geo-alt-fill text-danger me-1"></i> <?= htmlspecialchars($task['location_ward']); ?></h5>
            <p class="text-muted small mb-3"><i class="bi bi-clock me-1"></i> Time: <?= htmlspecialchars($task['created_at']); ?></p>

            <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                <div>
                    <small class="text-muted d-block">Status:</small>
                    <?php 
                        $st = $task['status'];
                        $st_badge = 'bg-secondary';
                        if ($st == 'Completed') $st_badge = 'bg-success';
                        elseif ($st == 'In Progress') $st_badge = 'bg-warning text-dark';
                        elseif ($st == 'Assigned') $st_badge = 'bg-primary';
                        elseif ($st == 'Unassigned') $st_badge = 'bg-danger';
                    ?>
                    <span class="badge <?= $st_badge; ?> fs-6"><?= htmlspecialchars($st); ?></span>
                </div>

                <div>
                    <!-- ১. রোগী সরাসরি কল করলে Accept Duty বাটন -->
                    <?php if ($task['status'] == 'Unassigned'): ?>
                        <form method="POST" action="staff_dashboard.php" class="d-inline">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="dispatch_id" value="<?= $task['dispatch_id']; ?>">
                            <input type="hidden" name="status" value="In Progress">
                            <button type="submit" class="btn btn-primary btn-sm fw-bold">
                                <i class="bi bi-hand-index-thumb"></i> Accept Duty
                            </button>
                        </form>
                    <?php endif; ?>

                    <!-- ২. নার্স বা অ্যাডমিন নাম সিলেক্ট করে দিলে Start Work বাটন -->
                    <?php if ($task['status'] == 'Assigned'): ?>
                        <form method="POST" action="staff_dashboard.php" class="d-inline">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="dispatch_id" value="<?= $task['dispatch_id']; ?>">
                            <input type="hidden" name="status" value="In Progress">
                            <button type="submit" class="btn btn-warning btn-sm text-dark fw-bold">
                                <i class="bi bi-play-fill"></i> Start Work
                            </button>
                        </form>
                    <?php endif; ?>

                    <!-- ৩. কাজ শুরু করার পর Mark Complete বাটন -->
                    <?php if ($task['status'] == 'In Progress'): ?>
                        <form method="POST" action="staff_dashboard.php" class="d-inline">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="dispatch_id" value="<?= $task['dispatch_id']; ?>">
                            <input type="hidden" name="status" value="Completed">
                            <button type="submit" class="btn btn-success btn-sm fw-bold">
                                <i class="bi bi-check-circle-fill"></i> Mark Complete
                            </button>
                        </form>
                    <?php endif; ?>

                    <!-- ৪. কাজ সম্পন্ন হলে Done প্রদর্শন -->
                    <?php if ($task['status'] == 'Completed'): ?>
                        <span class="text-success fw-bold"><i class="bi bi-check-all fs-4"></i> Completed</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; else: ?>
        <div class="card border-0 shadow-sm p-4 text-center text-muted">
            <i class="bi bi-check2-circle fs-1 text-success mb-2"></i>
            <h5>No Pending Tasks!</h5>
            <p class="small mb-0">আপনার জন্য এই মুহূর্তে কোনো পেন্ডিং কাজ নেই।</p>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>