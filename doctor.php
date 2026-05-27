<?php
require 'config.php';
requireAuth(['doctor']);

$doc_id = $_SESSION['user_id'];
$active_day = (int)($_GET['day'] ?? date('N'));
$msg = '';

// Получаем информацию о враче
$doctor = $pdo->prepare("SELECT specialty_id FROM users WHERE id = ?");
$doctor->execute([$doc_id]);
$doctor_info = $doctor->fetch();

// Получаем услуги
$services = $pdo->prepare("
    SELECT s.* FROM services s 
    LEFT JOIN specialties sp ON s.specialty_id = sp.id
    WHERE s.specialty_id IS NULL OR s.specialty_id = ?
    ORDER BY s.name
");
$services->execute([$doctor_info['specialty_id']]);
$services = $services->fetchAll();

// === ОБРАБОТКА ДЕЙСТВИЙ ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_slot']) || isset($_POST['edit_slot'])) {
        $day = (int)$_POST['day_of_week'];
        $start = $_POST['start_time'];
        $end = $_POST['end_time'];
        $service_id = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
        $is_break = isset($_POST['is_break']) ? 1 : 0;
        
        if ($is_break) $service_id = null;

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
    
    elseif (isset($_POST['delete_slot'])) {
        $pdo->prepare("DELETE FROM doctor_schedule_slots WHERE id=? AND doctor_id=?")
            ->execute([(int)$_POST['slot_id'], $doc_id]);
        $msg = '🗑 Слот удален!';
    }
}

