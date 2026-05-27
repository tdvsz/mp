<?php
require 'config.php';
requireAuth(['doctor']);

$doc_id = $_SESSION['user_id'];

// Проверяем и добавляем поле description в таблицу users, если его нет
try {
    $pdo->query("SELECT description FROM users LIMIT 1");
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), "Unknown column 'description'")) {
        $pdo->exec("ALTER TABLE users ADD COLUMN description TEXT NULL");
    }
}

$active_tab = $_GET['tab'] ?? 'schedule';
$msg = '';

$doctor = $pdo->prepare("SELECT specialty_id, description FROM users WHERE id = ?");
$doctor->execute([$doc_id]);
$doctor_info = $doctor->fetch();

// Обработка сохранения описания
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_description'])) {
    $description = trim($_POST['description']);
    $stmt = $pdo->prepare("UPDATE users SET description = ? WHERE id = ?");
    $stmt->execute([$description, $doc_id]);
    $msg = 'Описание сохранено!';
    $doctor_info['description'] = $description;
}

// Получаем услуги для расписания (для карточек)
$services = $pdo->prepare("
    SELECT s.* FROM services s 
    LEFT JOIN specialties sp ON s.specialty_id = sp.id
    WHERE s.specialty_id IS NULL OR s.specialty_id = ?
    ORDER BY s.name
");
$services->execute([$doctor_info['specialty_id']]);
$services = $services->fetchAll();

// Обработка слотов (расписание)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_slot']) || isset($_POST['edit_slot'])) {
        $day = (int)$_POST['day_of_week'];
        $start = $_POST['start_time'];
        $end = $_POST['end_time'];
        $service_id = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
        $is_break = isset($_POST['is_break']) && $_POST['is_break'] == 1 ? 1 : 0;
        
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
            $err = 'Это время пересекается с существующим слотом!';
        } elseif ($start >= $end) {
            $err = 'Время начала должно быть раньше времени окончания!';
        } else {
            if (isset($_POST['edit_slot']) && !empty($_POST['edit_id'])) {
                $pdo->prepare("UPDATE doctor_schedule_slots SET start_time=?, end_time=?, service_id=?, is_break=? WHERE id=? AND doctor_id=?")
                    ->execute([$start, $end, $service_id, $is_break, $_POST['edit_id'], $doc_id]);
                $msg = 'Слот обновлен!';
            } else {
                $pdo->prepare("INSERT INTO doctor_schedule_slots (doctor_id, day_of_week, start_time, end_time, service_id, is_break) VALUES (?,?,?,?,?,?)")
                    ->execute([$doc_id, $day, $start, $end, $service_id, $is_break]);
                $msg = 'Слот добавлен!';
            }
        }
    }
    
    elseif (isset($_POST['delete_slot'])) {
        $pdo->prepare("DELETE FROM doctor_schedule_slots WHERE id=? AND doctor_id=?")
            ->execute([(int)$_POST['slot_id'], $doc_id]);
        $msg = '🗑 Слот удален!';
    }
}

// Получаем расписание для вкладки "Расписание"
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
$active_day = (int)($_GET['day'] ?? date('N'));
$day_slots = array_filter($schedule_slots, fn($s) => $s['day_of_week'] == $active_day);

