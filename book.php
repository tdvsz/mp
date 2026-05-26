<?php
require 'config.php';
requireAuth(['patient']);

$doctor_id = (int)($_GET['doctor_id'] ?? 0);
if (!$doctor_id) redirect('doctors.php');

// 1. Врач
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'doctor'");
$stmt->execute([$doctor_id]);
$doctor = $stmt->fetch();
if (!$doctor) redirect('doctors.php');

// 2. Услуги (общие + профильные для этого врача)
$stmt = $pdo->prepare("
    SELECT s.* FROM services s 
    LEFT JOIN specialties sp ON s.specialty_id = sp.id
    WHERE s.specialty_id IS NULL OR sp.id = ?
    ORDER BY s.specialty_id DESC, s.name
");
$stmt->execute([$doctor['specialty_id']]);
$services = $stmt->fetchAll();

// 3. Параметры
$service_id = (int)($_GET['service_id'] ?? 0);
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_time = $_GET['time'] ?? '';

$selected_service = null;
if ($service_id) {
    $selected_service = $pdo->query("SELECT * FROM services WHERE id = $service_id")->fetch();
}

function get_free_slots($pdo, $doc_id, $date, $service_id) {
    if (!$service_id) return 0;
    
    $stmt = $pdo->prepare("SELECT duration_minutes FROM services WHERE id = ?");
    $stmt->execute([$service_id]);
    $duration = $stmt->fetchColumn();
    if (!$duration) return 0;

    $day_of_week = (int)date('N', strtotime($date));
    
    // Получаем слоты для этого дня, где доступна эта услуга
    $slots = $pdo->prepare("
        SELECT start_time, end_time, service_id, is_break 
        FROM doctor_schedule_slots 
        WHERE doctor_id = ? AND day_of_week = ? 
        AND (service_id = ? OR service_id IS NULL)
        AND is_break = 0
        ORDER BY start_time
    ");
    $slots->execute([$doc_id, $day_of_week, $service_id]);
    $schedule_slots = $slots->fetchAll();
    
    if (empty($schedule_slots)) return 0;

    $count = 0;
    foreach ($schedule_slots as $slot) {
        $start = new DateTime($slot['start_time']);
        $end = new DateTime($slot['end_time']);
        $interval = new DateInterval("PT{$duration}M");
        $current = clone $start;

        while ($current < $end) {
            $next = clone $current; 
            $next->add($interval);
            if ($next > $end) break;
            
            $slot_time = $current->format('H:i');
            $busy = $pdo->prepare("SELECT id FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND start_time = ? AND status = 'booked'");
            $busy->execute([$doc_id, $date, $slot_time]);
            if (!$busy->fetch()) $count++;
            
            $current->add($interval);
        }
    }
    return $count;
}

// 5. Доступные слоты
$available_slots = [];
if ($selected_date && $selected_service) {
    $dow = (int)date('N', strtotime($selected_date));
    $dur = $selected_service['duration_minutes'];
    
    // Получаем все слоты для этого дня
    $sch = $pdo->prepare("
        SELECT start_time, end_time, service_id, is_break 
        FROM doctor_schedule_slots 
        WHERE doctor_id = ? AND day_of_week = ? 
        AND (service_id = ? OR service_id IS NULL)
        AND is_break = 0
        ORDER BY start_time
    ");
    $sch->execute([$doctor_id, $dow, $service_id]);
    $schedule_slots = $sch->fetchAll();

    foreach ($schedule_slots as $slot) {
        $s = new DateTime($slot['start_time']);
        $e = new DateTime($slot['end_time']);
        $int = new DateInterval("PT{$dur}M");
        $cur = clone $s;

        while ($cur < $e) {
            $next = clone $cur; 
            $next->add($int);
            if ($next > $e) break;
            
            $t = $cur->format('H:i');
            $b = $pdo->prepare("SELECT id FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND start_time = ? AND status = 'booked'");
            $b->execute([$doctor_id, $selected_date, $t]);
            if (!$b->fetch()) $available_slots[] = $t;
            $cur->add($int);
        }
    }
}

// 6. Обработка бронирования (POST)
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $srv = (int)$_POST['service_id'];
    $d = $_POST['date'];
    $time = $_POST['time'];
    
    $srvData = $pdo->prepare("SELECT price FROM services WHERE id = ?");
    $srvData->execute([$srv]);
    $price = $srvData->fetchColumn() ?: 0;

    $check = $pdo->prepare("SELECT id FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND start_time = ? AND status = 'booked'");
    $check->execute([$doctor_id, $d, "$time:00"]);
    
    if ($check->fetch()) {
        $msg = '❌ Время только что заняли. Выберите другое.';
    } else {
        $pdo->prepare("INSERT INTO appointments (patient_id, doctor_id, service_id, appointment_date, start_time, price) VALUES (?,?,?,?,?,?)")
            ->execute([$_SESSION['user_id'], $doctor_id, $srv, $d, "$time:00", $price]);
        redirect("book.php?doctor_id=$doctor_id&service_id=$srv&date=".urlencode($d)."&msg=ok");
    }
}
if (isset($_GET['msg']) && $_GET['msg'] === 'ok') $msg = 'Запись успешно подтверждена!';

// 7. Календарь
$months = [];
$current_month = date('Y-m');
for ($i = 0; $i < 2; $i++) {
    $date_obj = new DateTime($current_month);
    $date_obj->add(new DateInterval("P{$i}M"));
    $months[] = $date_obj->format('Y-m');
}

function build_calendar($pdo, $doc_id, $srv_id, $month_str, $active_date) {
    $ts = strtotime($month_str);
    $year = date('Y', $ts);
    $month = date('m', $ts);
    $months_ru = [
        1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
        5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
        9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь'
    ];
    $month_name = $months_ru[(int)date('n', $ts)];
    $first_day = new DateTime("$year-$month-01");
    $days_in_month = date('t', $ts);
    $start_week = (int)$first_day->format('N');
    
    $html = "<div class='calendar-month'>";
    $html .= "<h3>" . ucfirst($month_name) . " $year</h3>";
    $html .= "<div class='calendar-grid'>";
    $headers = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
    foreach ($headers as $h) $html .= "<div class='cal-head'>$h</div>";
    
    for ($i = 1; $i < $start_week; $i++) $html .= "<div class='cal-day empty'></div>";
    
    for ($d = 1; $d <= $days_in_month; $d++) {
        $date_str = "$year-$month-" . str_pad($d, 2, '0', STR_PAD_LEFT);
        $today = date('Y-m-d');
        $slots = get_free_slots($pdo, $doc_id, $date_str, $srv_id);
        $is_active = ($date_str === $active_date) ? 'active' : '';
        $is_past = ($date_str < $today) ? 'past' : '';
        $is_weekend = (date('N', strtotime($date_str)) > 5) ? 'weekend' : '';
        
        $html .= "<div class='cal-day $is_active $is_past $is_weekend' data-date='$date_str' onclick='selectDate(this)'>";
        $html .= "<span class='day-num'>$d</span>";
        if ($slots > 0 && $date_str >= $today) {
            $html .= "<span class='slots-count'>$slots талонов</span>";
        }
        $html .= "</div>";
    }
    $html .= "</div></div>";
    return $html;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Запись | Medprofi</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="site-header">
    <div class="container header-flex">
        <a href="doctors.php" class="logo">← Врачи</a>
        <a href="logout.php" class="btn btn-outline">Выйти</a>
    </div>
</header>

<main class="container">
    <div class="booking-layout">
        <aside class="doctor-sidebar">
    <div class="doctor-profile-card">
        <img src="<?=htmlspecialchars($doctor['photo'])?>" onerror="this.src='https://placehold.co/150x200/e2e8f0/1e293b?text=Нет+фото'">
        <h2><?=htmlspecialchars($doctor['full_name'])?></h2>
        <span class="badge-spec"><?=htmlspecialchars($doctor['specialty'] ?? '')?></span>
        <p class="exp-text">Стаж: <?=$doctor['experience_years']?> лет</p>
        
        <?php if(!empty($doctor['description'])): ?>
            <div class="doc-divider"></div>
            <p class="doctor-bio"><?=nl2br(htmlspecialchars($doctor['description']))?></p>
        <?php endif; ?>
    </div>
</aside>

        <section class="booking-main">
            <!-- ФОРМА ФИЛЬТРОВ (GET) -->
            <form method="GET" class="card" id="filterForm">
                <input type="hidden" name="doctor_id" value="<?=$doctor_id?>">
                <input type="hidden" name="service_id" id="service_id" value="<?=$service_id?>">
                <input type="hidden" name="date" id="date" value="<?=$selected_date?>">
                
                <div class="step-block">
                    <label>Выберите услугу:</label>
                    <div class="services-grid">
                        <?php foreach($services as $srv): 
                            $is_specific = $srv['specialty_id'] !== null;
                            $is_selected = ($srv['id'] == $service_id);
                        ?>
                            <div class="service-item <?=($is_selected ? 'selected' : '')?>" 
                                 onclick="selectService(<?=$srv['id']?>)"
                                 data-service-id="<?=$srv['id']?>">
                                <div class="service-name"><?=htmlspecialchars($srv['name'])?></div>
                                <div class="service-details">
                                    <span class="service-duration"><?=$srv['duration_minutes']?> мин</span>
                                    <span class="service-price"><?=number_format($srv['price'], 0, ',', ' ')?> BUN</span>
                                </div>
                                <?php if($is_specific): ?>
                                    <span class="service-badge">Профильная</span>
                                <?php else: ?>
                                    <span class="service-badge">Общая</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if($service_id): ?>
                <div class="step-block">
                    <label>Выберите дату:</label>
                    <div class="calendar-container">
                        <?php foreach($months as $m): echo build_calendar($pdo, $doctor_id, $service_id, $m, $selected_date); endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </form>

            <!-- ФОРМА БРОНИРОВАНИЯ (POST) -->
            <?php if($service_id && $selected_date && !empty($available_slots)): ?>
            <form method="POST" class="card booking-confirm-form">
                <input type="hidden" name="doctor_id" value="<?=$doctor_id?>">
                <input type="hidden" name="service_id" value="<?=$service_id?>">
                <input type="hidden" name="date" value="<?=htmlspecialchars($selected_date)?>">
                
                <div class="step-block">
                    <label>Выберите время:</label>
                    <div class="time-grid">
                        <?php foreach($available_slots as $t): ?>
                        <div class="time-item">
                            <input type="radio" name="time" value="<?=$t?>" id="t_<?=$t?>" required>
                            <label for="t_<?=$t?>"><?=$t?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="btn btn-primary btn-large" style="width:100%; margin-top:20px;">Подтвердить запись</button>
                </div>
            </form>
            <?php elseif($service_id && $selected_date && empty($available_slots)): ?>
                <div class="card info-box" style="margin-top:20px;">Нет свободных талонов на эту дату.</div>
            <?php endif; ?>
        </section>
    </div>
</main>

<script>
function selectService(serviceId) {
    // Убираем выделение со всех услуг
    document.querySelectorAll('.service-item').forEach(item => {
        item.classList.remove('selected');
    });
    
    // Добавляем выделение выбранной услуге
    const selectedItem = document.querySelector(`.service-item[data-service-id="${serviceId}"]`);
    if (selectedItem) {
        selectedItem.classList.add('selected');
    }
    
    // Обновляем hidden input и отправляем форму
    document.getElementById('service_id').value = serviceId;
    document.getElementById('filterForm').submit();
}

function selectDate(el) {
    if (el.classList.contains('past')) return;
    const date = el.getAttribute('data-date');
    document.getElementById('date').value = date;
    document.getElementById('filterForm').submit();
}
</script>
<?php require 'toast.php'; ?>
</body>
</html>