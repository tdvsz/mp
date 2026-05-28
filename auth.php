<?php
require 'config.php';
$err = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    $phone = trim($_POST['phone']);
    $pass = $_POST['password'];

    if ($action === 'register') {
        $name = trim($_POST['full_name']);
        $email = trim($_POST['email']); // опционально
        if (!$phone || !$pass || !$name) $err = 'Заполните все обязательные поля';
        elseif (!preg_match('/^\+?[0-9]{9,15}$/', $phone)) $err = 'Неверный формат номера телефона';
        else {
            // Проверка уникальности номера
            $stmt = $pdo->prepare("SELECT id FROM users WHERE phone_number = ?");
            $stmt->execute([$phone]);
            if ($stmt->fetch()) {
                $err = 'Этот номер телефона уже зарегистрирован';
            } else {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                // email опционален
                $email = $email ?: null;
                $stmt = $pdo->prepare("INSERT INTO users (phone_number, email, password_hash, role, full_name) VALUES (?, ?, ?, 'patient', ?)");
                try {
                    $stmt->execute([$phone, $email, $hash, $name]);
                    $success = 'Регистрация успешна. Теперь войдите.';
                } catch (PDOException $e) {
                    $err = 'Ошибка регистрации: ' . ($e->errorInfo[1] == 1062 ? 'Такой номер или email уже используется' : $e->getMessage());
                }
            }
        }
    } elseif ($action === 'login') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE phone_number = ?");
        $stmt->execute([$phone]);
        $user = $stmt->fetch();
        if ($user && password_verify($pass, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            redirect($user['role'] === 'patient' ? 'doctors.php' : 'dashboard.php');
        } else $err = 'Неверный номер телефона или пароль';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <title>Вход | Medprofi</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="auth.css">
</head>

<body class="auth-page">
    <a href="index.php" class="back">← На главную</a>
    <div class="auth-card">
        <h2>Вход в систему</h2>
        <form method="POST">
            <input type="hidden" name="action" id="action" value="login">
            <input type="text" name="full_name" id="name-field" placeholder="ФИО" style="display:none;">
            <input type="text" name="email" id="email-field" placeholder="Email (для уведомлений)" style="display:none;">
            <input type="tel" name="phone" placeholder="Номер телефона" required>
            <input type="password" name="password" placeholder="Пароль" required>
            <button type="submit">Войти</button>
        </form>
        <p class="switch">Нет аккаунта? <a href="#" onclick="toggleAuth()">Зарегистрироваться</a></p>
    </div>
    <script>
        function toggleAuth() {
            const f = document.getElementById('action'),
                nameField = document.getElementById('name-field'),
                emailField = document.getElementById('email-field'),
                btn = document.querySelector('button');
            if (f.value === 'login') {
                f.value = 'register';
                nameField.style.display = 'block';
                emailField.style.display = 'block';
                btn.textContent = 'Зарегистрироваться';
            } else {
                f.value = 'login';
                nameField.style.display = 'none';
                emailField.style.display = 'none';
                btn.textContent = 'Войти';
            }
        }
    </script>
    <?php require 'toast.php'; ?>
</body>

</html>