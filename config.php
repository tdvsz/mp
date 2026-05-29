<?php
session_start();
$pdo = new PDO('mysql:host=localhost;dbname=clinic_app;charset=utf8mb4', 'root', 'root', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

function redirect($url)
{
    header("Location: $url");
    exit;
}
function requireAuth($roles = [])
{
    if (!isset($_SESSION['user_id'])) redirect('auth.php');
    if ($roles && !in_array($_SESSION['role'], $roles)) redirect('index.php');
}

define('UPLOAD_DIR', __DIR__ . '/uploads/');

function handlePhotoUpload($file, $old_photo_path = null)
{
    if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($file['type'], $allowed_types)) {
        return false;
    }

    if ($file['size'] > 2 * 1024 * 1024) {
        return false;
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $new_filename = uniqid('img_', true) . '.' . $ext;
    $destination = UPLOAD_DIR . $new_filename;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        if ($old_photo_path && !filter_var($old_photo_path, FILTER_VALIDATE_URL)) {
            $old_path_full = UPLOAD_DIR . basename($old_photo_path);
            if (file_exists($old_path_full)) {
                unlink($old_path_full);
            }
        }
        return $new_filename;
    }

    return false;
}

function plural_years($years) {
    $n = abs((int)$years);
    $n100 = $n % 100;
    $n10 = $n % 10;
    if ($n100 >= 11 && $n100 <= 19) return "$n лет";
    if ($n10 === 1) return "$n год";
    if ($n10 >= 2 && $n10 <= 4) return "$n года";
    return "$n лет";
}