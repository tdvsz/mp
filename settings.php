<?php
require 'config.php';
requireAuth();

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Получаем текущие данные пользователя
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_name = trim($_POST['full_name']);
    $new_email = trim($_POST['email']);
    $new_telegram = trim($_POST['telegram_nick']);
    $new_photo = trim($_POST['photo']);
    
    $old_pass = $_POST['old_password'] ?? '';
    $new_pass = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';
    
    // Валидация
    if (empty($new_name)) {
        $err = 'ФИО не может быть пустым';
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Неверный формат email';
    } else {
        // Проверяем уникальность email (кроме текущего пользователя)
        $check_email = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check_email->execute([$new_email, $user_id]);
        if ($check_email->fetch()) {
            $err = 'Этот email уже используется другим пользователем';
        } else {
            // Формируем запрос обновления
            $update_fields = "full_name = ?, email = ?, telegram_nick = ?, photo = ?";
            $params = [$new_name, $new_email, $new_telegram, $new_photo];
            
            // Если указан новый пароль
            if (!empty($new_pass)) {
                if (empty($old_pass)) {
                    $err = 'Для смены пароля введите текущий пароль';
                } elseif (!password_verify($old_pass, $user['password_hash'])) {
                    $err = 'Текущий пароль неверный';
                } elseif ($new_pass !== $confirm_pass) {
                    $err = 'Новый пароль и подтверждение не совпадают';
                } elseif (strlen($new_pass) < 6) {
                    $err = 'Новый пароль должен быть не менее 6 символов';
                } else {
                    $update_fields .= ", password_hash = ?";
                    $params[] = password_hash($new_pass, PASSWORD_DEFAULT);
                }
            }
            
            if (empty($err)) {
                $params[] = $user_id;
                $pdo->prepare("UPDATE users SET $update_fields WHERE id = ?")->execute($params);
                
                // Обновляем сессию
                $_SESSION['full_name'] = $new_name;
                $msg = '✅ Профиль успешно обновлен!';
                
                // Перезагружаем данные
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Настройки | Medprofi</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="site-header">
    <div class="container header-flex">
        <a href="dashboard.php" class="logo">← Кабинет</a>
        <a href="logout.php" class="btn btn-outline">Выйти</a>
    </div>
</header>

<main class="container" style="max-width:700px;">
    <h1 style="margin-bottom:25px;">️ Настройки профиля</h1>
    
    <?php if($msg): ?><div class="success"><?=htmlspecialchars($msg)?></div><?php endif; ?>
    <?php if($err): ?><div class="err"><?=htmlspecialchars($err)?></div><?php endif; ?>
    
    <div class="card">
        <form method="POST">
            <div class="settings-section">
                <h3> Основная информация</h3>
                
                <div class="form-group">
                    <label>ФИО:</label>
                    <input type="text" name="full_name" value="<?=htmlspecialchars($user['full_name'])?>" required>
                </div>
                
                <div class="form-group">
                    <label>Email:</label>
                    <input type="email" name="email" value="<?=htmlspecialchars($user['email'])?>" required>
                </div>
                
                <?php if($role !== 'admin'): ?>
                <div class="form-group">
                    <label>Telegram ник (без @):</label>
                    <input type="text" name="telegram_nick" value="<?=htmlspecialchars($user['telegram_nick'] ?? '')?>" placeholder="username">
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Ссылка на фото:</label>
                    <input type="url" name="photo" value="<?=htmlspecialchars($user['photo'] ?? '')?>" placeholder="https://example.com/photo.jpg">
                    <?php if(!empty($user['photo'])): ?>
                        <img src="<?=htmlspecialchars($user['photo'])?>" style="width:80px; height:80px; border-radius:50%; object-fit:cover; margin-top:10px;" onerror="this.style.display='none'">
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="settings-section" style="margin-top:30px; padding-top:30px; border-top:1px solid var(--border);">
                <h3>Смена пароля</h3>
                <p style="font-size:0.9rem; color:#64748b; margin-bottom:15px;">Оставьте поля пустыми, если не хотите менять пароль</p>
                
                <div class="form-group">
                    <label>Текущий пароль:</label>
                    <input type="password" name="old_password" placeholder="Введите текущий пароль">
                </div>
                
                <div class="form-group">
                    <label>Новый пароль:</label>
                    <input type="password" name="new_password" placeholder="Минимум 6 символов">
                </div>
                
                <div class="form-group">
                    <label>Подтвердите новый пароль:</label>
                    <input type="password" name="confirm_password" placeholder="Повторите новый пароль">
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary btn-large" style="width:100%; margin-top:25px;">Сохранить изменения</button>
        </form>
    </div>
</main>

<style>
.settings-section h3 { margin:0 0 15px; color:#334155; }
.form-group { margin-bottom:15px; }
.form-group label { display:block; margin-bottom:5px; font-weight:500; color:#475569; font-size:0.9rem; }
.form-group input { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:8px; font-size:0.95rem; }
.form-group input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(37,99,235,0.1); }
</style>
</body>
</html>