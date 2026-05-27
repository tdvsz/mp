<?php
require 'config.php';
requireAuth(['patient']);

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 9;
$offset = ($page - 1) * $limit;

$search = trim($_GET['search'] ?? '');
$specialty_id = (int)($_GET['specialty_id'] ?? 0);

$where = "WHERE u.role = 'doctor'";
$params = [];

if ($search !== '') {
    // Ищем по имени врача или названию специальности через JOIN
    $where .= " AND (u.full_name LIKE ? OR sp.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($specialty_id > 0) {
    $where .= " AND u.specialty_id = ?";
    $params[] = $specialty_id;
}

try {
    // Подсчёт для пагинации
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u $where");
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();
    $totalPages = ceil($total / $limit);

    // Выборка врачей с JOIN к specialties
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.specialty_id, u.experience_years, u.photo, sp.name as specialty_name
        FROM users u 
        LEFT JOIN specialties sp ON u.specialty_id = sp.id
        $where 
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $doctors = $stmt->fetchAll();
    
    // Получаем список специальностей для фильтра
    $specs = $pdo->query("SELECT id, name FROM specialties ORDER BY name")->fetchAll();
    
} catch (PDOException $e) {
    die("Ошибка БД: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Врачи | Medprofi</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="site-header">
    <div class="container header-flex">
        <a href="index.php" class="logo">Medprofi</a>
        <nav>
            <a href="dashboard.php" class="btn btn-outline">Мои записи</a>
            <a href="logout.php" class="btn btn-outline">Выйти</a>
        </nav>
    </div>
</header>

<main class="container">
    <h1>Наши специалисты</h1>
    
    <form method="GET" class="filters-bar">
        <input type="text" name="search" placeholder="Поиск по ФИО или специальности" value="<?=htmlspecialchars($search)?>" class="search-input">
        <select name="specialty_id" class="filter-select">
            <option value="">Все специальности</option>
            <?php foreach($specs as $s): ?>
                <option value="<?=$s['id']?>" <?=($specialty_id === $s['id'] ? 'selected' : '')?>><?=htmlspecialchars($s['name'])?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Применить</button>
    </form>

    <div class="doctors-grid">
        <?php if(empty($doctors)): ?>
            <p class="no-results">Врачи не найдены. Попробуйте изменить параметры поиска.</p>
        <?php else: ?>
            <?php foreach($doctors as $doc): ?>
                <div class="doctor-card">
                    <img src="uploads/<?=htmlspecialchars($doc['photo'])?>" alt="<?=htmlspecialchars($doc['full_name'])?>" onerror="this.src='https://placehold.co/150x200/e2e8f0/1e293b?text=Нет+фото'">
                    <div class="card-info">
                        <h3><?=htmlspecialchars($doc['full_name'])?></h3>
                        <p class="spec"><?=htmlspecialchars($doc['specialty_name'] ?? 'Не указана')?></p>
                        <p class="exp">Стаж: <?=$doc['experience_years']?> лет</p>
                        <a href="book.php?doctor_id=<?= $doc['id'] ?>" class="btn btn-primary btn-sm">Записаться</a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if($totalPages > 1): ?>
        <div class="pagination">
            <?php if($page > 1): ?>
                <a href="?page=<?= $page-1 ?>&search=<?=urlencode($search)?>&specialty_id=<?=$specialty_id?>" class="pg-btn">← Назад</a>
            <?php endif; ?>
            <?php for($i=1; $i<=$totalPages; $i++): ?>
                <a href="?page=<?= $i ?>&search=<?=urlencode($search)?>&specialty_id=<?=$specialty_id?>" class="pg-btn <?=($i==$page ? 'active' : '')?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if($page < $totalPages): ?>
                <a href="?page=<?= $page+1 ?>&search=<?=urlencode($search)?>&specialty_id=<?=$specialty_id?>" class="pg-btn">Вперед →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>
<?php require 'toast.php'; ?>
</body>
</html>