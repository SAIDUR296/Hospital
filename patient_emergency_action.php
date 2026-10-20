<?php
session_start();
require 'db_connect.php';

// সিকিউরিটি চেক: শুধুমাত্র পেশেন্ট রোলের ইউজার এক্সেস পাবে
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Patient') {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bed_id'])) {
    $bed_id = intval($_POST['bed_id']);
    
    if ($bed_id > 0) {
        try {
            // অতিরিক্ত টেবিল ছাড়াই cabins_beds টেবিলে ইমার্জেন্সি নোট আপডেট
            $stmt = $pdo->prepare("UPDATE cabins_beds SET emergency_note = '🚨 NURSE NEEDED: Patient called from bed!' WHERE bed_id = ?");
            $stmt->execute([$bed_id]);
            
            $_SESSION['message'] = "Emergency signal sent to the Nurse Station successfully!";
            $_SESSION['message_type'] = "danger";
        } catch (PDOException $e) {
            $_SESSION['message'] = "Error sending alert: " . $e->getMessage();
            $_SESSION['message_type'] = "danger";
        }
    }
}

header("Location: dashboard.php");
exit();