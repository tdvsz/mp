<?php
require 'config.php';
$err = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    $phone = trim($_POST['phone']);
    $pass = $_POST['password'];

    if ($action === 'register') {
        $name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        if (!$phone || !$pass || !$name) {
            $err = 'Заполните все обязательные поля';
        } elseif (!preg_match('/^\+375[0-9]{9}$/', $phone)) {
            $err = 'Неверный формат номера телефона (ожидается 9 цифр после +375)';
        } else {
            // Проверка уникальности номера
            $stmt = $pdo->prepare("SELECT id FROM users WHERE phone_number = ?");
            $stmt->execute([$phone]);
            if ($stmt->fetch()) {
                $err = 'Этот номер телефона уже зарегистрирован';
            } else {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
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
        } else {
            $err = 'Неверный номер телефона или пароль';
        }
    }
}

// Запоминание состояния
$is_register = (isset($_POST['action']) && $_POST['action'] === 'register' && !$success);

$posted_phone = $_POST['phone'] ?? '';
$phone_tail = '';
if (strpos($posted_phone, '+375') === 0) {
    $phone_tail = substr($posted_phone, 4);
    if (strlen($phone_tail) === 9) {
        $phone_tail = substr($phone_tail, 0, 2) . ' ' . substr($phone_tail, 2, 3) . ' ' . substr($phone_tail, 5, 2) . ' ' . substr($phone_tail, 7, 2);
    }
}
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <title>Авторизация | Medprofi</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="auth.css">
    <link type="image/x-icon" href="favicon.ico" rel="shortcut icon">
</head>

<body class="auth-page">
    <a href="index.php" class="back" style="margin-bottom: 20px;">На главную</a>

    <div class="auth-card">
        <h2 id="auth-title"><?= $is_register ? 'Регистрация' : 'Вход в систему' ?></h2>

        <form method="POST" id="auth-form" onsubmit="return prepareForm()">
            <input type="hidden" name="action" id="action" value="<?= $is_register ? 'register' : 'login' ?>">

            <div id="register-fields" style="display: <?= $is_register ? 'block' : 'none' ?>;">
                <div class="form-group">
                    <label>ФИО</label>
                    <input type="text" name="full_name" id="name-field" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" <?= $is_register ? 'required' : '' ?>>
                </div>
                <div class="form-group">
                    <label>Email (для уведомлений)</label>
                    <input type="email" name="email" id="email-field" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Номер телефона</label>
                <div class="phone-group">
                    <div class="phone-prefix">+375</div>
                    <input type="tel" id="phone_input" placeholder="29 123 45 67" required value="<?= htmlspecialchars($phone_tail) ?>" oninput="formatPhone(this)">
                </div>
                <input type="hidden" name="phone" id="full_phone" value="<?= htmlspecialchars($posted_phone) ?>">
            </div>

            <div class="form-group">
                <label>Пароль</label>
                <input type="password" name="password" placeholder="Введите пароль" required>
            </div>

            <button type="submit" id="submit-btn" class="btn btn-primary btn-large full-width">
                <?= $is_register ? 'Зарегистрироваться' : 'Войти' ?>
            </button>
        </form>

        <p class="switch" id="switch-text">
            <?php if ($is_register): ?>
                Уже есть аккаунт? <a href="#" onclick="toggleAuth(event)">Войти</a>
            <?php else: ?>
                Нет аккаунта? <a href="#" onclick="toggleAuth(event)">Зарегистрироваться</a>
            <?php endif; ?>
        </p>
    </div>

    <script>
        function toggleAuth(e) {
            if (e) e.preventDefault();
            const action = document.getElementById('action');
            const regFields = document.getElementById('register-fields');
            const nameField = document.getElementById('name-field');
            const btn = document.getElementById('submit-btn');
            const title = document.getElementById('auth-title');
            const switchText = document.getElementById('switch-text');

            if (action.value === 'login') {
                action.value = 'register';
                regFields.style.display = 'block';
                nameField.required = true;
                btn.textContent = 'Зарегистрироваться';
                title.textContent = 'Регистрация';
                switchText.innerHTML = 'Уже есть аккаунт? <a href="#" onclick="toggleAuth(event)">Войти</a>';
            } else {
                action.value = 'login';
                regFields.style.display = 'none';
                nameField.required = false;
                btn.textContent = 'Войти';
                title.textContent = 'Вход в систему';
                switchText.innerHTML = 'Нет аккаунта? <a href="#" onclick="toggleAuth(event)">Зарегистрироваться</a>';
            }
        }

        function formatPhone(input) {
            let val = input.value.replace(/\D/g, '');
            if (val.length > 9) val = val.substring(0, 9);

            let formatted = val;
            if (val.length > 2) formatted = val.substring(0, 2) + ' ' + val.substring(2);
            if (val.length > 5) formatted = formatted.substring(0, 6) + ' ' + formatted.substring(6);
            if (val.length > 7) formatted = formatted.substring(0, 9) + ' ' + formatted.substring(9);

            input.value = formatted;

            document.getElementById('full_phone').value = val.length > 0 ? '+375' + val : '';
        }

        function prepareForm() {
            const fullPhone = document.getElementById('full_phone').value;
            if (fullPhone.length !== 13) {
                if (typeof Toast !== 'undefined') Toast.error('Введите номер телефона полностью (9 цифр)');
                else alert('Введите номер телефона полностью (9 цифр)');
                return false;
            }
            return true;
        }

        document.addEventListener("DOMContentLoaded", () => {
            const phoneInput = document.getElementById('phone_input');
            if (phoneInput.value) formatPhone(phoneInput);
        });
    </script>

    <?php require 'toast.php'; ?>
</body>

</html>