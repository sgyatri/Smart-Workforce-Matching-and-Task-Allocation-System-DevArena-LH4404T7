<?php
// includes/db.php
// Edit these values if your MySQL user/pass differs
$host = '127.0.0.1';
$db   = 'worker_manager';
$user = 'root';
$pass = ''; // XAMPP default
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // In production do not echo this
    exit("DB Connection failed: " . $e->getMessage());
}
