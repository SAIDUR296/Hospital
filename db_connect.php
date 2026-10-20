<?php
// db_connect.php - Smart Hospital ERP Database Connection

$host     = 'localhost';
$dbname   = 'smart_hospital_erp';
$username = 'root'; 
$password = ''; 

try {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // PDO Exception এরর থ্রো করার জন্য
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,     // ডাটা ফেচ করলে Associative Array হিসেবে রিটার্ন করবে
        PDO::ATTR_EMULATE_PREPARES   => false,                // SQL Injection সর্বোচ্চ প্রতিরোধে Real Prepared Statements চালু রাখবে
    ];

    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}
?>