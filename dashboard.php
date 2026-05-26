<?php
require 'config.php';
requireAuth();

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$name = htmlspecialchars($_SESSION['full_name']);

// === ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ДЛЯ РУССКИХ ДАТ ===
function ru_month($month_num) {
    $months = [
        1 => 'янв', 2 => 'фев', 3 => 'мар', 4 => 'апр',
        5 => 'май', 6 => 'июн', 7 => 'июл', 8 => 'авг',
        9 => 'сен', 10 => 'окт', 11 => 'ноя', 12 => 'дек'
    ];
    return $months[(int)$month_num] ?? '';
}

function format_date_ru($date) {
    return $date ? date('d.m.Y', strtotime($date)) : '';
}
// ===============================================

$success_msg = '';
$err_msg = '';

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['cancel_appointment'])) {
        $ap_id = (int)$_POST['ap_id'];
        $pdo->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ? AND patient_id = ? AND status = 'booked'")
            ->execute([$ap_id, $user_id]);
        $success_msg = 'Прием успешно отменен!';
    } elseif (isset($_POST['confirm_appointment'])) {
        $ap_id = (int)$_POST['ap_id'];
        $pdo->prepare("UPDATE appointments SET status = 'completed' WHERE id = ? AND doctor_id = ? AND status = 'booked'")
            ->execute([$ap_id, $user_id]);
        $success_msg = 'Прием подтвержден!';
    }
}

// ==================== ПАЦИЕНТ ====================
$patient_upcoming = [];
$patient_history = [];
$patient_history_params = [];

