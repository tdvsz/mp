<?php
require 'config.php';
requireAuth(['doctor']);


$doc_id = $_SESSION['user_id'];
$msg = '';

// Обработка обновления профиля (описания)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $desc = trim($_POST['description']);
    $pdo->prepare("UPDATE users SET description = ? WHERE id = ?")->execute([$desc, $doc_id]);
    $msg = '✅ Описание обновлено!';
}

// Получаем текущее описание
$doctor_info = $pdo->prepare("SELECT description FROM users WHERE id = ?");
$doctor_info->execute([$doc_id]);
$current_desc = $doctor_info->fetchColumn();

// Получаем информацию о враче (его специальность)
$doctor = $pdo->prepare("SELECT specialty_id FROM users WHERE id = ?");
$doctor->execute([$doc_id]);
$doctor_info = $doctor->fetch();

// Получаем услуги: ТОЛЬКО общие (NULL) ИЛИ для специальности врача
$stmt = $pdo->prepare("
    SELECT s.* FROM services s 
    LEFT JOIN specialties sp ON s.specialty_id = sp.id
    WHERE s.specialty_id IS NULL OR s.specialty_id = ?
    ORDER BY s.name
");
$stmt->execute([$doctor_info['specialty_id']]);
$services = $stmt->fetchAll();

// Добавление/редактирование слота
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_slot']) || isset($_POST['edit_slot'])) {
        $day = (int)$_POST['day_of_week'];
        $start = $_POST['start_time'];
        $end = $_POST['end_time'];
        // Если перерыв, service_id игнорируем (ставим NULL)
        $service_id = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
        $is_break = isset($_POST['is_break']) ? 1 : 0;
        
        if ($is_break) $service_id = null;

        // Проверка на пересечение
        $overlap_sql = "
            SELECT id FROM doctor_schedule_slots 
            WHERE doctor_id = ? AND day_of_week = ? 
            AND (
                (start_time <= ? AND end_time > ?) OR
                (start_time < ? AND end_time >= ?) OR
                (start_time >= ? AND end_time <= ?)
            )
        ";
        $params = [$doc_id, $day, $start, $start, $end, $end, $start, $end];
        
        if (isset($_POST['edit_slot']) && !empty($_POST['edit_id'])) {
            $overlap_sql .= " AND id != ?";
            $params[] = (int)$_POST['edit_id'];
        }
        
        $overlap = $pdo->prepare($overlap_sql);
        $overlap->execute($params);
        
        if ($overlap->fetch()) {
            $msg = '❌ Это время пересекается с существующим слотом!';
        } elseif ($start >= $end) {
            $msg = '❌ Время начала должно быть раньше времени окончания!';
        } else {
            if (isset($_POST['edit_slot']) && !empty($_POST['edit_id'])) {
                $pdo->prepare("UPDATE doctor_schedule_slots SET start_time=?, end_time=?, service_id=?, is_break=? WHERE id=? AND doctor_id=?")
                    ->execute([$start, $end, $service_id, $is_break, $_POST['edit_id'], $doc_id]);
                $msg = '✅ Слот обновлен!';
            } else {
                $pdo->prepare("INSERT INTO doctor_schedule_slots (doctor_id, day_of_week, start_time, end_time, service_id, is_break) VALUES (?,?,?,?,?,?)")
                    ->execute([$doc_id, $day, $start, $end, $service_id, $is_break]);
                $msg = '✅ Слот добавлен!';
            }
        }
    }
}

// Удаление слота
if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM doctor_schedule_slots WHERE id=? AND doctor_id=?")
        ->execute([(int)$_GET['delete'], $doc_id]);
    $msg = '🗑 Слот удален!';
    header("Location: doctor.php?msg=deleted");
    exit;
}