// Получаем расписание
$schedule = $pdo->prepare("
    SELECT s.*, srv.name as service_name, srv.duration_minutes
    FROM doctor_schedule_slots s 
    LEFT JOIN services srv ON s.service_id = srv.id 
    WHERE s.doctor_id = ? 
    ORDER BY s.day_of_week, s.start_time
");
$schedule->execute([$doc_id]);
$schedule_slots = $schedule->fetchAll();

$days = ['','Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
$day_slots = array_filter($schedule_slots, fn($s) => $s['day_of_week'] == $active_day);

// Параметры для шкалы времени
$start_hour = 8; // Начало рабочего дня
$end_hour = 21;  // Конец рабочего дня
$hours = $end_hour - $start_hour; // 13 часов
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Расписание | Medprofi</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .schedule-container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        
        /* Вкладки дней */
        .day-tabs { display: flex; gap: 8px; margin-bottom: 25px; flex-wrap: wrap; }
        .day-tab { 
            padding: 12px 20px; 
            background: #fff; 
            border: 2px solid var(--border); 
            border-radius: 10px; 
            cursor: pointer; 
            transition: 0.2s; 
            font-weight: 600;
            text-decoration: none;
            color: var(--text);
        }
        .day-tab:hover { background: #f8fafc; border-color: #cbd5e1; }
        .day-tab.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        
        /* Шкала времени */
        .timeline-wrapper {
            background: #fff;
            border-radius: 16px;
            border: 1px solid var(--border);
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        
        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .timeline-date {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text);
        }
        
        .btn-add-slot {
            padding: 10px 20px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
            transition: 0.2s;
        }
        .btn-add-slot:hover { background: var(--primary-dark); }
        
        /* Сама шкала */
        .timeline {
            position: relative;
            height: 120px;
            margin: 30px 0;
            border-radius: 12px;
            overflow: hidden;
        }
        
        /* Фоновая сетка часов */
        .timeline-grid {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            display: flex;
        }
        
        .grid-hour {
            flex: 1;
            border-left: 1px solid #e2e8f0;
            position: relative;
        }
        .grid-hour:first-child { border-left: none; }
        
        .grid-hour::after {
            content: attr(data-hour);
            position: absolute;
            top: -25px;
            left: 0;
            font-size: 0.75rem;
            color: #94a3b8;
            font-weight: 500;
        }
        
        /* Слоты на шкале */
        .slot-block {
            position: absolute;
            top: 20px;
            height: 80px;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 8px;
            cursor: pointer;
            transition: all 0.2s;
            overflow: hidden;
            z-index: 10;
        }
        
        .slot-block.service {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: #fff;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }
        
        .slot-block.break {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: #fff;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
        }
        
        .slot-block:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .slot-name {
            font-weight: 700;
            font-size: 0.9rem;
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 100%;
        }
        
        .slot-time {
            font-size: 0.75rem;
            opacity: 0.9;
            margin-top: 4px;
            white-space: nowrap;
        }
        
        .slot-actions {
            display: none;
            position: absolute;
            top: 5px;
            right: 5px;
            gap: 4px;
        }
        
        .slot-block:hover .slot-actions { display: flex; }
        
        .slot-btn {
            width: 24px;
            height: 24px;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            transition: 0.2s;
        }
        
        .slot-btn.edit {
            background: rgba(255,255,255,0.3);
            color: #fff;
        }
        .slot-btn.edit:hover { background: rgba(255,255,255,0.5); }
        
        .slot-btn.delete {
            background: rgba(239, 68, 68, 0.8);
            color: #fff;
        }
        .slot-btn.delete:hover { background: #ef4444; }
        
        /* Пустое состояние */
        .empty-timeline {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }
        .empty-timeline .icon { font-size: 3rem; margin-bottom: 15px; }
        
        /* Подсказка */
        .timeline-hint {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #64748b;
            font-size: 0.9rem;
            margin-top: 15px;
        }
        
        /* Форма добавления */
        .add-slot-form {
            background: #fff;
            border-radius: 16px;
            border: 1px solid var(--border);
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text);
            font-size: 0.9rem;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.95rem;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 20px 0;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .checkbox-group label {
            cursor: pointer;
            font-weight: 500;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
        }
        
        .btn-save {
            padding: 12px 30px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            transition: 0.2s;
        }
        .btn-save:hover { background: var(--primary-dark); }
        
        .btn-clear {
            padding: 12px 30px;
            background: #fff;
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            transition: 0.2s;
        }
        .btn-clear:hover { background: #f8fafc; }
        
        @media(max-width: 768px) {
            .timeline { height: 100px; }
            .slot-block { height: 60px; top: 20px; }
            .form-row { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; }
            .btn-save, .btn-clear { width: 100%; text-align: center; }
        }
    </style>
</head>
<body>
<header class="site-header">
    <div class="container header-flex">
        <a href="dashboard.php" class="logo">← В кабинет</a>
        <div style="display:flex; gap:10px;">
            <a href="settings.php" class="btn btn-outline">️ Настройки</a>
            <a href="logout.php" class="btn btn-outline">Выйти</a>
        </div>
    </div>
</header>

<main class="schedule-container">
    <?php if($msg): ?>
        <div class="success" style="margin-bottom: 20px;"><?=htmlspecialchars($msg)?></div>
    <?php endif; ?>
    
    <h1 style="margin-bottom: 20px;">📅 Моё расписание</h1>
    
    <!-- Вкладки дней -->
    <div class="day-tabs">
        <?php for($d=1; $d<=7; $d++): ?>
            <a href="?day=<?=$d?>" class="day-tab <?=($d==$active_day?'active':'')?>"><?=$days[$d]?></a>
        <?php endfor; ?>
    </div>
    
    <!-- Шкала времени -->
    <div class="timeline-wrapper">
        <div class="timeline-header">
            <div class="timeline-date">
                🕐 <?=$days[$active_day]?>, <?=date('d.m.Y', strtotime("next $active_day day"))?>
            </div>
            <button class="btn-add-slot" onclick="scrollToForm()">+ Добавить слот</button>
        </div>
        
        <?php if(empty($day_slots)): ?>
            <div class="empty-timeline">
                <div class="icon">📋</div>
                <p>Нет записей на этот день</p>
                <p style="font-size: 0.85rem;">Нажмите "Добавить слот" или кликните на шкалу ниже</p>
            </div>
        <?php else: ?>
            <div class="timeline" id="timeline" onclick="handleTimelineClick(event)">
                <!-- Сетка часов -->
                <div class="timeline-grid">
                    <?php for($h=$start_hour; $h<$end_hour; $h++): ?>
                        <div class="grid-hour" data-hour="<?=sprintf('%02d:00', $h)?>"></div>
                    <?php endfor; ?>
                </div>
                
                <!-- Слоты -->
                <?php foreach($day_slots as $slot): 
                    $start_min = (strtotime($slot['start_time']) - strtotime(sprintf('%02d:00', $start_hour))) / 60;
                    $end_min = (strtotime($slot['end_time']) - strtotime(sprintf('%02d:00', $start_hour))) / 60;
                    $total_minutes = ($end_hour - $start_hour) * 60;
                    
                    $left_percent = ($start_min / $total_minutes) * 100;
                    $width_percent = (($end_min - $start_min) / $total_minutes) * 100;
                    
                    $label = $slot['is_break'] ? ' Перерыв' : ($slot['service_name'] ?? '📋 Слот');
                ?>
                <div class="slot-block <?=$slot['is_break']?'break':'service'?>" 
                     style="left: <?=$left_percent?>%; width: <?=$width_percent?>%;"
                     onclick="event.stopPropagation(); editSlot(<?=json_encode($slot)?>)">
                    <div class="slot-name"><?=htmlspecialchars($label)?></div>
                    <div class="slot-time"><?=substr($slot['start_time'],0,5)?> - <?=substr($slot['end_time'],0,5)?></div>
                    <div class="slot-actions">
                        <button class="slot-btn edit" onclick="event.stopPropagation(); editSlot(<?=json_encode($slot)?>)" title="Редактировать">✏️</button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить этот слот?')">
                            <input type="hidden" name="slot_id" value="<?=$slot['id']?>">
                            <button type="submit" name="delete_slot" class="slot-btn delete" title="Удалить">🗑</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="timeline-hint">
                💡 Кликните по свободному месту на шкале, чтобы быстро добавить слот
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Форма добавления/редактирования -->
    <div class="add-slot-form" id="slotForm">
        <h3 style="margin: 0 0 20px;">➕ Добавить временной слот</h3>
        <form method="POST" id="slotFormInner">
            <input type="hidden" name="edit_id" id="edit_id" value="">
            <input type="hidden" name="day_of_week" id="form_day" value="<?=$active_day?>">
            
            <div class="form-row">
                <div class="form-group">
                    <label>Начало:</label>
                    <input type="time" name="start_time" id="start_time" required min="<?=sprintf('%02d:00', $start_hour)?>" max="<?=sprintf('%02d:00', $end_hour)?>">
                </div>
                <div class="form-group">
                    <label>Окончание:</label>
                    <input type="time" name="end_time" id="end_time" required min="<?=sprintf('%02d:00', $start_hour)?>" max="<?=sprintf('%02d:00', $end_hour)?>">
                </div>
            </div>

            <div class="form-group">
                <label>Услуга:</label>
                <select name="service_id" id="service_id">
                    <option value="">-- Выберите услугу --</option>
                    <?php foreach($services as $srv): ?>
                        <option value="<?=$srv['id']?>"><?=htmlspecialchars($srv['name'])?> (<?=$srv['duration_minutes']?> мин)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" name="is_break" id="is_break" value="1" onchange="toggleServiceMode()">
                <label for="is_break">Это перерыв/обед (без услуги)</label>
            </div>
            
            <div class="form-actions">
                <button type="submit" name="add_slot" class="btn-save">💾 Сохранить слот</button>
                <button type="button" class="btn-clear" onclick="resetForm()">Очистить</button>
            </div>
        </form>
    </div>
</main>

<script>
// Клик по шкале для быстрого добавления
function handleTimelineClick(event) {
    const timeline = document.getElementById('timeline');
    if (!timeline) return;
    
    const rect = timeline.getBoundingClientRect();
    const clickX = event.clientX - rect.left;
    const clickPercent = (clickX / rect.width) * 100;
    
    const totalMinutes = (<?=$end_hour?> - <?=$start_hour?>) * 60;
    const clickMinute = (clickPercent / 100) * totalMinutes;
    
    const startMinute = Math.floor(clickMinute / 30) * 30; // Округляем до 30 минут
    const endMinute = startMinute + 60; // По умолчанию 1 час
    
    const startHour = <?=$start_hour?> + Math.floor(startMinute / 60);
    const startMin = startMinute % 60;
    const endHour = <?=$start_hour?> + Math.floor(endMinute / 60);
    const endMin = endMinute % 60;
    
    document.getElementById('start_time').value = `${String(startHour).padStart(2,'0')}:${String(startMin).padStart(2,'0')}`;
    document.getElementById('end_time').value = `${String(endHour).padStart(2,'0')}:${String(endMin).padStart(2,'0')}`;
    
    // Скролл к форме
    scrollToForm();
}

// Скролл к форме
function scrollToForm() {
    document.getElementById('slotForm').scrollIntoView({behavior: 'smooth'});
}

// Переключение режима "Перерыв"
function toggleServiceMode() {
    const isBreak = document.getElementById('is_break').checked;
    const serviceSelect = document.getElementById('service_id');
    serviceSelect.disabled = isBreak;
    if (isBreak) serviceSelect.value = '';
}

// Сброс формы
function resetForm() {
    document.getElementById('slotFormInner').reset();
    document.getElementById('edit_id').value = '';
    document.getElementById('service_id').disabled = false;
    const btn = document.querySelector('button[name="add_slot"]');
    btn.name = 'add_slot';
    btn.textContent = ' Сохранить слот';
}

// Редактирование слота
function editSlot(slot) {
    document.getElementById('edit_id').value = slot.id;
    document.getElementById('form_day').value = slot.day_of_week;
    document.getElementById('start_time').value = slot.start_time;
    document.getElementById('end_time').value = slot.end_time;
    
    if (slot.is_break == 1) {
        document.getElementById('is_break').checked = true;
        document.getElementById('service_id').disabled = true;
        document.getElementById('service_id').value = '';
    } else {
        document.getElementById('is_break').checked = false;
        document.getElementById('service_id').disabled = false;
        document.getElementById('service_id').value = slot.service_id || '';
    }
    
    // Меняем кнопку
    const btn = document.querySelector('button[name="add_slot"]');
    btn.name = 'edit_slot';
    btn.textContent = '✏️ Обновить слот';
    
    // Скролл к форме
    scrollToForm();
}
</script>
<?php require 'toast.php'; ?>
</body>
</html>