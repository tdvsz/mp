<?php
require 'config.php';
requireAuth(['admin']);
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Обработка добавления специальности
if (isset($_POST['add_specialty'])) {
    $name = trim($_POST['spec_name']);
    $desc = trim($_POST['spec_desc']);
    $pdo->prepare("INSERT INTO specialties (name, description) VALUES (?, ?)")->execute([$name, $desc]);
    $msg = 'Специальность добавлена';
}
    if (isset($_POST['add_doctor'])) {
        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (email, password_hash, role, full_name) VALUES (?, ?, 'doctor', ?)")
            ->execute([$_POST['email'], $hash, $_POST['full_name']]);
        $msg = 'Врач добавлен';
    } elseif (isset($_POST['add_service'])) {
    $spec = trim($_POST['specialty']);
    $pdo->prepare("INSERT INTO services (name, duration_minutes, price, specialty) VALUES (?, ?, ?, ?)")
        ->execute([$_POST['name'], (int)$_POST['duration'], (float)$_POST['price'], $spec === '' ? null : $spec]);
    $msg = 'Услуга добавлена';
}
}

$all_aps = $pdo->query("SELECT a.*, p.full_name as pat, d.full_name as doc, s.name as srv FROM appointments a JOIN users p ON a.patient_id=p.id JOIN users d ON a.doctor_id=d.id JOIN services s ON a.service_id=s.id ORDER BY a.appointment_date DESC")->fetchAll();
$services = $pdo->query("SELECT * FROM services")->fetchAll();
$specialties = $pdo->query("SELECT * FROM specialties ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><title>Админ</title><link rel="stylesheet" href="style.css"></head>
<body>
<a href="dashboard.php" class="back">← В личный кабинет</a>
<h2>Панель администратора</h2>
<div class="grid">
<form method="POST" class="card">
    <h3>Добавить врача</h3>
    <input name="full_name" placeholder="ФИО" required>
    <input name="email" type="email" placeholder="Email" required>
    <input name="password" placeholder="Пароль" required>
    <button name="add_doctor">Добавить</button>
</form>
<form method="POST" class="card">
    <h3>Добавить услугу</h3>
    <input name="name" placeholder="Название" required>
    <div style="display:flex; gap:10px;">
        <input name="duration" type="number" placeholder="Длительность (мин)" required style="flex:1;">
        <input name="price" type="number" step="0.01" placeholder="Стоимость (BUN)" required style="flex:1;">
    </div>
    <label style="font-size:0.85rem; color:#64748b; margin-top:5px; display:block;">Специальность (оставьте пустым для общей):</label>
    <select name="specialty">
        <option value=""> Общая (для всех врачей)</option>
        <?php 
        $specs = $pdo->query("SELECT DISTINCT specialty FROM users WHERE role='doctor' AND specialty IS NOT NULL ORDER BY specialty")->fetchAll(PDO::FETCH_COLUMN);
        foreach($specs as $s): ?>
            <option value="<?=htmlspecialchars($s)?>"><?=htmlspecialchars($s)?></option>
        <?php endforeach; ?>
    </select>
    <button name="add_service" style="margin-top:10px;">Добавить</button>
</form>
<form method="POST" class="card">
    <h3>➕ Добавить специальность</h3>
    <input name="spec_name" placeholder="Название (например: Кардиолог)" required>
    <input name="spec_desc" placeholder="Описание (опционально)">
    <button name="add_specialty">Добавить</button>
</form>
</div>
<p><?=$msg?></p>

<h3>Все бронирования</h3>
<table class="data-table">
<tr><th>ID</th><th>Дата</th><th>Время</th><th>Пациент</th><th>Врач</th><th>Услуга</th><th>Статус</th></tr>
<?php foreach($all_aps as $a): ?>
<tr>
    <td><?=$a['id']?></td>
    <td><?=htmlspecialchars($a['appointment_date'])?></td>
    <td><?=substr($a['start_time'],0,5)?></td>
    <td><?=htmlspecialchars($a['pat'])?></td>
    <td><?=htmlspecialchars($a['doc'])?></td>
    <td><?=htmlspecialchars($a['srv'])?></td>
    <td><?=htmlspecialchars($a['status'])?></td>
</tr>
<?php endforeach; ?>
</table>
</body></html>