$start_hour = 8;
$end_hour = 21;
$total_hours = $end_hour - $start_hour;
$total_minutes = $total_hours * 60;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Расписание | Medprofi</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Вкладки */
        .doctor-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            border-bottom: 2px solid var(--border);
            padding-bottom: 0;
        }
        .doctor-tab {
            padding: 10px 24px;
            font-size: 1rem;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            border-radius: 8px 8px 0 0;
            transition: 0.2s;
        }
        .doctor-tab:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
        .doctor-tab.active {
            color: var(--primary);
            border-bottom: 3px solid var(--primary);
            background: transparent;
        }

        /* Шкала времени */
        .schedule-container { max-width: 1200px; margin: 0 auto; padding: 20px; }
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
        
        .timeline {
            position: relative;
            height: 120px;
            margin: 30px 0 45px;
            border-radius: 12px;
            overflow: hidden;
        }
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
        /* Верхние подписи */
        .grid-hour::after {
            content: attr(data-hour);
            position: absolute;
            top: -25px;
            left: 0;
            font-size: 0.75rem;
            color: #94a3b8;
            font-weight: 500;
        }
        /* Нижние подписи */
        .timeline-labels {
            display: flex;
            position: relative;
            margin-top: 10px;
            height: 25px;
        }
        .timeline-labels .label-hour {
            flex: 1;
            text-align: left;
            font-size: 0.7rem;
            color: #94a3b8;
            font-weight: 500;
            padding-left: 4px;
        }
        
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
        .empty-timeline {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }
        .timeline-hint {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #64748b;
            font-size: 0.9rem;
            margin-top: 15px;
        }
        
        /* Форма добавления/редактирования */
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
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 20px 0;
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
        }
        
        /* Карточки услуг (как в book.php) */
        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
            margin-top: 10px;
            margin-bottom: 20px;
        }
        .service-item {
            background: #f8fafc;
            border: 2px solid var(--border);
            border-radius: 10px;
            padding: 12px;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }
        .service-item:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
            transform: translateY(-2px);
        }
        .service-item.selected {
            background: #eff6ff;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .service-name {
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 6px;
            font-size: 0.95rem;
        }
        .service-details {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            font-size: 0.8rem;
        }
        .service-price {
            color: var(--primary);
            font-weight: 700;
        }
        .service-badge {
            display: inline-block;
            font-size: 0.7rem;
            color: #64748b;
            background: #e2e8f0;
            padding: 2px 8px;
            border-radius: 12px;
        }
        
        /* Стили для вкладки описания */
        .description-card {
            background: #fff;
            border-radius: 16px;
            border: 1px solid var(--border);
            padding: 30px;
        }
        .description-card h3 {
            margin-top: 0;
            margin-bottom: 20px;
            color: #0f172a;
        }
        .description-card textarea {
            width: 100%;
            min-height: 300px;
            padding: 15px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-family: inherit;
            font-size: 0.95rem;
            line-height: 1.5;
            resize: vertical;
        }
        .description-card textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .service-item.break-item {
    background: #fffbeb;
    border-color: #f59e0b;
}
.service-item.break-item:hover {
    background: #fef3c7;
    border-color: #d97706;
}
.service-item.break-item.selected {
    background: #fef3c7;
    border-color: #f59e0b;
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.2);
}
        
        @media(max-width: 768px) {
            .timeline { height: 100px; }
            .slot-block { height: 60px; top: 20px; }
            .form-row { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; }
            .btn-save, .btn-clear { width: 100%; text-align: center; }
            .services-grid { grid-template-columns: 1fr; }
        }
    </style>
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

