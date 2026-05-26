<?php
require 'config.php';
$err = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    $email = trim($_POST['email']);
    $pass = $_POST['password'];
    
    if ($action === 'register') {
        $name = trim($_POST['full_name']);
        if (!$email || !$pass || !$name) $err = 'Заполните все поля';
        elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) $err = 'Неверный формат email';
        else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, role, full_name) VALUES (?, ?, 'patient', ?)");
            try { $stmt->execute([$email, $hash, $name]); $success = 'Регистрация успешна. Войдите.'; }
            catch (PDOException $e) { $err = 'Этот email уже зарегистрирован'; }
        }
    } elseif ($action === 'login') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($pass, $user['password_hash'])) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
    // Пациенты идут к врачам, остальные в кабинет
    redirect($user['role'] === 'patient' ? 'doctors.php' : 'dashboard.php');
} else $err = 'Неверный email или пароль';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><title>Вход | Medprofi</title><link rel="stylesheet" href="style.css"></head>
<body class="auth-page">
<a href="index.php" class="back">← На главную</a>
<div class="auth-card">
    <h2>Вход в систему</h2>
    <form method="POST">
        <input type="hidden" name="action" id="action" value="login">
        <input type="text" name="full_name" id="name-field" placeholder="ФИО" style="display:none;">
        <input type="email" name="email" placeholder="Номер телефона" required>
        <input type="password" name="password" placeholder="Пароль" required>
        <button type="submit">Войти</button>
    </form>
    <p class="switch">Нет аккаунта? <a href="#" onclick="toggleAuth()">Зарегистрироваться</a></p>
</div>
<script>
function toggleAuth() {
    const f = document.getElementById('action'), n = document.getElementById('name-field');
    if(f.value === 'login') { f.value = 'register'; n.style.display = 'block'; document.querySelector('button').textContent = 'Зарегистрироваться'; }
    else { f.value = 'login'; n.style.display = 'none'; document.querySelector('button').textContent = 'Войти'; }
}
</script>
</body>
<?php require 'toast.php'; ?>
</html>