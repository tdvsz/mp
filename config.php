<?php
session_start();
$pdo = new PDO('mysql:host=localhost;dbname=clinic_app;charset=utf8mb4', 'root', 'root', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

function redirect($url) { header("Location: $url"); exit; }
function requireAuth($roles = []) {
    if (!isset($_SESSION['user_id'])) redirect('auth.php');
    if ($roles && !in_array($_SESSION['role'], $roles)) redirect('index.php');
}
?>