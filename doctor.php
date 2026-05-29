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
    } elseif (isset($_POST['delete_slot'])) {
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

$days = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
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
    <link rel="stylesheet" href="doctor.css">
</head>

<body>
    <header class="site-header">
        <div class="container header-flex">
            <div class="header-left">
                <a href="dashboard.php" class="btn-back">← В кабинет</a>
                <a href="dashboard.php" class="logo">Medprofi <span class="badge">Врач</span></a>
            </div>
            <div style="display:flex; gap:10px; align-items:center;">
                <a href="settings.php" class="btn btn-outline">Настройки</a>
                <a href="logout.php" class="btn btn-outline">Выйти</a>
            </div>
        </div>
    </header>

    <main class="schedule-container">
        <!-- Вкладки -->
        <div class="doctor-tabs">
            <a href="?tab=schedule" class="doctor-tab <?= $active_tab === 'schedule' ? 'active' : '' ?>">Расписание</a>
            <a href="?tab=description" class="doctor-tab <?= $active_tab === 'description' ? 'active' : '' ?>">Описание</a>
        </div>

        <?php if ($active_tab === 'schedule'): ?>
            <h1 style="margin-bottom: 20px;">Моё расписание</h1>

            <div class="day-tabs">
                <?php for ($d = 1; $d <= 7; $d++): ?>
                    <a href="?tab=schedule&day=<?= $d ?>" class="day-tab <?= ($d == $active_day ? 'active' : '') ?>"><?= $days[$d] ?></a>
                <?php endfor; ?>
            </div>

            <div class="timeline-wrapper">
                <?php if (empty($day_slots)): ?>
                    <div class="empty-timeline">
                        <div class="icon"></div>
                        <p>Нет записей на этот день</p>
                    </div>
                <?php else: ?>
                    <div class="timeline" id="timeline" onclick="handleTimelineClick(event)">
                        <div class="timeline-grid">
                            <?php for ($h = $start_hour; $h < $end_hour; $h++): ?>
                                <div class="grid-hour" data-hour="<?= sprintf('%02d:00', $h) ?>"></div>
                            <?php endfor; ?>
                        </div>
                        <?php foreach ($day_slots as $slot):
                            $start_min = (strtotime($slot['start_time']) - strtotime(sprintf('%02d:00', $start_hour))) / 60;
                            $end_min = (strtotime($slot['end_time']) - strtotime(sprintf('%02d:00', $start_hour))) / 60;
                            $left_percent = ($start_min / $total_minutes) * 100;
                            $width_percent = (($end_min - $start_min) / $total_minutes) * 100;
                            $label = $slot['is_break'] ? 'Перерыв' : ($slot['service_name'] ?? 'Слот');
                        ?>
                            <div class="slot-block <?= $slot['is_break'] ? 'break' : 'service' ?>"
                                style="left: <?= $left_percent ?>%; width: <?= $width_percent ?>%;"
                                onclick="event.stopPropagation(); editSlot(<?= htmlspecialchars(json_encode($slot), ENT_QUOTES) ?>)">
                                <div class="slot-name"><?= htmlspecialchars($label) ?></div>
                                <div class="slot-time"><?= substr($slot['start_time'], 0, 5) ?> - <?= substr($slot['end_time'], 0, 5) ?></div>
                                <div class="slot-actions">
                                    <button class="slot-btn edit" onclick="event.stopPropagation(); editSlot(<?= htmlspecialchars(json_encode($slot), ENT_QUOTES) ?>)" title="Редактировать">✏️</button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить этот слот?')">
                                        <input type="hidden" name="slot_id" value="<?= $slot['id'] ?>">
                                        <button type="submit" name="delete_slot" class="slot-btn delete" title="Удалить">🗑</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <!-- Нижние подписи времени -->
                    <div class="timeline-labels">
                        <?php for ($h = $start_hour; $h < $end_hour; $h++): ?>
                            <div class="label-hour"><?= sprintf('%02d:00', $h) ?></div>
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
                    <input type="hidden" name="day_of_week" id="form_day" value="<?= $active_day ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label>Начало:</label>
                            <input type="time" name="start_time" id="start_time" required min="<?= sprintf('%02d:00', $start_hour) ?>" max="<?= sprintf('%02d:00', $end_hour) ?>">
                        </div>
                        <div class="form-group">
                            <label>Окончание:</label>
                            <input type="time" name="end_time" id="end_time" required min="<?= sprintf('%02d:00', $start_hour) ?>" max="<?= sprintf('%02d:00', $end_hour) ?>">
                        </div>
                    </div>

                    <!-- Карточки услуг вместо select -->
                    <!-- Карточки услуг + карточка перерыва -->
                    <div class="form-group">
                        <label>Услуга или перерыв:</label>
                        <div class="services-grid" id="servicesGrid">
                            <?php foreach ($services as $srv): ?>
                                <div class="service-item" data-service-id="<?= $srv['id'] ?>" data-is-break="0" onclick="selectService(<?= $srv['id'] ?>, 0)">
                                    <div class="service-name"><?= htmlspecialchars($srv['name']) ?></div>
                                    <div class="service-details">
                                        <span class="service-duration"><?= $srv['duration_minutes'] ?> мин</span>
                                        <span class="service-price"><?= number_format($srv['price'], 0, ',', ' ') ?> BUN</span>
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
                    <textarea name="description" placeholder="Расскажите пациентам о своём опыте, специализации, образовании, подходе к лечению..."><?= htmlspecialchars($doctor_info['description'] ?? '') ?></textarea>
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
            const totalMinutes = <?= $total_minutes ?>;
            const clickMinute = (clickPercent / 100) * totalMinutes;
            const startMinute = Math.floor(clickMinute / 30) * 30; // Округляем до 30 минут
            const endMinute = startMinute + 60; // По умолчанию 1 час

            const startHour = <?= $start_hour ?> + Math.floor(startMinute / 60);
            const startMin = startMinute % 60;
            const endHour = <?= $start_hour ?> + Math.floor(endMinute / 60);
            const endMin = endMinute % 60;

            document.getElementById('start_time').value = `${String(startHour).padStart(2,'0')}:${String(startMin).padStart(2,'0')}`;
            document.getElementById('end_time').value = `${String(endHour).padStart(2,'0')}:${String(endMin).padStart(2,'0')}`;

            scrollToForm();
        }

        function scrollToForm() {
            document.getElementById('slotForm').scrollIntoView({
                behavior: 'smooth'
            });
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