<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Medprofi — Современная клиника</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="landing-page">
<header class="site-header">
    <div class="container header-flex">
        <a href="index.php" class="logo">Medprofi</a>
        <a href="auth.php" class="btn btn-primary">Войти / Регистрация</a>
    </div>
</header>

<main>
    <section class="hero">
        <div class="container">
            <h1>Ваша забота — наша профессия</h1>
            <p>Онлайн-запись к сертифицированным специалистам. Без очередей, удобно и в любое время.</p>
            <a href="auth.php" class="btn btn-large btn-primary">Записаться онлайн</a>
        </div>
    </section>

    <section class="advantages">
        <div class="container">
            <h2>Почему выбирают Medprofi</h2>
            <div class="adv-grid">
                <div class="adv-card">
                    <div class="icon">🩺</div>
                    <h3>Опытные врачи</h3>
                    <p>Сертифицированные специалисты с клиническим стажем от 5 лет</p>
                </div>
                <div class="adv-card">
                    <div class="icon">⏱</div>
                    <h3>Точное время</h3>
                    <p>Приходите к назначенному часу. Никаких ожиданий в очередях</p>
                </div>
                <div class="adv-card">
                    <div class="icon">💻</div>
                    <h3>Личный кабинет</h3>
                    <p>Управляйте записями, историей посещений и результатами онлайн</p>
                </div>
                <div class="adv-card">
                    <div class="icon">🔒</div>
                    <h3>Конфиденциальность</h3>
                    <p>Защищённые данные и соблюдение врачебной этики</p>
                </div>
            </div>
        </div>
    </section>
</main>

<footer class="site-footer">
    <div class="container">© 2026 Medprofi. Все права защищены. | г. Москва, ул. Примерная, 10</div>
</footer>
<?php require 'includes/toast.php'; ?>
</body>
</html>