if ($role === 'patient') {
    // Предстоящие приемы
    $up = $pdo->prepare("SELECT a.id, a.appointment_date, a.start_time, a.status, a.price,
            d.full_name as doctor_name, d.telegram_nick, s.name as service_name, s.duration_minutes
            FROM appointments a JOIN users d ON a.doctor_id = d.id JOIN services s ON a.service_id = s.id
            WHERE a.patient_id = ? AND a.appointment_date >= CURDATE() AND a.status = 'booked'
            ORDER BY a.appointment_date ASC, a.start_time ASC");
    $up->execute([$user_id]);
    $patient_upcoming = $up->fetchAll();

    // Фильтры истории
    $hist_date = $_GET['hist_date'] ?? '';
    $hist_status = $_GET['hist_status'] ?? '';
    $hist_sort = $_GET['hist_sort'] ?? 'date_desc';
    $hist_page = max(1, (int)($_GET['hist_page'] ?? 1));
    $hist_limit = 8;
    $hist_offset = ($hist_page - 1) * $hist_limit;

    $hist_where = "WHERE a.patient_id = ? AND (a.appointment_date < CURDATE() OR a.status IN ('cancelled', 'completed'))";
    $patient_history_params = [$user_id];

    if ($hist_date) {
        $hist_where .= " AND a.appointment_date = ?";
        $patient_history_params[] = $hist_date;
    }
    if ($hist_status) {
        $hist_where .= " AND a.status = ?";
        $patient_history_params[] = $hist_status;
    }

    // Сортировка
    $hist_order = "ORDER BY a.appointment_date DESC, a.start_time DESC";
    if ($hist_sort === 'date_asc') $hist_order = "ORDER BY a.appointment_date ASC, a.start_time ASC";
    if ($hist_sort === 'price_desc') $hist_order = "ORDER BY a.price DESC";

    // Подсчет
    $hist_count = $pdo->prepare("SELECT COUNT(*) FROM appointments a $hist_where");
    $hist_count->execute($patient_history_params);
    $hist_total = $hist_count->fetchColumn();
    $hist_pages = ceil($hist_total / $hist_limit);

    // Запрос истории
    $ph = $pdo->prepare("SELECT a.id, a.appointment_date, a.start_time, a.status, a.price,
            d.full_name as doctor_name, s.name as service_name
            FROM appointments a JOIN users d ON a.doctor_id = d.id JOIN services s ON a.service_id = s.id
            $hist_where $hist_order LIMIT $hist_limit OFFSET $hist_offset");
    $ph->execute($patient_history_params);
    $patient_history = $ph->fetchAll();
}

// ==================== ВРАЧ ====================
$doctor_today = [];
$doctor_future = [];

if ($role === 'doctor') {
    // Приемы на сегодня
    $td = $pdo->prepare("SELECT a.id, a.start_time, a.status, a.price,
            p.id as patient_id, p.full_name as patient_name, p.telegram_nick,
            s.name as service_name, s.duration_minutes
            FROM appointments a JOIN users p ON a.patient_id = p.id JOIN services s ON a.service_id = s.id
            WHERE a.doctor_id = ? AND a.appointment_date = CURDATE() AND a.status = 'booked'
            ORDER BY a.start_time ASC");
    $td->execute([$user_id]);
    $doctor_today = $td->fetchAll();

    // Приемы на будущие дни
    $ft = $pdo->prepare("SELECT a.id, a.appointment_date, a.start_time, a.status, a.price,
            p.id as patient_id, p.full_name as patient_name,
            s.name as service_name
            FROM appointments a JOIN users p ON a.patient_id = p.id JOIN services s ON a.service_id = s.id
            WHERE a.doctor_id = ? AND a.appointment_date > CURDATE() AND a.status = 'booked'
            ORDER BY a.appointment_date ASC, a.start_time ASC");
    $ft->execute([$user_id]);
    $doctor_future = $ft->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Кабинет | Medprofi</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="site-header">
    <div class="container header-flex">
        <div class="logo">Medprofi <span class="badge"><?=($role === 'patient' ? 'Пациент' : ($role === 'doctor' ? 'Врач' : 'Админ'))?></span></div>
        <div style="display:flex; gap:10px; align-items:center;">
            <a href="settings.php" class="btn btn-outline">Настройки</a>
            <a href="logout.php" class="btn btn-outline">Выйти</a>
        </div>
    </div>
</header>

<main class="container">
    <?php if($success_msg): ?><div class="success"><?=htmlspecialchars($success_msg)?></div><?php endif; ?>
    <?php if($err_msg): ?><div class="err"><?=htmlspecialchars($err_msg)?></div><?php endif; ?>
    
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; flex-wrap:wrap; gap:15px;">
        <h1 style="margin:0;">Добро пожаловать, <?=$name?></h1>
        <?php if($role === 'patient'): ?>
            <a href="doctors.php" class="btn btn-primary">Записаться к врачу</a>
        <?php elseif($role === 'doctor'): ?>
            <a href="doctor.php" class="btn btn-primary">Настроить расписание</a>
        <?php elseif($role === 'admin'): ?>
            <a href="admin.php" class="btn btn-primary">Панель администратора</a>
        <?php endif; ?>
    </div>

    <!-- ================= ПАЦИЕНТ ================= -->
    <?php if($role === 'patient'): ?>
    <h2 style="margin:30px 0 15px; color:var(--primary);">Предстоящие приемы</h2>
    <?php if(empty($patient_upcoming)): ?>
        <div class="info-box">У вас нет предстоящих записей. <a href="doctors.php" style="color:var(--primary); font-weight:600;">Записаться →</a></div>
    <?php else: ?>
        <div class="appointments-list">
            <?php foreach($patient_upcoming as $ap): 
                $ap_ts = strtotime($ap['appointment_date']);
                $ap_day = date('d', $ap_ts);
                $ap_month_ru = ru_month(date('n', $ap_ts));
            ?>
                <div class="appointment-card upcoming">
                    <div class="ap-date">
                        <div class="ap-day"><?=$ap_day?></div>
                        <div class="ap-month"><?=$ap_month_ru?></div>
                    </div>
                    <div class="ap-info">
                        <h3><?=htmlspecialchars($ap['service_name'])?></h3>
                        <p class="ap-doctor"><?=htmlspecialchars($ap['doctor_name'])?></p>
                        <p class="ap-time"><?=substr($ap['start_time'],0,5)?> • <?=$ap['duration_minutes']?> мин</p>
                        <?php if(!empty($ap['telegram_nick'])): ?>
                            <p class="ap-tg"> Telegram: <a href="https://t.me/<?=htmlspecialchars($ap['telegram_nick'])?>" target="_blank">@<?=htmlspecialchars($ap['telegram_nick'])?></a></p>
                        <?php endif; ?>
                    </div>
                    <div class="ap-price"><?=number_format($ap['price'], 0, ',', ' ')?> BUN</div>
                    <div class="ap-actions">
                        <form method="POST" onsubmit="return confirm('Отменить эту запись?')">
                            <input type="hidden" name="ap_id" value="<?=$ap['id']?>">
                            <button type="submit" name="cancel_appointment" class="btn-cancel">✖ Отменить</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- ИСТОРИЯ С ФИЛЬТРАМИ -->
    <div class="history-controls">
        <h2 style="margin:0; color:#64748b;">История посещений</h2>
        <form method="GET" class="history-filters-row">
            <input type="hidden" name="hist_page" value="1">
            <div class="filter-group">
                <label>Дата:</label>
                <input type="date" name="hist_date" value="<?=htmlspecialchars($hist_date)?>">
            </div>
            <div class="filter-group">
                <label>Статус:</label>
                <select name="hist_status">
                    <option value="">Все</option>
                    <option value="completed" <?=($hist_status=='completed'?'selected':'')?>>Завершен</option>
                    <option value="cancelled" <?=($hist_status=='cancelled'?'selected':'')?>>Отменен</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Сортировка:</label>
                <select name="hist_sort" onchange="this.form.submit()">
                    <option value="date_desc" <?=($hist_sort=='date_desc'?'selected':'')?>>Сначала новые</option>
                    <option value="date_asc" <?=($hist_sort=='date_asc'?'selected':'')?>>Сначала старые</option>
                    <option value="price_desc" <?=($hist_sort=='price_desc'?'selected':'')?>>По цене ↓</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="height:42px;">Применить</button>
            <?php if($hist_date || $hist_status): ?>
                <a href="#" class="btn btn-outline" onclick="this.href=this.href.split('?')[0]; return true;">Сбросить</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if(empty($patient_history)): ?>
        <div class="info-box">История пуста.</div>
    <?php else: ?>
        <table class="data-table">
            <tr><th>Дата</th><th>Время</th><th>Услуга</th><th>Врач</th><th>Цена</th><th>Статус</th></tr>
            <?php foreach($patient_history as $ap): ?>
            <tr>
                <td><?=format_date_ru($ap['appointment_date'])?></td>
                <td><?=substr($ap['start_time'],0,5)?></td>
                <td><?=htmlspecialchars($ap['service_name'])?></td>
                <td><?=htmlspecialchars($ap['doctor_name'])?></td>
                <td><?=number_format($ap['price'], 0, ',', ' ')?> BUN</td>
                <td><span class="status-badge <?=$ap['status']?>"><?=($ap['status']=='completed'?'Завершен':($ap['status']=='cancelled'?'Отменен':'Записан'))?></span></td>
            </tr>
            <?php endforeach; ?>
        </table>

        <!-- Пагинация истории -->
        <?php if($hist_pages > 1): ?>
        <div class="pagination" style="margin-top:15px;">
            <?php if($hist_page > 1): ?>
                <a href="?hist_page=<?= $hist_page-1 ?>&hist_date=<?=urlencode($hist_date)?>&hist_status=<?=urlencode($hist_status)?>&hist_sort=<?=urlencode($hist_sort)?>" class="pg-btn">←</a>
            <?php endif; ?>
            <?php for($i=1; $i<=$hist_pages; $i++): ?>
                <a href="?hist_page=<?= $i ?>&hist_date=<?=urlencode($hist_date)?>&hist_status=<?=urlencode($hist_status)?>&hist_sort=<?=urlencode($hist_sort)?>" class="pg-btn <?=($i==$hist_page ? 'active' : '')?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if($hist_page < $hist_pages): ?>
                <a href="?hist_page=<?= $hist_page+1 ?>&hist_date=<?=urlencode($hist_date)?>&hist_status=<?=urlencode($hist_status)?>&hist_sort=<?=urlencode($hist_sort)?>" class="pg-btn">→</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- ================= ВРАЧ ================= -->
    <?php elseif($role === 'doctor'): ?>
    
    <h2 style="margin:0 0 15px; color:#10b981;">Приемы на сегодня</h2>
    <?php if(empty($doctor_today)): ?>
        <div class="info-box">На сегодня записей нет.</div>
    <?php else: ?>
        <div class="appointments-list">
            <?php foreach($doctor_today as $ap): ?>
                <div class="appointment-card today">
                    <div class="ap-time-block">
                        <strong><?=substr($ap['start_time'],0,5)?></strong>
                        <span><?=$ap['duration_minutes']?> мин</span>
                    </div>
                    <div class="ap-info">
                        <h3><?=htmlspecialchars($ap['service_name'])?></h3>
                        <p><?=htmlspecialchars($ap['patient_name'])?></p>
                        <?php if(!empty($ap['telegram_nick'])): ?>
                            <p><a href="https://t.me/<?=htmlspecialchars($ap['telegram_nick'])?>" target="_blank">@<?=htmlspecialchars($ap['telegram_nick'])?></a></p>
                        <?php endif; ?>
                    </div>
                    <div class="ap-actions">
                        <a href="patient_history.php?patient_id=<?=$ap['patient_id']?>" class="btn-history">История</a>
                        <form method="POST">
                            <input type="hidden" name="ap_id" value="<?=$ap['id']?>">
                            <button type="submit" name="confirm_appointment" class="btn-confirm">Подтвердить</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2 style="margin:30px 0 15px; color:#3b82f6;">Предстоящие приемы</h2>
    <?php if(empty($doctor_future)): ?>
        <div class="info-box">Нет предстоящих записей.</div>
    <?php else: ?>
        <table class="data-table">
            <tr><th>Дата</th><th>Время</th><th>Пациент</th><th>Услуга</th><th>Действия</th></tr>
            <?php foreach($doctor_future as $ap): ?>
            <tr>
                <td><?=format_date_ru($ap['appointment_date'])?></td>
                <td><?=substr($ap['start_time'],0,5)?></td>
                <td><?=htmlspecialchars($ap['patient_name'])?></td>
                <td><?=htmlspecialchars($ap['service_name'])?></td>
                <td><a href="patient_history.php?patient_id=<?=$ap['patient_id']?>" class="btn-history">История</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <!-- СПИСОК ПАЦИЕНТОВ ВРАЧА -->
    <div style="margin-top:40px; padding-top:30px; border-top:2px solid var(--border);">
        <h2 style="margin:0 0 20px; color:#6366f1;">Мои пациенты</h2>
        <?php
        // Параметры поиска пациентов
        $pat_search = trim($_GET['pat_search'] ?? '');
        $pat_page = max(1, (int)($_GET['pat_page'] ?? 1));
        $pat_limit = 10;
        $pat_offset = ($pat_page - 1) * $pat_limit;

        $pat_where = "SELECT DISTINCT p.id, p.full_name, p.telegram_nick, p.photo,
                COUNT(a.id) as visits_count, MAX(a.appointment_date) as last_visit
                FROM appointments a
                JOIN users p ON a.patient_id = p.id
                WHERE a.doctor_id = ?";
        $pat_params = [$user_id];

        if ($pat_search) {
            $pat_where .= " AND p.full_name LIKE ?";
            $pat_params[] = "%$pat_search%";
        }

        $pat_where .= " GROUP BY p.id ORDER BY last_visit DESC";

        // Подсчет
        $pat_count_sql = "SELECT COUNT(DISTINCT p.id) FROM appointments a JOIN users p ON a.patient_id = p.id WHERE a.doctor_id = ?";
        $pat_count_params = [$user_id];
        if ($pat_search) {
            $pat_count_sql .= " AND p.full_name LIKE ?";
            $pat_count_params[] = "%$pat_search%";
        }
        $pat_total = $pdo->prepare($pat_count_sql)->execute($pat_count_params) ? $pdo->query("SELECT COUNT(DISTINCT p.id) FROM appointments a JOIN users p ON a.patient_id = p.id WHERE a.doctor_id = " . $user_id . ($pat_search ? " AND p.full_name LIKE '%$pat_search%'" : ""))->fetchColumn() : 0;
        
        // Исправленный подсчет
        $count_stmt = $pdo->prepare($pat_count_sql);
        $count_stmt->execute($pat_count_params);
        $pat_total = $count_stmt->fetchColumn();
        $pat_pages = ceil($pat_total / $pat_limit);

        // Запрос пациентов
        $pat_stmt = $pdo->prepare("$pat_where LIMIT $pat_limit OFFSET $pat_offset");
        $pat_stmt->execute($pat_params);
        $patients_list = $pat_stmt->fetchAll();
        ?>

        <form method="GET" style="margin-bottom:20px; display:flex; gap:10px;">
            <input type="text" name="pat_search" placeholder="Поиск по ФИО пациента..." value="<?=htmlspecialchars($pat_search)?>" style="flex:1; padding:10px; border:1px solid var(--border); border-radius:8px;">
            <button type="submit" class="btn btn-primary">Найти</button>
            <?php if($pat_search): ?>
                <a href="dashboard.php" class="btn btn-outline">Сбросить</a>
            <?php endif; ?>
        </form>

        <?php if(empty($patients_list)): ?>
            <div class="info-box">Пациентов не найдено.</div>
        <?php else: ?>
            <div class="patients-grid">
                <?php foreach($patients_list as $pat): ?>
                <div class="patient-card-mini">
                    <img src="<?=htmlspecialchars($pat['photo'] ?? 'https://placehold.co/150x200/e2e8f0/1e293b?text=П')?>" 
                         onerror="this.src='https://placehold.co/150x200/e2e8f0/1e293b?text=П'">
                    <div class="pat-info">
                        <h4><?=htmlspecialchars($pat['full_name'])?></h4>
                        <?php if(!empty($pat['telegram_nick'])): ?>
                            <p class="tg-mini"><a href="https://t.me/<?=htmlspecialchars($pat['telegram_nick'])?>" target="_blank">@<?=htmlspecialchars($pat['telegram_nick'])?></a></p>
                        <?php endif; ?>
                        <p class="pat-stats">Визитов: <?=$pat['visits_count']?> • Последний: <?=format_date_ru($pat['last_visit'])?></p>
                    </div>
                    <a href="patient_history.php?patient_id=<?=$pat['id']?>" class="btn-history" style="margin-top:auto;">История</a>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Пагинация пациентов -->
            <?php if($pat_pages > 1): ?>
            <div class="pagination" style="margin-top:20px;">
                <?php if($pat_page > 1): ?>
                    <a href="?pat_page=<?= $pat_page-1 ?>&pat_search=<?=urlencode($pat_search)?>" class="pg-btn">←</a>
                <?php endif; ?>
                <?php for($i=1; $i<=$pat_pages; $i++): ?>
                    <a href="?pat_page=<?= $i ?>&pat_search=<?=urlencode($pat_search)?>" class="pg-btn <?=($i==$pat_page ? 'active' : '')?>"><?= $i ?></a>
                <?php endfor; ?>
                <?php if($pat_page < $pat_pages): ?>
                    <a href="?pat_page=<?= $pat_page+1 ?>&pat_search=<?=urlencode($pat_search)?>" class="pg-btn">→</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php elseif($role === 'admin'): ?>
        <div class="info-box">Перейдите в <a href="admin.php" style="color:var(--primary); font-weight:600;">панель администратора</a> для управления системой.</div>
    <?php endif; ?>
</main>

<style>
.history-controls { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; margin:30px 0 15px; padding:15px; background:#fff; border-radius:10px; border:1px solid var(--border); }
.history-filters-row { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
.filter-group { display:flex; flex-direction:column; gap:4px; }
.filter-group label { font-size:0.8rem; font-weight:600; color:#64748b; }
.filter-group input, .filter-group select { padding:8px; border:1px solid var(--border); border-radius:6px; font-size:0.9rem; }

.patients-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:15px; }
.patient-card-mini { display:flex; gap:15px; padding:15px; background:#fff; border:1px solid var(--border); border-radius:10px; align-items:center; transition:0.2s; }
.patient-card-mini:hover { box-shadow:0 4px 12px rgba(0,0,0,0.08); transform:translateY(-2px); }
.patient-card-mini img { width:60px; height:60px; border-radius:50%; object-fit:cover; }
.pat-info h4 { margin:0 0 4px; font-size:1rem; color:#0f172a; }
.tg-mini { margin:0; font-size:0.85rem; }
.tg-mini a { color:var(--primary); text-decoration:none; font-weight:500; }
.pat-stats { margin:4px 0 0; font-size:0.8rem; color:#64748b; }

@media(max-width:700px) {
    .history-controls { flex-direction:column; align-items:stretch; }
    .history-filters-row { flex-direction:column; }
    .patients-grid { grid-template-columns:1fr; }
    .appointment-card { flex-direction:column; align-items:flex-start; gap:15px; }
    .ap-actions { width:100%; display:flex; gap:10px; }
    .btn-confirm, .btn-history, .btn-cancel { flex:1; text-align:center; }
}
</style>
<?php require 'toast.php'; ?>
</body>
</html>