<main class="schedule-container">
    <!-- Вкладки -->
    <div class="doctor-tabs">
        <a href="?tab=schedule" class="doctor-tab <?=$active_tab === 'schedule' ? 'active' : ''?>">Расписание</a>
        <a href="?tab=description" class="doctor-tab <?=$active_tab === 'description' ? 'active' : ''?>">Описание</a>
    </div>

    <?php if ($active_tab === 'schedule'): ?>
        <h1 style="margin-bottom: 20px;">Моё расписание</h1>
        
        <div class="day-tabs">
            <?php for($d=1; $d<=7; $d++): ?>
                <a href="?tab=schedule&day=<?=$d?>" class="day-tab <?=($d==$active_day?'active':'')?>"><?=$days[$d]?></a>
            <?php endfor; ?>
        </div>
        
        <div class="timeline-wrapper">
            <?php if(empty($day_slots)): ?>
                <div class="empty-timeline">
                    <div class="icon"></div>
                    <p>Нет записей на этот день</p>
                </div>
            <?php else: ?>
                <div class="timeline" id="timeline" onclick="handleTimelineClick(event)">
                    <div class="timeline-grid">
                        <?php for($h=$start_hour; $h<$end_hour; $h++): ?>
                            <div class="grid-hour" data-hour="<?=sprintf('%02d:00', $h)?>"></div>
                        <?php endfor; ?>
                    </div>
                    <?php foreach($day_slots as $slot): 
                        $start_min = (strtotime($slot['start_time']) - strtotime(sprintf('%02d:00', $start_hour))) / 60;
                        $end_min = (strtotime($slot['end_time']) - strtotime(sprintf('%02d:00', $start_hour))) / 60;
                        $left_percent = ($start_min / $total_minutes) * 100;
                        $width_percent = (($end_min - $start_min) / $total_minutes) * 100;
                        $label = $slot['is_break'] ? 'Перерыв' : ($slot['service_name'] ?? 'Слот');
                    ?>
                    <div class="slot-block <?=$slot['is_break']?'break':'service'?>" 
                         style="left: <?=$left_percent?>%; width: <?=$width_percent?>%;"
                         onclick="event.stopPropagation(); editSlot(<?=htmlspecialchars(json_encode($slot), ENT_QUOTES)?>)">
                        <div class="slot-name"><?=htmlspecialchars($label)?></div>
                        <div class="slot-time"><?=substr($slot['start_time'],0,5)?> - <?=substr($slot['end_time'],0,5)?></div>
                        <div class="slot-actions">
                            <button class="slot-btn edit" onclick="event.stopPropagation(); editSlot(<?=htmlspecialchars(json_encode($slot), ENT_QUOTES)?>)" title="Редактировать">✏️</button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить этот слот?')">
                                <input type="hidden" name="slot_id" value="<?=$slot['id']?>">
                                <button type="submit" name="delete_slot" class="slot-btn delete" title="Удалить">🗑</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <!-- Нижние подписи времени -->
                <div class="timeline-labels">
                    <?php for($h=$start_hour; $h<$end_hour; $h++): ?>
                        <div class="label-hour"><?=sprintf('%02d:00', $h)?></div>
                    <?php endfor; ?>
                </div>
                <div class="timeline-hint">
                    Кликните по свободному месту на шкале, чтобы добавить слот
                </div>
            <?php endif; ?>
        </div>
        
        <div class="add-slot-form" id="slotForm">
            <h3 style="margin: 0 0 20px;" id="formTitle">Добавить временной слот</h3>
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

                <!-- Карточки услуг вместо select -->
                <!-- Карточки услуг + карточка перерыва -->
<div class="form-group">
    <label>Услуга или перерыв:</label>
    <div class="services-grid" id="servicesGrid">
        <?php foreach($services as $srv): ?>
            <div class="service-item" data-service-id="<?=$srv['id']?>" data-is-break="0" onclick="selectService(<?=$srv['id']?>, 0)">
                <div class="service-name"><?=htmlspecialchars($srv['name'])?></div>
                <div class="service-details">
                    <span class="service-duration"><?=$srv['duration_minutes']?> мин</span>
                    <span class="service-price"><?=number_format($srv['price'], 0, ',', ' ')?> BUN</span>
                </div>
                <div class="service-badge">Услуга</div>
            </div>
        <?php endforeach; ?>
        <!-- Карточка перерыва -->
        <div class="service-item break-item" data-is-break="1" onclick="selectBreak()">
            <div class="service-name">Перерыв</div>
            <div class="service-details">
                <span class="service-duration">Без услуги</span>
            </div>
            <div class="service-badge">Перерыв</div>
        </div>
    </div>
    <input type="hidden" name="service_id" id="service_id" value="">
    <input type="hidden" name="is_break" id="is_break" value="0">
</div>      
                <div class="form-actions">
                    <button type="submit" name="add_slot" id="submitBtn" class="btn-save">Сохранить слот</button>
                    <button type="button" class="btn-clear" onclick="resetForm()">Очистить</button>
                </div>
            </form>
        </div>

    <?php elseif ($active_tab === 'description'): ?>
        <div class="description-card">
            <h3>О себе</h3>
            <form method="POST">
                <textarea name="description" placeholder="Расскажите пациентам о своём опыте, специализации, образовании, подходе к лечению..."><?=htmlspecialchars($doctor_info['description'] ?? '')?></textarea>
                <div style="margin-top: 20px; text-align: right;">
                    <button type="submit" name="save_description" class="btn-save">Сохранить описание</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</main>

