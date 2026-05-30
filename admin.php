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
        if (isset($_POST['update_appointment_status'])) {
            $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ?")
                ->execute([$_POST['status'], (int)$_POST['ap_id']]);
            $msg = 'Статус обновлен';
        } elseif (isset($_POST['delete_appointment'])) {
            $pdo->prepare("DELETE FROM appointments WHERE id = ?")->execute([(int)$_POST['ap_id']]);
            $msg = 'Запись удалена';
        } elseif (isset($_POST['add_doctor']) || isset($_POST['edit_doctor'])) {
            $photo_filename = null;
            if (!empty($_POST['doctor_id'])) {
                $stmt_old = $pdo->prepare("SELECT photo FROM users WHERE id = ?");
                $stmt_old->execute([(int)$_POST['doctor_id']]);
                $photo_filename = $stmt_old->fetchColumn();
            }
            if (!empty($_FILES['photo']['name'])) {
                $uploaded = handlePhotoUpload($_FILES['photo'], $photo_filename);
                if ($uploaded === false) $err = 'Ошибка загрузки фото.';
                else $photo_filename = $uploaded;
            }
            if (empty($err)) {
                $phone = trim($_POST['phone_number']);
                $email = trim($_POST['email']) ?: null;
                $full_name = $_POST['full_name'];
                $specialty_id = $_POST['specialty_id'] ?: null;
                $exp = (int)$_POST['experience_years'];

                if (!empty($_POST['doctor_id'])) {
                    $id = (int)$_POST['doctor_id'];
                    $pdo->prepare("UPDATE users SET full_name=?, phone_number=?, email=?, specialty_id=?, experience_years=?, photo=? WHERE id=?")
                        ->execute([$full_name, $phone, $email, $specialty_id, $exp, $photo_filename, $id]);
                    $msg = 'Врач обновлен';
                } else {
                    $pass_hash = password_hash($_POST['password'] ?? '123456', PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO users (full_name, phone_number, email, specialty_id, experience_years, photo, password_hash, role) VALUES (?,?,?,?,?,?,?,'doctor')")
                        ->execute([$full_name, $phone, $email, $specialty_id, $exp, $photo_filename, $pass_hash]);
                    $msg = 'Врач добавлен';
                }
            }
        } elseif (isset($_POST['delete_doctor'])) {
            $pdo->prepare("DELETE FROM users WHERE id = ? AND role='doctor'")->execute([(int)$_POST['doctor_id']]);
            $msg = 'Врач удален';
        } elseif (isset($_POST['add_service']) || isset($_POST['edit_service'])) {
            $data = [
                $_POST['name'],
                (int)$_POST['duration_minutes'],
                (float)$_POST['price'],
                $_POST['specialty_id'] ?: null
            ];

            if (!empty($_POST['service_id'])) {
                $data[] = (int)$_POST['service_id'];
                $pdo->prepare("UPDATE services SET name=?, duration_minutes=?, price=?, specialty_id=? WHERE id=?")
                    ->execute($data);
                $msg = 'Услуга обновлена';
            } else {
                $pdo->prepare("INSERT INTO services (name, duration_minutes, price, specialty_id) VALUES (?,?,?,?)")
                    ->execute($data);
                $msg = 'Услуга добавлена';
            }
        } elseif (isset($_POST['delete_service'])) {
            $pdo->prepare("DELETE FROM services WHERE id = ?")->execute([(int)$_POST['service_id']]);
            $msg = 'Услуга удалена';
        } elseif (isset($_POST['add_specialty']) || isset($_POST['edit_specialty'])) {
            if (!empty($_POST['specialty_id'])) {
                $pdo->prepare("UPDATE specialties SET name=? WHERE id=?")
                    ->execute([$_POST['name'], (int)$_POST['specialty_id']]);
                $msg = 'Специальность обновлена';
            } else {
                $pdo->prepare("INSERT INTO specialties (name) VALUES (?)")
                    ->execute([$_POST['name']]);
                $msg = 'Специальность добавлена';
            }
        } elseif (isset($_POST['delete_specialty'])) {
            $pdo->prepare("DELETE FROM specialties WHERE id = ?")->execute([(int)$_POST['specialty_id']]);
            $msg = 'Специальность удалена';
        }
    } catch (PDOException $e) {
        $err = 'Ошибка: ' . $e->getMessage();
    }
}

