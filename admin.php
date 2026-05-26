<?php
require 'config.php';
requireAuth(['admin']);

// Пагинация
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// Активная вкладка
$active_tab = $_GET['tab'] ?? 'appointments';

// Обработка действий
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // === ЗАПИСИ ===
        if (isset($_POST['update_appointment_status'])) {
            $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ?")
                ->execute([$_POST['status'], (int)$_POST['ap_id']]);
            $msg = 'Статус обновлен';
        }
        elseif (isset($_POST['delete_appointment'])) {
            $pdo->prepare("DELETE FROM appointments WHERE id = ?")->execute([(int)$_POST['ap_id']]);
            $msg = 'Запись удалена';
        }
        
        // === ВРАЧИ ===
        elseif (isset($_POST['add_doctor']) || isset($_POST['edit_doctor'])) {
            $data = [
                $_POST['full_name'],
                $_POST['email'],
                $_POST['specialty_id'] ?: null,
                $_POST['experience_years'],
                $_POST['photo'] ?: null
            ];
            
            if (isset($_POST['edit_doctor']) && !empty($_POST['doctor_id'])) {
                $data[] = (int)$_POST['doctor_id'];
                $pdo->prepare("UPDATE users SET full_name=?, email=?, specialty_id=?, experience_years=?, photo=? WHERE id=?")
                    ->execute($data);
                $msg = 'Врач обновлен';
            } else {
                $data[] = password_hash($_POST['password'] ?? '123456', PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (full_name, email, specialty_id, experience_years, photo, password_hash, role) VALUES (?,?,?,?,?,?,'doctor')")
                    ->execute($data);
                $msg = 'Врач добавлен';
            }
        }
        elseif (isset($_POST['delete_doctor'])) {
            $pdo->prepare("DELETE FROM users WHERE id = ? AND role='doctor'")->execute([(int)$_POST['doctor_id']]);
            $msg = 'Врач удален';
        }
        
        // === УСЛУГИ ===
        elseif (isset($_POST['add_service']) || isset($_POST['edit_service'])) {
            $data = [
                $_POST['name'],
                (int)$_POST['duration_minutes'],
                (float)$_POST['price'],
                $_POST['specialty_id'] ?: null
            ];
            
            if (isset($_POST['edit_service']) && !empty($_POST['service_id'])) {
                $data[] = (int)$_POST['service_id'];
                $pdo->prepare("UPDATE services SET name=?, duration_minutes=?, price=?, specialty_id=? WHERE id=?")
                    ->execute($data);
                $msg = 'Услуга обновлена';
            } else {
                $pdo->prepare("INSERT INTO services (name, duration_minutes, price, specialty_id) VALUES (?,?,?,?)")
                    ->execute($data);
                $msg = 'Услуга добавлена';
            }
        }
        elseif (isset($_POST['delete_service'])) {
            $pdo->prepare("DELETE FROM services WHERE id = ?")->execute([(int)$_POST['service_id']]);
            $msg = 'Услуга удалена';
        }
        
        // === СПЕЦИАЛЬНОСТИ ===
        elseif (isset($_POST['add_specialty']) || isset($_POST['edit_specialty'])) {
            if (isset($_POST['edit_specialty']) && !empty($_POST['specialty_id'])) {
                $pdo->prepare("UPDATE specialties SET name=?, description=? WHERE id=?")
                    ->execute([$_POST['name'], $_POST['description'], (int)$_POST['specialty_id']]);
                $msg = 'Специальность обновлена';
            } else {
                $pdo->prepare("INSERT INTO specialties (name, description) VALUES (?,?)")
                    ->execute([$_POST['name'], $_POST['description']]);
                $msg = 'Специальность добавлена';
            }
        }
        elseif (isset($_POST['delete_specialty'])) {
            $pdo->prepare("DELETE FROM specialties WHERE id = ?")->execute([(int)$_POST['specialty_id']]);
            $msg = 'Специальность удалена';
        }
        
    } catch (PDOException $e) {
        $err = 'Ошибка: ' . $e->getMessage();
    }
}

// Получение данных для таблиц
$data = [];
$total = 0;

