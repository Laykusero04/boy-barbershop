<?php
// Simple PDO connection helper for Boy Barbershop

// Use shop timezone so "today" and sale times match your location (Philippines)
date_default_timezone_set('Asia/Manila');

$host = 'localhost';
$dbname = 'boy_barbershop';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // So new sales get stored in shop time (Asia/Manila)
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}