// Получаем расписание врача
$schedule = $pdo->prepare("
    SELECT s.*, srv.name as service_name, srv.duration_minutes
    FROM doctor_schedule_slots s 
    LEFT JOIN services srv ON s.service_id = srv.id 
    WHERE s.doctor_id = ? 
    ORDER BY s.day_of_week, s.start_time
");
$schedule->execute([$doc_id]);
$schedule_slots = $schedule->fetchAll();

$days = ['','Понедельник','Вторник','Среда','Четверг','Пятница','Суббота','Воскресенье'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Мое расписание | Medprofi</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="site-header">
    <div class="container header-flex">
        <a href="dashboard.php" class="logo">← В кабинет</a>
        <div style="display:flex; gap:10px;">
            <a href="settings.php" class="btn btn-outline">Настройки</a>
            <a href="logout.php" class="btn btn-outline">Выйти</a>
        </div>
    </div>
</header>

<main class="container">
    <!-- Форма редактирования профиля врача -->
<div class="card" style="margin-bottom: 20px;">
    <h3>Мой профиль</h3>
    <form method="POST">
        <label>Описание / О себе:</label>
        <textarea name="description" rows="3" style="width:100%; padding:10px; border:1px solid #e2e8f0; border-radius:6px; resize:vertical;"><?=htmlspecialchars($current_desc ?? '')?></textarea>
        <button type="submit" name="update_profile" class="btn btn-primary" style="margin-top:10px;">Сохранить описание</button>
    </form>
</div>

<?php if($msg): ?><div class="success"><?=htmlspecialchars($msg)?></div><?php endif; ?>
    <h1>Мое расписание</h1>
    
    <?php if($msg): ?>
        <div class="success"><?=htmlspecialchars($msg)?></div>
    <?php endif; ?>
    
    <!-- Форма добавления слота -->
    <div class="card">
        <h3>Добавить временной слот</h3>
        <form method="POST" id="slotForm">
            <input type="hidden" name="edit_id" id="edit_id" value="">
            
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:15px;">
                <div>
                    <label>День недели:</label>
                    <select name="day_of_week" id="day_of_week" required>
                        <?php for($i=1;$i<=7;$i++): ?>
                            <option value="<?=$i?>"><?=$days[$i]?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div>
                    <label>Начало:</label>
                    <input type="time" name="start_time" id="start_time" required>
                </div>
                
                <div>
                    <label>Окончание:</label>
                    <input type="time" name="end_time" id="end_time" required>
                </div>
            </div>

            <!-- Блок выбора услуги -->
            <div id="service-block" style="margin-top: 15px;">
                <label>Услуга:</label>
                <input type="hidden" name="service_id" id="service_id" value="">
                <div class="services-grid" id="service-grid">
                    <?php if(empty($services)): ?>
                        <p style="color:#666; grid-column: 1/-1;">Нет доступных услуг для вашей специальности.</p>
                    <?php else: ?>
                        <?php foreach($services as $srv): ?>
                            <div class="service-item" data-id="<?=$srv['id']?>" onclick="selectService(<?=$srv['id']?>, this)">
                                <div class="service-name"><?=htmlspecialchars($srv['name'])?></div>
                                <div class="service-details">
                                    <span class="service-duration"><?=$srv['duration_minutes']?> мин</span>
                                    <span class="service-price"><?=number_format($srv['price'], 0, ',', ' ')?> BUN</span>
                                </div>
                                <span class="service-badge"><?=($srv['specialty_id'] ? 'Профильная' : 'Общая')?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Чекбокс перерыва -->
            <div style="margin-top:15px; display:flex; align-items:center; gap:10px;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                    <input type="checkbox" name="is_break" id="is_break" value="1" onchange="toggleServiceMode()">
                    <span>Это перерыв/обед (без услуги)</span>
                </label>
            </div>
            
            <div style="margin-top:20px; display:flex; gap:10px;">
                <button type="submit" name="add_slot" class="btn btn-primary">Сохранить слот</button>
                <button type="button" class="btn btn-outline" onclick="resetForm()">Очистить</button>
            </div>
        </form>
    </div>
    
    <!-- Расписание по дням -->
    <h2 style="margin:30px 0 20px;">Текущее расписание</h2>
    
    <?php for($day=1; $day<=7; $day++): 
        $day_slots = array_filter($schedule_slots, fn($s) => $s['day_of_week'] == $day);
        if(empty($day_slots)) continue;
    ?>
    <div class="day-schedule">
        <h3><?=$days[$day]?></h3>
        <div class="slots-list">
            <?php foreach($day_slots as $slot): ?>
                <div class="slot-card <?=($slot['is_break'] ? 'break' : '')?>">
                    <div class="slot-time">
                        <strong><?=substr($slot['start_time'],0,5)?> - <?=substr($slot['end_time'],0,5)?></strong>
                    </div>
                    <div class="slot-info">
                        <?php if($slot['is_break']): ?>
                            <span class="slot-badge break-badge">Перерыв</span>
                        <?php else: ?>
                            <span class="slot-badge service-badge"><?=htmlspecialchars($slot['service_name'] ?? 'Без услуги')?></span>
                            <?php if($slot['service_name'] && $slot['duration_minutes']): 
                                $duration = strtotime($slot['end_time']) - strtotime($slot['start_time']);
                                $slots_count = floor($duration / ($slot['duration_minutes'] * 60));
                            ?>
                                <span class="slots-count">(~<?=$slots_count?> записей)</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="slot-actions">
                        <button class="btn-edit" onclick='editSlot(<?=json_encode($slot)?>)'>✏️</button>
                        <a href="?delete=<?=$slot['id']?>" class="btn-delete" onclick="return confirm('Удалить этот слот?')">🗑</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endfor; ?>
    
    <?php if(empty($schedule_slots)): ?>
        <p style="text-align:center; color:#64748b; padding:40px;">Расписание пока пустое. Добавьте временные слоты выше.</p>
    <?php endif; ?>
</main>

<script>
// Выбор услуги по клику на карточку
function selectService(id, el) {
    // Убираем выделение со всех
    document.querySelectorAll('.service-item').forEach(item => item.classList.remove('selected'));
    
    // Выделяем текущую
    el.classList.add('selected');
    
    // Записываем ID в скрытое поле
    document.getElementById('service_id').value = id;
}

// Переключение режима "Перерыв"
function toggleServiceMode() {
    const isBreak = document.getElementById('is_break').checked;
    const grid = document.getElementById('service-grid');
    const serviceInput = document.getElementById('service_id');
    
    if (isBreak) {
        grid.style.opacity = '0.5';
        grid.style.pointerEvents = 'none'; // Блокируем клики
        serviceInput.value = ''; // Сбрасываем услугу
        document.querySelectorAll('.service-item').forEach(item => item.classList.remove('selected'));
    } else {
        grid.style.opacity = '1';
        grid.style.pointerEvents = 'auto';
    }
}

// Сброс формы
function resetForm() {
    document.getElementById('slotForm').reset();
    document.getElementById('edit_id').value = '';
    document.getElementById('service_id').value = '';
    document.querySelectorAll('.service-item').forEach(item => item.classList.remove('selected'));
    toggleServiceMode(); // Сброс визуала перерыва
    
    const btn = document.querySelector('button[name="add_slot"]');
    btn.name = 'add_slot';
    btn.textContent = ' Сохранить слот';
}

// Редактирование слота
function editSlot(slot) {
    // Заполняем основные поля
    document.getElementById('edit_id').value = slot.id;
    document.getElementById('day_of_week').value = slot.day_of_week;
    document.getElementById('start_time').value = slot.start_time;
    document.getElementById('end_time').value = slot.end_time;
    
    // Обработка перерыва
    document.getElementById('is_break').checked = (slot.is_break == 1);
    toggleServiceMode();
    
    // Обработка услуги
    if (slot.service_id && !slot.is_break) {
        document.getElementById('service_id').value = slot.service_id;
        // Ищем карточку и подсвечиваем её
        const card = document.querySelector(`.service-item[data-id="${slot.service_id}"]`);
        if (card) card.classList.add('selected');
    } else {
        document.getElementById('service_id').value = '';
        document.querySelectorAll('.service-item').forEach(item => item.classList.remove('selected'));
    }
    
    // Меняем кнопку
    const btn = document.querySelector('button[name="add_slot"]');
    btn.name = 'edit_slot';
    btn.textContent = '✏️ Обновить слот';
    
    // Скролл к форме
    document.querySelector('.card').scrollIntoView({behavior: 'smooth'});
}
</script>

<style>
/* Стили для карточек услуг в расписании врача */
.services-grid { 
    display: grid; 
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); 
    gap: 10px; 
    margin-top: 10px; 
    transition: opacity 0.2s;
}

.service-item { 
    background: #fff; 
    border: 2px solid var(--border); 
    border-radius: 10px; 
    padding: 12px; 
    cursor: pointer; 
    transition: all 0.2s;
    position: relative;
}

.service-item:hover { 
    border-color: #94a3b8;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.05);
}

.service-item.selected { 
    background: #eff6ff; 
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
}

.service-name { 
    font-weight: 600; 
    color: #0f172a; 
    margin-bottom: 5px;
    font-size: 0.9rem;
    line-height: 1.2;
}

.service-details { 
    display: flex; 
    justify-content: space-between; 
    align-items: center;
    margin-bottom: 5px;
    font-size: 0.8rem;
}

.service-price { 
    color: var(--primary); 
    font-weight: 700;
}

.service-badge { 
    display: block;
    font-size: 0.7rem;
    color: #64748b;
    margin-top: 4px;
}

/* Стили для списка расписания */
.day-schedule { 
    background: #fff; 
    border-radius: 10px; 
    padding: 20px; 
    margin-bottom: 20px; 
    border: 1px solid var(--border);
}
.day-schedule h3 { 
    margin: 0 0 15px; 
    color: var(--primary);
    border-bottom: 2px solid var(--border);
    padding-bottom: 10px;
}
.slots-list { 
    display: flex; 
    flex-direction: column; 
    gap: 10px; 
}
.slot-card { 
    display: flex; 
    align-items: center; 
    gap: 20px; 
    padding: 15px; 
    background: #f8fafc; 
    border-radius: 8px; 
    border-left: 4px solid var(--primary);
    transition: all 0.2s;
}
.slot-card.break { 
    background: #fef3c7; 
    border-left-color: #f59e0b;
}
.slot-card:hover { 
    transform: translateX(5px); 
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.slot-time { 
    min-width: 140px; 
    font-size: 1.1rem;
}
.slot-info { 
    flex-grow: 1; 
    display: flex; 
    align-items: center; 
    gap: 10px;
    flex-wrap: wrap;
}
.slot-badge { 
    padding: 5px 10px; 
    border-radius: 20px; 
    font-size: 0.85rem; 
    font-weight: 500;
}
.service-badge-list { 
    background: #dbeafe; 
    color: #1e40af;
}
.break-badge { 
    background: #fde68a; 
    color: #b45309;
}
.slots-count { 
    color: #64748b; 
    font-size: 0.8rem;
}
.slot-actions { 
    display: flex; 
    gap: 8px; 
}
.btn-edit, .btn-delete { 
    padding: 6px 10px; 
    border-radius: 6px; 
    border: none;
    cursor: pointer;
    font-size: 1.1rem;
    transition: 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.btn-edit { 
    background: #dbeafe; 
    color: #1e40af;
}
.btn-edit:hover { 
    background: #3b82f6; 
    color: #fff;
}
.btn-delete { 
    background: #fee2e2; 
    color: #b91c1c;
}
.btn-delete:hover { 
    background: #ef4444; 
    color: #fff;
}
</style>
<?php require 'includes/toast.php'; ?>
</body>
</html>