switch ($active_tab) {
    case 'appointments':
        $stmt = $pdo->prepare("
            SELECT a.*, p.full_name as patient_name, d.full_name as doctor_name, s.name as service_name
            FROM appointments a
            JOIN users p ON a.patient_id = p.id
            JOIN users d ON a.doctor_id = d.id
            JOIN services s ON a.service_id = s.id
            ORDER BY a.appointment_date DESC, a.start_time DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $data = $stmt->fetchAll();
        
        $total = $pdo->query("SELECT COUNT(*) FROM appointments")->fetchColumn();
        break;
        
    case 'doctors':
        $stmt = $pdo->prepare("
            SELECT u.*, s.name as specialty_name
            FROM users u
            LEFT JOIN specialties s ON u.specialty_id = s.id
            WHERE u.role = 'doctor'
            ORDER BY u.full_name
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $data = $stmt->fetchAll();
        
        $total = $pdo->query("SELECT COUNT(*) FROM users WHERE role='doctor'")->fetchColumn();
        break;
        
    case 'services':
        $stmt = $pdo->prepare("
            SELECT s.*, sp.name as specialty_name
            FROM services s
            LEFT JOIN specialties sp ON s.specialty_id = sp.id
            ORDER BY s.name
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $data = $stmt->fetchAll();
        
        $total = $pdo->query("SELECT COUNT(*) FROM services")->fetchColumn();
        break;
        
    case 'specialties':
        $stmt = $pdo->prepare("SELECT * FROM specialties ORDER BY name LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $data = $stmt->fetchAll();
        
        $total = $pdo->query("SELECT COUNT(*) FROM specialties")->fetchColumn();
        break;
        
    case 'patients':
        $stmt = $pdo->prepare("
            SELECT u.*, COUNT(a.id) as appointments_count
            FROM users u
            LEFT JOIN appointments a ON u.id = a.patient_id
            WHERE u.role = 'patient'
            GROUP BY u.id
            ORDER BY u.full_name
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $data = $stmt->fetchAll();
        
        $total = $pdo->query("SELECT COUNT(*) FROM users WHERE role='patient'")->fetchColumn();
        break;
}

$total_pages = ceil($total / $limit);

// Данные для форм
$specialties_list = $pdo->query("SELECT * FROM specialties ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Админ-панель | Medprofi</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-layout { display: flex; min-height: 100vh; }
        .admin-sidebar { width: 250px; background: #fff; border-right: 1px solid var(--border); padding: 20px 0; position: fixed; height: 100vh; overflow-y: auto; }
        .admin-content { flex: 1; margin-left: 250px; padding: 30px; }
        .admin-nav-item { display: block; padding: 12px 20px; color: var(--text); text-decoration: none; transition: 0.2s; border-left: 3px solid transparent; }
        .admin-nav-item:hover { background: #f8fafc; }
        .admin-nav-item.active { background: #eff6ff; border-left-color: var(--primary); color: var(--primary); font-weight: 600; }
        .admin-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .admin-title { font-size: 1.8rem; margin: 0; }
        .btn-add { background: #10b981; color: #fff; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; cursor: pointer; border: none; }
        .btn-add:hover { background: #059669; }
        .data-table { width: 100%; background: #fff; border-radius: 10px; overflow: hidden; border: 1px solid var(--border); }
        .data-table th, .data-table td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border); }
        .data-table th { background: #f8fafc; font-weight: 600; }
        .data-table tr:hover { background: #f8fafc; }
        .status-badge { padding: 4px 10px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; }
        .status-badge.booked { background: #dbeafe; color: #1e40af; }
        .status-badge.completed { background: #d1fae5; color: #047857; }
        .status-badge.cancelled { background: #fee2e2; color: #b91c1c; }
        .btn-sm { padding: 6px 12px; font-size: 0.85rem; border-radius: 6px; border: none; cursor: pointer; margin-right: 5px; }
        .btn-edit { background: #3b82f6; color: #fff; }
        .btn-delete { background: #ef4444; color: #fff; }
        .btn-approve { background: #10b981; color: #fff; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: #fff; padding: 30px; border-radius: 12px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; color: #475569; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; }
        .pagination { display: flex; justify-content: center; gap: 6px; margin-top: 20px; }
        .pg-btn { padding: 8px 14px; border-radius: 8px; background: #fff; border: 1px solid var(--border); text-decoration: none; color: var(--text); font-weight: 500; }
        .pg-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .pg-btn:hover { background: #f8fafc; }
    </style>
</head>
<body>
<div class="admin-layout">
    <!-- Sidebar -->
    <aside class="admin-sidebar">
        <div style="padding: 0 20px 20px; border-bottom: 1px solid var(--border); margin-bottom: 20px;">
            <h2 style="margin: 0; color: var(--primary);">Medprofi Admin</h2>
        </div>
        <nav>
            <a href="?tab=appointments" class="admin-nav-item <?=($active_tab=='appointments'?'active':'')?>">📅 Записи</a>
            <a href="?tab=doctors" class="admin-nav-item <?=($active_tab=='doctors'?'active':'')?>">👨‍⚕️ Врачи</a>
            <a href="?tab=services" class="admin-nav-item <?=($active_tab=='services'?'active':'')?>">🏥 Услуги</a>
            <a href="?tab=specialties" class="admin-nav-item <?=($active_tab=='specialties'?'active':'')?>">📋 Специальности</a>
            <a href="?tab=patients" class="admin-nav-item <?=($active_tab=='patients'?'active':'')?>">👥 Пациенты</a>
            <a href="index.php" class="admin-nav-item" style="margin-top: 30px; border-top: 1px solid var(--border);">← На сайт</a>
        </nav>
    </aside>

    <!-- Content -->
    <main class="admin-content">
        <?php if($msg): ?><div class="success" style="margin-bottom: 20px;"><?=htmlspecialchars($msg)?></div><?php endif; ?>
        <?php if($err): ?><div class="err" style="margin-bottom: 20px;"><?=htmlspecialchars($err)?></div><?php endif; ?>

        <div class="admin-header">
            <h1 class="admin-title">
                <?php
                $titles = [
                    'appointments' => '📅 Записи на прием',
                    'doctors' => '👨‍️ Врачи',
                    'services' => '🏥 Услуги',
                    'specialties' => '📋 Специальности',
                    'patients' => '👥 Пациенты'
                ];
                echo $titles[$active_tab] ?? 'Админ-панель';
                ?>
            </h1>
            <?php if($active_tab !== 'patients'): ?>
            <button class="btn-add" onclick="openModal()">+ Добавить</button>
            <?php endif; ?>
        </div>

        <!-- TABLES -->
        <?php if($active_tab === 'appointments'): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Дата</th>
                    <th>Время</th>
                    <th>Пациент</th>
                    <th>Врач</th>
                    <th>Услуга</th>
                    <th>Статус</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($data as $row): ?>
                <tr>
                    <td><?=$row['id']?></td>
                    <td><?=htmlspecialchars($row['appointment_date'])?></td>
                    <td><?=substr($row['start_time'],0,5)?></td>
                    <td><?=htmlspecialchars($row['patient_name'])?></td>
                    <td><?=htmlspecialchars($row['doctor_name'])?></td>
                    <td><?=htmlspecialchars($row['service_name'])?></td>
                    <td>
                        <select onchange="updateStatus(<?=$row['id']?>, this.value)" style="padding: 4px; border-radius: 4px;">
                            <option value="booked" <?=($row['status']=='booked'?'selected':'')?>>Записан</option>
                            <option value="completed" <?=($row['status']=='completed'?'selected':'')?>>Завершен</option>
                            <option value="cancelled" <?=($row['status']=='cancelled'?'selected':'')?>>Отменен</option>
                        </select>
                    </td>
                    <td>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить?')">
                            <input type="hidden" name="ap_id" value="<?=$row['id']?>">
                            <button type="submit" name="delete_appointment" class="btn-sm btn-delete">🗑</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php elseif($active_tab === 'doctors'): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>ФИО</th>
                    <th>Email</th>
                    <th>Специальность</th>
                    <th>Стаж</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($data as $row): ?>
                <tr>
                    <td><?=$row['id']?></td>
                    <td><?=htmlspecialchars($row['full_name'])?></td>
                    <td><?=htmlspecialchars($row['email'])?></td>
                    <td><?=htmlspecialchars($row['specialty_name'] ?? '-')?></td>
                    <td><?=$row['experience_years']?> лет</td>
                    <td>
                        <button class="btn-sm btn-edit" onclick='editDoctor(<?=json_encode($row)?>)'>✏️</button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить врача?')">
                            <input type="hidden" name="doctor_id" value="<?=$row['id']?>">
                            <button type="submit" name="delete_doctor" class="btn-sm btn-delete">🗑</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php elseif($active_tab === 'services'): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Название</th>
                    <th>Длительность</th>
                    <th>Цена</th>
                    <th>Специальность</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($data as $row): ?>
                <tr>
                    <td><?=$row['id']?></td>
                    <td><?=htmlspecialchars($row['name'])?></td>
                    <td><?=$row['duration_minutes']?> мин</td>
                    <td><?=number_format($row['price'], 0, ',', ' ')?> BUN</td>
                    <td><?=htmlspecialchars($row['specialty_name'] ?? 'Общая')?></td>
                    <td>
                        <button class="btn-sm btn-edit" onclick='editService(<?=json_encode($row)?>)'>✏️</button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить услугу?')">
                            <input type="hidden" name="service_id" value="<?=$row['id']?>">
                            <button type="submit" name="delete_service" class="btn-sm btn-delete">🗑</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php elseif($active_tab === 'specialties'): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Название</th>
                    <th>Описание</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($data as $row): ?>
                <tr>
                    <td><?=$row['id']?></td>
                    <td><?=htmlspecialchars($row['name'])?></td>
                    <td><?=htmlspecialchars($row['description'] ?? '-')?></td>
                    <td>
                        <button class="btn-sm btn-edit" onclick='editSpecialty(<?=json_encode($row)?>)'>✏️</button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить специальность?')">
                            <input type="hidden" name="specialty_id" value="<?=$row['id']?>">
                            <button type="submit" name="delete_specialty" class="btn-sm btn-delete">🗑</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php elseif($active_tab === 'patients'): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>ФИО</th>
                    <th>Email</th>
                    <th>Записей</th>
                    <th>Telegram</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($data as $row): ?>
                <tr>
                    <td><?=$row['id']?></td>
                    <td><?=htmlspecialchars($row['full_name'])?></td>
                    <td><?=htmlspecialchars($row['email'])?></td>
                    <td><?=$row['appointments_count']?></td>
                    <td><?=htmlspecialchars($row['telegram_nick'] ?? '-')?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- PAGINATION -->
        <?php if($total_pages > 1): ?>
        <div class="pagination">
            <?php if($page > 1): ?>
                <a href="?tab=<?=$active_tab?>&page=<?= $page-1 ?>" class="pg-btn">←</a>
            <?php endif; ?>
            <?php for($i=1; $i<=$total_pages; $i++): ?>
                <a href="?tab=<?=$active_tab?>&page=<?= $i ?>" class="pg-btn <?=($i==$page ? 'active' : '')?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if($page < $total_pages): ?>
                <a href="?tab=<?=$active_tab?>&page=<?= $page+1 ?>" class="pg-btn">→</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>
</div>

<!-- MODALS -->
<!-- Doctor Modal -->
<div id="doctorModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="doctorModalTitle">Добавить врача</h3>
            <button class="modal-close" onclick="closeModal('doctorModal')">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="doctor_id" id="doctor_id">
            <div class="form-group">
                <label>ФИО:</label>
                <input type="text" name="full_name" id="doctor_full_name" required>
            </div>
            <div class="form-group">
                <label>Email:</label>
                <input type="email" name="email" id="doctor_email" required>
            </div>
            <div class="form-group">
                <label>Пароль (оставьте пустым при редактировании):</label>
                <input type="password" name="password" id="doctor_password">
            </div>
            <div class="form-group">
                <label>Специальность:</label>
                <select name="specialty_id" id="doctor_specialty_id">
                    <option value="">Не выбрана</option>
                    <?php foreach($specialties_list as $s): ?>
                        <option value="<?=$s['id']?>"><?=htmlspecialchars($s['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Стаж (лет):</label>
                <input type="number" name="experience_years" id="doctor_experience_years" min="0" required>
            </div>
            <div class="form-group">
                <label>Фото (URL):</label>
                <input type="url" name="photo" id="doctor_photo">
            </div>
            <button type="submit" name="add_doctor" class="btn-add" style="width:100%;">Сохранить</button>
        </form>
    </div>
</div>

<!-- Service Modal -->
<div id="serviceModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="serviceModalTitle">Добавить услугу</h3>
            <button class="modal-close" onclick="closeModal('serviceModal')">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="service_id" id="service_id">
            <div class="form-group">
                <label>Название:</label>
                <input type="text" name="name" id="service_name" required>
            </div>
            <div class="form-group">
                <label>Длительность (мин):</label>
                <input type="number" name="duration_minutes" id="service_duration" min="1" required>
            </div>
            <div class="form-group">
                <label>Цена (BUN):</label>
                <input type="number" name="price" id="service_price" min="0" step="0.01" required>
            </div>
            <div class="form-group">
                <label>Специальность:</label>
                <select name="specialty_id" id="service_specialty_id">
                    <option value="">Общая (для всех)</option>
                    <?php foreach($specialties_list as $s): ?>
                        <option value="<?=$s['id']?>"><?=htmlspecialchars($s['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" name="add_service" class="btn-add" style="width:100%;">Сохранить</button>
        </form>
    </div>
</div>

<!-- Specialty Modal -->
<div id="specialtyModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="specialtyModalTitle">Добавить специальность</h3>
            <button class="modal-close" onclick="closeModal('specialtyModal')">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="specialty_id" id="specialty_id">
            <div class="form-group">
                <label>Название:</label>
                <input type="text" name="name" id="specialty_name" required>
            </div>
            <div class="form-group">
                <label>Описание:</label>
                <textarea name="description" id="specialty_description" rows="3"></textarea>
            </div>
            <button type="submit" name="add_specialty" class="btn-add" style="width:100%;">Сохранить</button>
        </form>
    </div>
</div>

<script>
// Modal functions
function openModal(type = '<?= $active_tab ?>') {
    if(type === 'doctors') document.getElementById('doctorModal').classList.add('active');
    else if(type === 'services') document.getElementById('serviceModal').classList.add('active');
    else if(type === 'specialties') document.getElementById('specialtyModal').classList.add('active');
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if(!modal) return;
    
    modal.classList.remove('active');
    
    // Clear form
    const form = modal.querySelector('form');
    if(form) {
        form.reset();
        // Clear hidden ID fields
        form.querySelectorAll('input[type="hidden"][name$="_id"]').forEach(input => {
            input.value = '';
        });
    }
    
    // Reset modal title
    const title = modal.querySelector('h3');
    if(title && title.id.includes('ModalTitle')) {
        title.textContent = title.id.replace('ModalTitle', '').replace(/([A-Z])/g, ' $1').trim();
    }
}

// Update appointment status
function updateStatus(apId, status) {
    if(!confirm('Изменить статус записи?')) return;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    form.innerHTML = `<input type="hidden" name="ap_id" value="${apId}"><input type="hidden" name="status" value="${status}"><input type="hidden" name="update_appointment_status" value="1">`;
    document.body.appendChild(form);
    form.submit();
}

// Edit functions
function editDoctor(data) {
    document.getElementById('doctorModalTitle').textContent = 'Редактировать врача';
    document.getElementById('doctor_id').value = data.id || '';
    document.getElementById('doctor_full_name').value = data.full_name || '';
    document.getElementById('doctor_email').value = data.email || '';
    document.getElementById('doctor_specialty_id').value = data.specialty_id || '';
    document.getElementById('doctor_experience_years').value = data.experience_years || '';
    document.getElementById('doctor_photo').value = data.photo || '';
    document.getElementById('doctorModal').classList.add('active');
}

function editService(data) {
    document.getElementById('serviceModalTitle').textContent = 'Редактировать услугу';
    document.getElementById('service_id').value = data.id || '';
    document.getElementById('service_name').value = data.name || '';
    document.getElementById('service_duration').value = data.duration_minutes || '';
    document.getElementById('service_price').value = data.price || '';
    document.getElementById('service_specialty_id').value = data.specialty_id || '';
    document.getElementById('serviceModal').classList.add('active');
}

function editSpecialty(data) {
    document.getElementById('specialtyModalTitle').textContent = 'Редактировать специальность';
    document.getElementById('specialty_id').value = data.id || '';
    document.getElementById('specialty_name').value = data.name || '';
    document.getElementById('specialty_description').value = data.description || '';
    document.getElementById('specialtyModal').classList.add('active');
}

// Close modals on outside click
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
    }
}

// Close modals on Escape key
document.addEventListener('keydown', function(event) {
    if(event.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(modal => {
            modal.classList.remove('active');
        });
    }
});
</script>
<?php require 'toast.php'; ?>
</body>
</html>