$search = trim($_GET['search'] ?? '');
$search_param = "%{$search}%";

$data = [];
$total = 0;

switch ($active_tab) {
    case 'appointments':
        $where = $search ? "WHERE p.full_name LIKE ? OR d.full_name LIKE ? OR s.name LIKE ?" : "";
        $stmt = $pdo->prepare("
            SELECT a.*, p.full_name as patient_name, d.full_name as doctor_name, s.name as service_name
            FROM appointments a
            JOIN users p ON a.patient_id = p.id
            JOIN users d ON a.doctor_id = d.id
            JOIN services s ON a.service_id = s.id
            $where
            ORDER BY a.appointment_date DESC, a.start_time DESC
            LIMIT ? OFFSET ?");
        $params = $search ? [$search_param, $search_param, $search_param, $limit, $offset] : [$limit, $offset];
        $stmt->execute($params);
        $data = $stmt->fetchAll();

        $stmt_total = $pdo->prepare("SELECT COUNT(*) FROM appointments a JOIN users p ON a.patient_id = p.id JOIN users d ON a.doctor_id = d.id JOIN services s ON a.service_id = s.id $where");
        $stmt_total->execute($search ? [$search_param, $search_param, $search_param] : []);
        $total = $stmt_total->fetchColumn();
        break;

    case 'doctors':
        $where = $search ? "AND (u.full_name LIKE ? OR u.phone_number LIKE ? OR u.email LIKE ? OR s.name LIKE ?)" : "";
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.phone_number, u.email, u.experience_years, u.photo, s.name as specialty_name
            FROM users u
            LEFT JOIN specialties s ON u.specialty_id = s.id
            WHERE u.role = 'doctor' $where
            ORDER BY u.full_name
            LIMIT ? OFFSET ?");
        $params = $search ? [$search_param, $search_param, $search_param, $search_param, $limit, $offset] : [$limit, $offset];
        $stmt->execute($params);
        $data = $stmt->fetchAll();

        $stmt_total = $pdo->prepare("SELECT COUNT(*) FROM users u LEFT JOIN specialties s ON u.specialty_id = s.id WHERE u.role = 'doctor' $where");
        $stmt_total->execute($search ? [$search_param, $search_param, $search_param, $search_param] : []);
        $total = $stmt_total->fetchColumn();
        break;

    case 'services':
        $where = $search ? "WHERE s.name LIKE ? OR sp.name LIKE ?" : "";
        $stmt = $pdo->prepare("
            SELECT s.*, sp.name as specialty_name
            FROM services s
            LEFT JOIN specialties sp ON s.specialty_id = sp.id
            $where
            ORDER BY s.name
            LIMIT ? OFFSET ?");
        $params = $search ? [$search_param, $search_param, $limit, $offset] : [$limit, $offset];
        $stmt->execute($params);
        $data = $stmt->fetchAll();

        $stmt_total = $pdo->prepare("SELECT COUNT(*) FROM services s LEFT JOIN specialties sp ON s.specialty_id = sp.id $where");
        $stmt_total->execute($search ? [$search_param, $search_param] : []);
        $total = $stmt_total->fetchColumn();
        break;

    case 'specialties':
        $where = $search ? "WHERE name LIKE ?" : "";
        $stmt = $pdo->prepare("SELECT * FROM specialties $where ORDER BY name LIMIT ? OFFSET ?");
        $params = $search ? [$search_param, $limit, $offset] : [$limit, $offset];
        $stmt->execute($params);
        $data = $stmt->fetchAll();

        $stmt_total = $pdo->prepare("SELECT COUNT(*) FROM specialties $where");
        $stmt_total->execute($search ? [$search_param] : []);
        $total = $stmt_total->fetchColumn();
        break;

    case 'patients':
        $where = $search ? "AND (u.full_name LIKE ? OR u.email LIKE ?)" : "";
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.email, COUNT(a.id) as appointments_count
            FROM users u
            LEFT JOIN appointments a ON u.id = a.patient_id
            WHERE u.role = 'patient' $where
            GROUP BY u.id
            ORDER BY u.full_name
            LIMIT ? OFFSET ?");
        $params = $search ? [$search_param, $search_param, $limit, $offset] : [$limit, $offset];
        $stmt->execute($params);
        $data = $stmt->fetchAll();

        $stmt_total = $pdo->prepare("SELECT COUNT(*) FROM users u LEFT JOIN appointments a ON u.id = a.patient_id WHERE u.role = 'patient' $where");
        $stmt_total->execute($search ? [$search_param, $search_param] : []);
        $total = $stmt_total->fetchColumn();
        break;
}
$total_pages = ceil($total / $limit);

$specialties_list = $pdo->query("SELECT * FROM specialties ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <title>Админ-панель | Medprofi</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css">
</head>

<body>
    <header class="site-header">
        <div class="container header-flex">
            <div class="header-left">
                <a href="dashboard.php" class="btn-back">← В кабинет</a>
                <a href="dashboard.php" class="logo">Medprofi <span class="badge">Админ</span></a>
            </div>
            <div style="display:flex; gap:10px; align-items:center;">
                <a href="settings.php" class="btn btn-outline">Настройки</a>
                <a href="logout.php" class="btn btn-outline">Выйти</a>
            </div>
        </div>
    </header>

    <main class="admin-content">
        <!-- Вкладки (как в doctor.php) -->
        <div class="admin-tabs">
            <a href="?tab=appointments" class="admin-tab <?= ($active_tab == 'appointments' ? 'active' : '') ?>">Записи</a>
            <a href="?tab=doctors" class="admin-tab <?= ($active_tab == 'doctors' ? 'active' : '') ?>">Врачи</a>
            <a href="?tab=services" class="admin-tab <?= ($active_tab == 'services' ? 'active' : '') ?>">Услуги</a>
            <a href="?tab=specialties" class="admin-tab <?= ($active_tab == 'specialties' ? 'active' : '') ?>">Специальности</a>
            <a href="?tab=patients" class="admin-tab <?= ($active_tab == 'patients' ? 'active' : '') ?>">Пациенты</a>
        </div>

        <div class="admin-header">
            <h1 class="admin-title">
                <?php
                $titles = [
                    'appointments' => 'Записи на прием',
                    'doctors' => 'Врачи',
                    'services' => 'Услуги',
                    'specialties' => 'Специальности',
                    'patients' => 'Пациенты'
                ];
                echo $titles[$active_tab] ?? 'Админ-панель';
                ?>
            </h1>
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <form method="GET" style="display:flex; gap:8px;">
                    <input type="hidden" name="tab" value="<?= $active_tab ?>">
                    <input type="text" name="search" placeholder="Поиск по таблице..." value="<?= htmlspecialchars($search) ?>"
                        style="padding:10px 14px; border:1px solid var(--border); border-radius:8px; width:260px; font-size:0.95rem;">
                    <button type="submit" class="btn-add" style="padding:10px 14px;">Поиск</button>
                    <?php if ($search): ?>
                        <a href="?tab=<?= $active_tab ?>" style="background:#f1f5f9; color:#64748b; padding:10px 12px; border-radius:8px; text-decoration:none; border:1px solid var(--border);">✖</a>
                    <?php endif; ?>
                </form>
                <?php if ($active_tab !== 'patients' && $active_tab !== 'appointments'): ?>
                    <button class="btn-add" onclick="openModal()">+ Добавить</button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Таблицы -->
        <?php if ($active_tab === 'appointments'): ?>
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
                    <?php foreach ($data as $row): ?>
                        <tr>
                            <td><?= $row['id'] ?></td>
                            <td><?= date('d.m.Y', strtotime($row['appointment_date'])) ?></td>
                            <td><?= substr($row['start_time'], 0, 5) ?></td>
                            <td><?= htmlspecialchars($row['patient_name']) ?></td>
                            <td><?= htmlspecialchars($row['doctor_name']) ?></td>
                            <td><?= htmlspecialchars($row['service_name']) ?></td>
                            <td>
                                <select onchange="updateStatus(<?= $row['id'] ?>, this.value)">
                                    <option value="booked" <?= ($row['status'] == 'booked' ? 'selected' : '') ?>>Записан</option>
                                    <option value="completed" <?= ($row['status'] == 'completed' ? 'selected' : '') ?>>Завершен</option>
                                    <option value="cancelled" <?= ($row['status'] == 'cancelled' ? 'selected' : '') ?>>Отменен</option>
                                </select>
                            </td>
                            <td>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить?')">
                                    <input type="hidden" name="ap_id" value="<?= $row['id'] ?>">
                                    <button type="submit" name="delete_appointment" class="btn-sm btn-delete">🗑</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif ($active_tab === 'doctors'): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>ФИО</th>
                        <th>Телефон</th>
                        <th>Email</th>
                        <th>Специальность</th>
                        <th>Стаж</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $row): ?>
                        <tr>
                            <td><?= $row['id'] ?></td>
                            <td><?= htmlspecialchars($row['full_name']) ?></td>
                            <td><?= htmlspecialchars($row['phone_number']) ?></td>
                            <td><?= htmlspecialchars($row['email']) ?></td>
                            <td><?= htmlspecialchars($row['specialty_name'] ?? '-') ?></td>
                            <td><?= plural_years($row['experience_years']) ?></td>
                            <td>
                                <button class="btn-sm btn-edit" onclick='editDoctor(<?= json_encode($row) ?>)'>✏️</button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить врача?')">
                                    <input type="hidden" name="doctor_id" value="<?= $row['id'] ?>">
                                    <button type="submit" name="delete_doctor" class="btn-sm btn-delete">🗑</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif ($active_tab === 'services'): ?>
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
                    <?php foreach ($data as $row): ?>
                        <tr>
                            <td><?= $row['id'] ?></td>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td><?= $row['duration_minutes'] ?> мин</td>
                            <td><?= number_format($row['price'], 0, ',', ' ') ?> BUN</td>
                            <td><?= htmlspecialchars($row['specialty_name'] ?? 'Общая') ?></td>
                            <td>
                                <button class="btn-sm btn-edit" onclick='editService(<?= json_encode($row) ?>)'>✏️</button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить услугу?')">
                                    <input type="hidden" name="service_id" value="<?= $row['id'] ?>">
                                    <button type="submit" name="delete_service" class="btn-sm btn-delete">🗑</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif ($active_tab === 'specialties'): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Название</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $row): ?>
                        <tr>
                            <td><?= $row['id'] ?></td>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td>
                                <button class="btn-sm btn-edit" onclick='editSpecialty(<?= json_encode($row) ?>)'>✏️</button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить специальность?')">
                                    <input type="hidden" name="specialty_id" value="<?= $row['id'] ?>">
                                    <button type="submit" name="delete_specialty" class="btn-sm btn-delete">🗑</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif ($active_tab === 'patients'): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>ФИО</th>
                        <th>Email</th>
                        <th>Записей</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $row): ?>
                        <tr>
                            <td><?= $row['id'] ?></td>
                            <td><?= htmlspecialchars($row['full_name']) ?></td>
                            <td><?= htmlspecialchars($row['email']) ?></td>
                            <td><?= $row['appointments_count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Пагинация -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php
                $search_qs = $search ? '&search=' . urlencode($search) : '';
                ?>
                <?php if ($page > 1): ?>
                    <a href="?tab=<?= $active_tab ?>&page=<?= $page - 1 ?><?= $search_qs ?>" class="pg-btn">←</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?tab=<?= $active_tab ?>&page=<?= $i ?><?= $search_qs ?>" class="pg-btn <?= ($i == $page ? 'active' : '') ?>"><?= $i ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a href="?tab=<?= $active_tab ?>&page=<?= $page + 1 ?><?= $search_qs ?>" class="pg-btn">→</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- Модальные окна (без изменений) -->
    <!-- Doctor Modal -->
    <div id="doctorModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="doctorModalTitle">Добавить врача</h3>
                <button class="modal-close" onclick="closeModal('doctorModal')">×</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="doctor_id" id="doctor_id">
                <div class="form-group"><label>ФИО:</label><input type="text" name="full_name" id="doctor_full_name" required></div>
                <div class="form-group"><label>Телефон:</label><input type="tel" name="phone_number" id="doctor_phone" required></div>
                <div class="form-group"><label>Email:</label><input type="email" name="email" id="doctor_email"></div>
                <div class="form-group"><label>Пароль (оставьте пустым при редактировании):</label><input type="password" name="password" id="doctor_password"></div>
                <div class="form-group">
                    <label>Специальность:</label>
                    <select name="specialty_id" id="doctor_specialty_id">
                        <option value="">Не выбрана</option>
                        <?php foreach ($specialties_list as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Стаж (лет):</label><input type="number" name="experience_years" id="doctor_experience_years" min="0" required></div>
                <div class="form-group">
                    <label>Фото врача:</label>
                    <input type="hidden" name="existing_photo" id="doctor_existing_photo">
                    <input type="file" name="photo" id="doctor_photo_file" accept="image/*">
                    <small style="color: #64748b;">JPG, PNG, WebP (макс. 2MB)</small>
                    <div id="doctor_photo_preview" style="margin-top:5px;"></div>
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
                <div class="form-group"><label>Название:</label><input type="text" name="name" id="service_name" required></div>
                <div class="form-group"><label>Длительность (мин):</label><input type="number" name="duration_minutes" id="service_duration" min="1" required></div>
                <div class="form-group"><label>Цена:</label><input type="number" name="price" id="service_price" min="0" step="0.01" required></div>
                <div class="form-group">
                    <label>Специальность:</label>
                    <select name="specialty_id" id="service_specialty_id">
                        <option value="">Общая (для всех)</option>
                        <?php foreach ($specialties_list as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
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
                <div class="form-group"><label>Название:</label><input type="text" name="name" id="specialty_name" required></div>
                <button type="submit" name="add_specialty" class="btn-add" style="width:100%;">Сохранить</button>
            </form>
        </div>
    </div>

    <script>
        function openModal(type = '<?= $active_tab ?>') {
            if (type === 'doctors') document.getElementById('doctorModal').classList.add('active');
            else if (type === 'services') document.getElementById('serviceModal').classList.add('active');
            else if (type === 'specialties') document.getElementById('specialtyModal').classList.add('active');
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) return;
            modal.classList.remove('active');
            const form = modal.querySelector('form');
            if (form) form.reset();
            const title = modal.querySelector('h3');
            if (title && title.id.includes('ModalTitle')) {
                title.textContent = title.id.replace('ModalTitle', '').replace(/([A-Z])/g, ' $1').trim();
            }
        }

        function updateStatus(apId, status) {
            if (!confirm('Изменить статус записи?')) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            form.innerHTML = `<input type="hidden" name="ap_id" value="${apId}"><input type="hidden" name="status" value="${status}"><input type="hidden" name="update_appointment_status" value="1">`;
            document.body.appendChild(form);
            form.submit();
        }

        function editDoctor(data) {
            document.getElementById('doctorModalTitle').textContent = 'Редактировать врача';
            document.getElementById('doctor_id').value = data.id || '';
            document.getElementById('doctor_full_name').value = data.full_name || '';
            document.getElementById('doctor_phone').value = data.phone_number || '';
            document.getElementById('doctor_email').value = data.email || '';
            document.getElementById('doctor_specialty_id').value = data.specialty_id || '';
            document.getElementById('doctor_experience_years').value = data.experience_years || '';
            document.getElementById('doctor_existing_photo').value = data.photo || '';
            document.getElementById('doctor_photo_preview').innerHTML = data.photo ?
                `<img src="uploads/${data.photo}" style="height:50px; margin-top:5px;">` :
                '';
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
            document.getElementById('specialtyModal').classList.add('active');
        }
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) event.target.classList.remove('active');
        }
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') document.querySelectorAll('.modal.active').forEach(m => m.classList.remove('active'));
        });
    </script>
    <?php require 'toast.php'; ?>
</body>

</html>