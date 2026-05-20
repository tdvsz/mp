<?php
require 'config.php';
requireAuth(['doctor']); // Доступ только врачам

$doc_id = $_SESSION['user_id'];
$patient_id = (int)($_GET['patient_id'] ?? 0);

if (!$patient_id) redirect('dashboard.php');

// 1. Проверка: существует ли пациент и был ли он у этого врача?
$check = $pdo->prepare("SELECT id, full_name, telegram_nick, photo FROM users WHERE id = ? AND role = 'patient'");
$check->execute([$patient_id]);
$patient = $check->fetch();

if (!$patient) {
    die("Пациент не найден.");
}

// 2. Параметры фильтрации и сортировки
$date_search = $_GET['date'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort_by = $_GET['sort'] ?? 'date_desc'; // date_desc, date_asc, price_desc

// Параметры пагинации
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// 3. Формирование SQL запроса
$where = "WHERE a.patient_id = ? AND a.doctor_id = ?";
$params = [$patient_id, $doc_id];

if ($date_search) {
    $where .= " AND a.appointment_date = ?";
    $params[] = $date_search;
}
if ($status_filter) {
    $where .= " AND a.status = ?";
    $params[] = $status_filter;
}

// Сортировка
$order_sql = "ORDER BY a.appointment_date DESC, a.start_time DESC";
if ($sort_by === 'date_asc') $order_sql = "ORDER BY a.appointment_date ASC, a.start_time ASC";
if ($sort_by === 'price_desc') $order_sql = "ORDER BY a.price DESC";

// 4. Подсчет общего количества записей для пагинации
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM appointments a $where");
$countStmt->execute($params);
$total_records = $countStmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

// 5. Получение записей
$stmt = $pdo->prepare("
    SELECT a.id, a.appointment_date, a.start_time, a.status, a.price, a.created_at, s.name as service_name
    FROM appointments a
    JOIN services s ON a.service_id = s.id
    $where
    $order_sql
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$history = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>История пациента | Medprofi</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="site-header">
    <div class="container header-flex">
        <a href="dashboard.php" class="logo">← Дашборд</a>
        <a href="logout.php" class="btn btn-outline">Выйти</a>
    </div>
</header>

<main class="container" style="max-width: 1000px;">
    <!-- Профиль пациента -->
    <div class="patient-profile-header">
        <div class="pp-avatar">
            <img src="<?=htmlspecialchars($patient['photo'] ?? 'https://placehold.co/150x200/e2e8f0/1e293b?text=Пациент')?>" 
                 onerror="this.src='https://placehold.co/150x200/e2e8f0/1e293b?text=Пациент'">
        </div>
        <div class="pp-info">
            <h1><?=htmlspecialchars($patient['full_name'])?></h1>
            <?php if(!empty($patient['telegram_nick'])): ?>
                <p class="tg-link">
                    📱 Telegram: <a href="https://t.me/<?=htmlspecialchars($patient['telegram_nick'])?>" target="_blank">@<?=htmlspecialchars($patient['telegram_nick'])?></a>
                </p>
            <?php else: ?>
                <p class="tg-link" style="opacity:0.5">Telegram не указан</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Панель фильтров -->
    <div class="filters-card">
        <form method="GET" class="history-filters">
            <input type="hidden" name="patient_id" value="<?= $patient_id ?>">
            
            <div class="filter-group">
                <label>Дата приема:</label>
                <input type="date" name="date" value="<?=htmlspecialchars($date_search)?>">
            </div>

            <div class="filter-group">
                <label>Статус:</label>
                <select name="status">
                    <option value="">Все статусы</option>
                    <option value="booked" <?=($status_filter=='booked'?'selected':'')?>>Записан</option>
                    <option value="completed" <?=($status_filter=='completed'?'selected':'')?>>Завершен</option>
                    <option value="cancelled" <?=($status_filter=='cancelled'?'selected':'')?>>Отменен</option>
                </select>
            </div>

            <div class="filter-group">
                <label>Сортировка:</label>
                <select name="sort" onchange="this.form.submit()">
                    <option value="date_desc" <?=($sort_by=='date_desc'?'selected':'')?>>Сначала новые</option>
                    <option value="date_asc" <?=($sort_by=='date_asc'?'selected':'')?>>Сначала старые</option>
                    <option value="price_desc" <?=($sort_by=='price_desc'?'selected':'')?>>По цене (убыв.)</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Применить</button>
            <?php if($date_search || $status_filter): ?>
                <a href="?patient_id=<?= $patient_id ?>" class="btn btn-outline">Сбросить</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Таблица истории -->
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Дата</th>
                    <th>Время</th>
                    <th>Услуга</th>
                    <th>Цена</th>
                    <th>Статус</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($history)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center; padding:30px; color:#64748b;">
                            Записей не найдено по выбранным критериям.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach($history as $row): ?>
                    <tr>
                        <td><?=date('d.m.Y', strtotime($row['appointment_date']))?></td>
                        <td><?=substr($row['start_time'],0,5)?></td>
                        <td><?=htmlspecialchars($row['service_name'])?></td>
                        <td><strong><?=number_format($row['price'], 0, ',', ' ')?> BUN</strong></td>
                        <td>
                            <?php if($row['status'] === 'completed'): ?>
                                <span class="status-badge completed">Завершен</span>
                            <?php elseif($row['status'] === 'cancelled'): ?>
                                <span class="status-badge cancelled">Отменен</span>
                            <?php else: ?>
                                <span class="status-badge booked">Записан</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Пагинация -->
    <?php if($total_pages > 1): ?>
        <div class="pagination">
            <?php if($page > 1): ?>
                <a href="?patient_id=<?= $patient_id ?>&page=<?= $page-1 ?>&date=<?=urlencode($date_search)?>&status=<?=urlencode($status_filter)?>&sort=<?=urlencode($sort_by)?>" class="pg-btn">← Назад</a>
            <?php endif; ?>
            
            <?php for($i=1; $i<=$total_pages; $i++): ?>
                <a href="?patient_id=<?= $patient_id ?>&page=<?= $i ?>&date=<?=urlencode($date_search)?>&status=<?=urlencode($status_filter)?>&sort=<?=urlencode($sort_by)?>" class="pg-btn <?=($i==$page ? 'active' : '')?>"><?= $i ?></a>
            <?php endfor; ?>
            
            <?php if($page < $total_pages): ?>
                <a href="?patient_id=<?= $patient_id ?>&page=<?= $page+1 ?>&date=<?=urlencode($date_search)?>&status=<?=urlencode($status_filter)?>&sort=<?=urlencode($sort_by)?>" class="pg-btn">Вперед →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</main>

<style>
    .patient-profile-header {
        display: flex;
        align-items: center;
        gap: 25px;
        background: #fff;
        padding: 25px;
        border-radius: 12px;
        border: 1px solid var(--border);
        margin-bottom: 25px;
    }
    .pp-avatar img {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid #f1f5f9;
    }
    .pp-info h1 { margin: 0 0 8px; font-size: 1.8rem; color: #0f172a; }
    .tg-link { margin: 0; font-size: 1rem; }
    .tg-link a { color: var(--primary); text-decoration: none; font-weight: 600; }
    .tg-link a:hover { text-decoration: underline; }

    .filters-card {
        background: #fff;
        padding: 20px;
        border-radius: 10px;
        border: 1px solid var(--border);
        margin-bottom: 25px;
    }
    .history-filters {
        display: flex;
        gap: 15px;
        align-items: flex-end;
        flex-wrap: wrap;
    }
    .filter-group { display: flex; flex-direction: column; gap: 5px; }
    .filter-group label { font-size: 0.85rem; font-weight: 600; color: #475569; }
    .filter-group input, .filter-group select {
        padding: 10px;
        border: 1px solid var(--border);
        border-radius: 6px;
    }
</style>
</body>
</html>