<script>
// Функции для управления карточками услуг
function selectService(serviceId, isBreak) {
    // Убираем выделение со всех
    document.querySelectorAll('.service-item').forEach(item => {
        item.classList.remove('selected');
    });
    // Выделяем выбранную
    const selectedItem = document.querySelector(`.service-item[data-service-id="${serviceId}"]`);
    if (selectedItem) selectedItem.classList.add('selected');
    
    // Устанавливаем значения
    document.getElementById('service_id').value = serviceId;
    document.getElementById('is_break').value = isBreak;
}

// Выбор перерыва
function selectBreak() {
    // Убираем выделение со всех
    document.querySelectorAll('.service-item').forEach(item => {
        item.classList.remove('selected');
    });
    // Выделяем карточку перерыва
    const breakItem = document.querySelector('.break-item');
    if (breakItem) breakItem.classList.add('selected');
    
    // Устанавливаем значения
    document.getElementById('service_id').value = '';
    document.getElementById('is_break').value = '1';
}

// Клик по шкале для быстрого добавления
function handleTimelineClick(event) {
    const timeline = document.getElementById('timeline');
    if (!timeline) return;
    
    const rect = timeline.getBoundingClientRect();
    const clickX = event.clientX - rect.left;
    const clickPercent = (clickX / rect.width) * 100;
    const totalMinutes = <?=$total_minutes?>;
    const clickMinute = (clickPercent / 100) * totalMinutes;
    const startMinute = Math.floor(clickMinute / 30) * 30; // Округляем до 30 минут
    const endMinute = startMinute + 60; // По умолчанию 1 час
    
    const startHour = <?=$start_hour?> + Math.floor(startMinute / 60);
    const startMin = startMinute % 60;
    const endHour = <?=$start_hour?> + Math.floor(endMinute / 60);
    const endMin = endMinute % 60;
    
    document.getElementById('start_time').value = `${String(startHour).padStart(2,'0')}:${String(startMin).padStart(2,'0')}`;
    document.getElementById('end_time').value = `${String(endHour).padStart(2,'0')}:${String(endMin).padStart(2,'0')}`;
    
    scrollToForm();
}

function scrollToForm() {
    document.getElementById('slotForm').scrollIntoView({behavior: 'smooth'});
}

// Сброс формы
function resetForm() {
    document.getElementById('slotFormInner').reset();
    document.getElementById('edit_id').value = '';
    document.getElementById('service_id').value = '';
    document.getElementById('is_break').value = '0';
    // Снимаем выделение со всех карточек
    document.querySelectorAll('.service-item').forEach(item => item.classList.remove('selected'));
    // Меняем кнопку обратно на добавление
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.name = 'add_slot';
    submitBtn.textContent = 'Сохранить слот';
    document.getElementById('formTitle').textContent = 'Добавить временной слот';
}

function editSlot(slot) {
    document.getElementById('edit_id').value = slot.id;
    document.getElementById('form_day').value = slot.day_of_week;
    document.getElementById('start_time').value = slot.start_time;
    document.getElementById('end_time').value = slot.end_time;
    
    // Снимаем выделение со всех
    document.querySelectorAll('.service-item').forEach(item => item.classList.remove('selected'));
    
    if (slot.is_break == 1) {
        // Перерыв
        document.getElementById('service_id').value = '';
        document.getElementById('is_break').value = '1';
        const breakItem = document.querySelector('.break-item');
        if (breakItem) breakItem.classList.add('selected');
    } else {
        // Обычная услуга
        document.getElementById('service_id').value = slot.service_id || '';
        document.getElementById('is_break').value = '0';
        if (slot.service_id) {
            const serviceItem = document.querySelector(`.service-item[data-service-id="${slot.service_id}"]`);
            if (serviceItem) serviceItem.classList.add('selected');
        }
    }
    
    // Меняем кнопку на "Обновить"
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.name = 'edit_slot';
    submitBtn.textContent = 'Обновить слот';
    document.getElementById('formTitle').textContent = 'Редактирование слота';
    
    scrollToForm();
}
</script>
<?php require 'toast.php'; ?>
</body>
</html>