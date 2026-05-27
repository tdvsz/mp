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

/**
 * Функция обработки загрузки фото
 * @param array $file Данные из $_FILES
 * @param string $old_photo_path Старый путь к фото (для удаления при замене)
 * @return string|bool Путь к новому фото или false при ошибке
 */
function handlePhotoUpload($file, $old_photo_path = null)
{
    if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    // Проверка типа файла (только изображения)
    $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($file['type'], $allowed_types)) {
        return false;
    }

    // Проверка размера (макс 2МБ)
    if ($file['size'] > 2 * 1024 * 1024) {
        return false;
    }

    // Генерация уникального имени
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $new_filename = uniqid('img_', true) . '.' . $ext;
    $destination = UPLOAD_DIR . $new_filename;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        // Удаляем старое фото, если оно существовало и не является внешним URL
        if ($old_photo_path && !filter_var($old_photo_path, FILTER_VALIDATE_URL)) {
            $old_path_full = UPLOAD_DIR . basename($old_photo_path);
            if (file_exists($old_path_full)) {
                unlink($old_path_full);
            }
        }
        return $new_filename; // Возвращаем только имя файла, хранить полный путь в БД не обязательно, если папка фиксирована
    }

    return false;
}
