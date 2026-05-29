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
    $new_phone = trim($_POST['phone_number']);
    $new_email = trim($_POST['email']);
    $photo_filename = $user['photo']; // Оставляем старое по умолчанию
    if (!empty($_FILES['photo']['name'])) {
        $uploaded = handlePhotoUpload($_FILES['photo'], $user['photo']);
        if ($uploaded === false) {
            $err = 'Ошибка при загрузке фото. Проверьте формат и размер.';
        } else {
            $photo_filename = $uploaded;
        }
    }

    $old_pass = $_POST['old_password'] ?? '';
    $new_pass = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    // Валидация
    if (empty($new_name)) $err = 'ФИО не может быть пустым';
    elseif (empty($new_phone)) $err = 'Номер телефона не может быть пустым';
    elseif (!preg_match('/^\+?[0-9]{9,15}$/', $new_phone)) $err = 'Неверный формат номера телефона';
    else {
        // Проверяем уникальность номера и email (если email задан)
        $check = $pdo->prepare("SELECT id FROM users WHERE (phone_number = ? OR (email = ? AND email IS NOT NULL)) AND id != ?");
        $check->execute([$new_phone, $new_email, $user_id]);
        if ($check->fetch()) {
            $err = 'Этот номер телефона или email уже используется другим пользователем';
        } else {
            $update_fields = "full_name = ?, phone_number = ?, email = ?, photo = ?";
            $params = [$new_name, $new_phone, $new_email, $photo_filename];

            if (!empty($new_pass)) {
                if (empty($old_pass)) $err = 'Для смены пароля введите текущий пароль';
                elseif (!password_verify($old_pass, $user['password_hash'])) $err = 'Текущий пароль неверный';
                elseif ($new_pass !== $confirm_pass) $err = 'Новый пароль и подтверждение не совпадают';
                elseif (strlen($new_pass) < 6) $err = 'Новый пароль должен быть не менее 6 символов';
                else {
                    $update_fields .= ", password_hash = ?";
                    $params[] = password_hash($new_pass, PASSWORD_DEFAULT);
                }
            }

            if (empty($err)) {
                $params[] = $user_id;
                $pdo->prepare("UPDATE users SET $update_fields WHERE id = ?")->execute($params);
                $_SESSION['full_name'] = $new_name;
                $msg = 'Профиль успешно обновлен!';
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
    <link rel="stylesheet" href="settings.css">
</head>

<body>
    <header class="site-header">
        <div class="container header-flex">
            <div class="header-left">
                <a href="dashboard.php" class="btn-back">← В кабинет</a>
                <a href="dashboard.php" class="logo">Medprofi <span class="badge"><?= ($_SESSION['role'] === 'patient' ? 'Пациент' : ($_SESSION['role'] === 'doctor' ? 'Врач' : 'Админ')) ?></span></a>
            </div>
            <div style="display:flex; gap:10px; align-items:center;">
                <a href="logout.php" class="btn btn-outline">Выйти</a>
            </div>
        </div>
    </header>

    <main class="container" style="max-width:700px;">
        <h1 style="margin-bottom:25px;">️ Настройки профиля</h1>
        <div class="card">
            <form method="POST" enctype="multipart/form-data">
                <div class="settings-section">
                    <h3> Основная информация</h3>

                    <div class="form-group">
                        <label>ФИО:</label>
                        <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" required>
                    </div>

                    <div class="form-group"><label>Номер телефона:</label><input type="tel" name="phone_number" value="<?= htmlspecialchars($user['phone_number']) ?>" required></div>

                    <div class="form-group">
                        <label>Email:</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>">
                    </div>

                    <div class="form-group">
                        <label>Фото профиля:</label>
                        <!-- Показываем текущее фото, если есть -->
                        <?php if (!empty($user['photo'])): ?>
                            <div style="margin-bottom: 10px;">
                                <img src="uploads/<?= htmlspecialchars($user['photo']) ?>"
                                    style="width:80px; height:80px; border-radius:50%; object-fit:cover;"
                                    onerror="this.style.display='none'">
                            </div>
                        <?php endif; ?>

                        <input type="file" name="photo" accept="image/*">
                        <small style="color: #64748b;">JPG, PNG, WebP (макс. 2MB)</small>
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
    <?php require 'toast.php'; ?>
</body>

</html>