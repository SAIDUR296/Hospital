<?php
require 'db_connect.php';
$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username       = trim($_POST['username']);
    $full_name      = trim($_POST['full_name']);
    $email          = trim($_POST['email']);
    $phone_number   = trim($_POST['phone_number']);
    $role           = $_POST['role'];
    $plain_password = $_POST['password'];

    if (empty($username) || empty($full_name) || empty($email) || empty($plain_password)) {
        $message = "<div class='alert alert-warning'>Please fill in all required fields.</div>";
    } else {
        try {
            $check_stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
            $check_stmt->execute([$username, $email]);

            if ($check_stmt->rowCount() > 0) {
                $message = "<div class='alert alert-danger'>Username or Email is already registered!</div>";
            } else {
                $password_hash = password_hash($plain_password, PASSWORD_DEFAULT);

                // যে রোলেই রেজিস্টার করুক, সরাসরি Active হয়ে যাবে
                $status = 'Active';

                $sql = "INSERT INTO users (username, password_hash, role, full_name, email, phone_number, status) 
                        VALUES (:username, :password_hash, :role, :full_name, :email, :phone_number, :status)";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':username'      => $username,
                    ':password_hash' => $password_hash,
                    ':role'          => $role,
                    ':full_name'     => $full_name,
                    ':email'         => $email,
                    ':phone_number'  => $phone_number,
                    ':status'        => $status
                ]);

                $message = "<div class='alert alert-success'>Registration Successful as <strong>$role</strong>! <a href='login.php' class='fw-bold'>Click Here to Login</a></div>";
            }
        } catch (PDOException $e) {
            $message = "<div class='alert alert-danger'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital ERP - Register</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow mb-5 border-0">
                    <div class="card-header bg-primary text-white text-center py-3">
                        <h4 class="mb-0">Smart Hospital ERP - Registration</h4>
                    </div>
                    <div class="card-body p-4">
                        <?= $message ?>
                        <form action="register.php" method="POST">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="full_name" class="form-control" required placeholder="Enter full name">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
                                <input type="text" name="username" class="form-control" required placeholder="Choose a unique username">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                                <input type="email" name="email" class="form-control" required placeholder="example@domain.com">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Phone Number</label>
                                <input type="text" name="phone_number" class="form-control" placeholder="017XXXXXXXX">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Register As (Role) <span class="text-danger">*</span></label>
                                <select name="role" class="form-select" required>
                                    <option value="Admin">Admin</option>
                                    <option value="Doctor">Doctor</option>
                                    <option value="Patient">Patient</option>
                                    <option value="Nurse">Nurse</option>
                                    <option value="Receptionist">Receptionist</option>
                                    <option value="Pharmacist">Pharmacist</option>
                                    <option value="Lab Technician">Lab Technician</option>
                                    <option value="Accountant">Accountant</option>
                                    <!-- 🆕 Support Staff Roles -->
                                <option value="Ward Boy">Ward Boy</option>
                                <option value="Cleaner">Cleaner</option>
                                <option value="Support Staff">Support Staff</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                                <input type="password" name="password" class="form-control" required placeholder="Enter strong password">
                            </div>
                            <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">Register Account</button>
                        </form>
                        <div class="text-center mt-3">
                            <a href="login.php" class="text-decoration-none">Already have an account? Login here</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>