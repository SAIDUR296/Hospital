<?php
session_start();
require 'db_connect.php';

$error = "";

// ইউজার ইতিমধ্যে লগইন করা থাকলে ড্যাশবোর্ডে পাঠাবে
if (isset($_SESSION['user_id'])) {
    if (in_array($_SESSION['role'] ?? '', ['Ward Boy', 'Cleaner', 'Support Staff'])) {
        header("Location: staff_dashboard.php");
    } else {
        header("Location: dashboard.php");
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "Please enter both Username and Password!";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT user_id, username, password_hash, role, full_name, status FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // ১. অ্যাকাউন্ট সাসপেন্ডেড হলে লগইন ব্লক করবে
                if (($user['status'] ?? '') === 'Suspended') {
                    $error = "Your account has been suspended. Please contact the administrator.";
                } 
                // ২. পাসওয়ার্ড ভেরিফিকেশন (এখানে ব্র্যাকেটের লজিক ঠিক করা হয়েছে)
                elseif (password_verify($password, $user['password_hash'])) {
                    
                    // সেশন ভ্যালু সেট
                    $_SESSION['user_id']   = $user['user_id'];
                    $_SESSION['username']  = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role']      = $user['role']; 
                    $_SESSION['status']    = $user['status'];

                    // লাস্ট লগইন আপডেট
                    $update = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
                    $update->execute([$user['user_id']]);

                    // 🟢 রোল ভিত্তিক রিডাইরেক্ট
                    if (in_array($_SESSION['role'], ['Ward Boy', 'Cleaner', 'Support Staff'])) {
                        header("Location: staff_dashboard.php");
                        exit();
                    } else {
                        header("Location: dashboard.php");
                        exit();
                    }

                } else {
                    $error = "Invalid Password! Please try again.";
                }
            } else {
                $error = "No user found with this Username/Email!";
            }
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Smart Hospital ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #0f172a; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { width: 100%; max-width: 400px; border-radius: 12px; border: none; }
        .card-header { background-color: #1e293b; color: white; border-top-left-radius: 12px !important; border-top-right-radius: 12px !important; }
    </style>
</head>
<body>

<div class="container d-flex justify-content-center">
    <div class="card login-card shadow-lg">
        <div class="card-header p-4 text-center">
            <h3 class="mb-0 fw-bold"><i class="bi bi-hospital me-2"></i>Hospital ERP</h3>
            <small class="text-muted">Sign in to your account</small>
        </div>
        <div class="card-body p-4 bg-white">

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Username or Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" name="username" class="form-control" placeholder="Enter username or email" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" class="form-control" placeholder="Enter password" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2 fw-bold"><i class="bi bi-box-arrow-in-right me-1"></i> Sign In</button>
            </form>
        </div>
        <div class="card-footer bg-light text-center py-3">
            <small class="text-muted">Don't have an account? <a href="register.php" class="text-primary text-decoration-none fw-semibold">Register Here</a></small>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>