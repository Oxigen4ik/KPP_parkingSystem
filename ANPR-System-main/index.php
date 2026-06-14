<?php
require_once 'auth.php';   // session_start() + хелперы ролей
require_auth();             // если не залогинен — редирект на login.php
include 'db_config.php';

// --- АВТОМАТИЧЕСКОЕ ДЕАКТИВАЦИЯ ПРОСРОЧЕННЫХ ГОСТЕВЫХ ПРОПУСКОВ ---
$conn->query("UPDATE allowed_cars SET is_active = 0 WHERE expires_at IS NOT NULL AND expires_at < NOW() AND is_active = 1");

// --- API И БЭКАП ---
if (isset($_GET['action']) && $_GET['action'] == 'make_backup') {
    $backup_name = 'backup_' . date('Y-m-d_H-i-s') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($backup_name, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        $sql_dump = "";
        $tables = ['allowed_cars', 'entry_logs'];
        foreach ($tables as $table) {
            $result = $conn->query("SELECT * FROM $table");
            $num_fields = $result->field_count;
            $sql_dump .= "DROP TABLE IF EXISTS $table;";
            $row2 = $conn->query("SHOW CREATE TABLE $table")->fetch_row();
            $sql_dump .= "\n\n" . $row2[1] . ";\n\n";
            while ($row = $result->fetch_row()) {
                $sql_dump .= "INSERT INTO $table VALUES(";
                for ($j = 0; $j < $num_fields; $j++) {
                    $row[$j] = addslashes($row[$j]);
                    $row[$j] = str_replace("\n", "\\n", $row[$j]);
                    if (isset($row[$j])) { $sql_dump .= '"' . $row[$j] . '"'; } else { $sql_dump .= '""'; }
                    if ($j < ($num_fields - 1)) { $sql_dump .= ','; }
                }
                $sql_dump .= ");\n";
            }
            $sql_dump .= "\n\n\n";
        }
        $zip->addFromString('database_dump.sql', $sql_dump);
        $rootPath = realpath(__DIR__);
        $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS),
            function ($current) use ($backup_name) { return $current->getFilename() !== $backup_name; }
        ));
        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($rootPath) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $backup_name . '"');
        header('Content-Length: ' . filesize($backup_name));
        readfile($backup_name);
        unlink($backup_name);
        exit;
    }
}

if (isset($_GET['action']) && $_GET['action'] == 'get_logs') {
    $sql = "SELECT id, plate_number, status, event_time, snapshot_path FROM entry_logs ORDER BY event_time DESC LIMIT 15";
    $result = $conn->query($sql);
    $logs = [];
    while($row = $result->fetch_assoc()) $logs[] = $row;
    header('Content-Type: application/json');
    echo json_encode($logs);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] == 'get_stats') {
    $total_cars = $conn->query("SELECT COUNT(*) as c FROM allowed_cars WHERE is_active = 1")->fetch_assoc()['c'];
    $last_log = $conn->query("SELECT event_time FROM entry_logs ORDER BY event_time DESC LIMIT 1")->fetch_assoc();
    $last_time = $last_log ? date('H:i', strtotime($last_log['event_time'])) : "--:--";
    header('Content-Type: application/json');
    echo json_encode(['total_cars' => $total_cars, 'last_activity' => $last_time]);
    exit;
}

// --- ФОРМЫ ---
$alert_msg = "";
$latin_to_rus = ['A'=>'А','B'=>'В','C'=>'С','E'=>'Е','H'=>'Н','K'=>'К','M'=>'М','O'=>'О','P'=>'Р','T'=>'Т','X'=>'Х','Y'=>'У'];

// Добавление записи (постоянной)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_plate'])) {
    $plate_number = strtoupper(trim($conn->real_escape_string($_POST['plate_number'])));
    $owner_name = trim($conn->real_escape_string($_POST['owner_name']));
    if (!empty($plate_number)) {
        $plate_number = strtr($plate_number, $latin_to_rus);
        $sql = "INSERT INTO allowed_cars (plate_number, owner_name, is_active, expires_at) VALUES ('$plate_number', '$owner_name', 1, NULL) ON DUPLICATE KEY UPDATE owner_name='$owner_name', is_active=1, expires_at=NULL";
        if ($conn->query($sql) === TRUE) $alert_msg = "success|Номер $plate_number добавлен!";
        else $alert_msg = "danger|Ошибка БД";
    }
}

// Добавление ГОСТЕВОГО пропуска
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_guest'])) {
    $plate_number = strtoupper(trim($conn->real_escape_string($_POST['plate_number'])));
    $owner_name = trim($conn->real_escape_string($_POST['owner_name']));
    $hours = intval($_POST['guest_hours']); // 12 или 24
    if (!empty($plate_number) && in_array($hours, [12, 24])) {
        $plate_number = strtr($plate_number, $latin_to_rus);
        $expires_at = date('Y-m-d H:i:s', strtotime("+$hours hours"));
        $sql = "INSERT INTO allowed_cars (plate_number, owner_name, is_active, expires_at) VALUES ('$plate_number', '$owner_name', 1, '$expires_at') ON DUPLICATE KEY UPDATE owner_name='$owner_name', is_active=1, expires_at='$expires_at'";
        if ($conn->query($sql) === TRUE) $alert_msg = "success|Гостевой пропуск для $plate_number выдан на $hours ч. (до " . date('d.m H:i', strtotime($expires_at)) . ")";
        else $alert_msg = "danger|Ошибка БД";
    }
}

// Редактирование записи
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_plate'])) {
    $id = intval($_POST['id']);
    $plate_number = strtoupper(trim($conn->real_escape_string($_POST['plate_number'])));
    $owner_name = trim($conn->real_escape_string($_POST['owner_name']));
    if (!empty($plate_number) && $id > 0) {
        $plate_number = strtr($plate_number, $latin_to_rus);
        $sql = "UPDATE allowed_cars SET plate_number='$plate_number', owner_name='$owner_name' WHERE id=$id";
        if ($conn->query($sql) === TRUE) $alert_msg = "success|Запись обновлена!";
        else $alert_msg = "danger|Ошибка БД";
    }
}

// Удаление записи
if (isset($_GET['delete_id'])) {
    $conn->query("DELETE FROM allowed_cars WHERE id=".intval($_GET['delete_id']));
    header("Location: index.php"); exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Информационная система КПП</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=Golos+Text:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<nav class="navbar">
    <div class="brand">
        <img src="favicon.ico" alt="Логотип" style="height: 36px; width: auto;">
        <span>Информационная система КПП</span>
    </div>
    <div class="navbar-actions">
        <?php if (is_admin()): ?>
            <a href="?action=make_backup" class="btn-minimal warning"><i class="fas fa-download"></i> Бэкап</a>
            <a href="users.php" class="btn-minimal"><i class="fas fa-users"></i> Пользователи</a>
        <?php endif; ?>
        <a href="analytics.php" class="btn-minimal"><i class="fas fa-chart-line"></i> Аналитика</a>
        <div class="time-display">
            <div class="clock" id="clock">--:--:--</div>
            <div class="date" id="date">—</div>
        </div>
        <!-- Плашка пользователя -->
        <div style="display:flex; align-items:center; gap:8px; padding: 4px 12px; border-radius:8px; border:1px solid var(--border); background:var(--panel-raised);">
            <i class="fas fa-<?= is_admin() ? 'crown' : 'shield' ?>" style="font-size:0.75rem; color: <?= is_admin() ? '#388bfd' : 'var(--accent)' ?>;"></i>
            <span style="font-family:var(--mono); font-size:0.75rem; color:var(--text-bright);">
                <?= htmlspecialchars($_SESSION['username']) ?>
            </span>
            <span style="font-family:var(--mono); font-size:0.62rem; color:var(--text-dim); text-transform:uppercase; letter-spacing:0.06em;">
                <?= is_admin() ? 'admin' : 'guard' ?>
            </span>
        </div>
        <!-- Кнопка тревоги -->
        <button id="emergencyBtn"
                onclick="triggerEmergency()"
                title="Экстренная тревога"
                style="
                    display:inline-flex; align-items:center; gap:7px;
                    padding:5px 14px; border-radius:8px; cursor:pointer;
                    font-family:var(--mono); font-size:0.75rem; font-weight:700;
                    letter-spacing:0.06em; text-transform:uppercase;
                    border:1px solid rgba(248,81,73,0.5);
                    background:rgba(248,81,73,0.12); color:#f85149;
                    transition:all 0.18s;
                "
                onmouseover="this.style.background='rgba(248,81,73,0.25)'"
                onmouseout="this.style.background='rgba(248,81,73,0.12)'">
            <i class="fas fa-triangle-exclamation"></i> ТРЕВОГА
        </button>
        <a href="logout.php" class="btn-minimal" title="Выйти"><i class="fas fa-right-from-bracket"></i></a>
        <button class="btn-minimal" onclick="location.reload()"><i class="fas fa-redo"></i></button>
    </div>
</nav>
<style>
/* Состояние активной тревоги */
.emergency-active {
    animation: emergencyPulse 0.6s ease-in-out infinite !important;
    background: rgba(248,81,73,0.35) !important;
    border-color: #f85149 !important;
    box-shadow: 0 0 20px rgba(248,81,73,0.4) !important;
}
@keyframes emergencyPulse {
    0%,100% { opacity:1; transform:scale(1); }
    50%      { opacity:0.7; transform:scale(0.97); }
}
</style>

<div class="main">
    <?php if ($alert_msg): 
        $parts = explode('|', $alert_msg); 
        $alertClass = $parts[0] === 'success' ? 'success' : 'danger';
    ?>
        <div class="alert-minimal <?= $alertClass ?>">
            <i class="fas fa-<?= $alertClass==='success'?'check-circle':'exclamation-circle' ?>"></i>
            <span><?= htmlspecialchars($parts[1]) ?></span>
            <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><span><i class="fas fa-clock"></i> События</span></div>
        <div class="card-body"><div class="scroll-area" id="logs-container"></div></div>
    </div>

    <div class="d-flex flex-column">
        <div class="card video-card">
            <div class="video-wrapper">
                <img src="http://localhost:5000/video_feed" onerror="this.src='https://placehold.co/1280x720/111/444?text=Нет+сигнала'">
                <div class="video-overlay">
                    <span class="live-indicator">LIVE</span>
                    <span class="text-dim" id="camera-status">● Онлайн</span>
                </div>
            </div>
        </div>
        <div class="stats-bar">
            <div class="stat-item"><div class="stat-value text-accent" id="stat-total">—</div><div class="stat-label">В базе</div></div>
            <div class="stat-item"><div class="stat-value text-success" id="stat-last">—</div><div class="stat-label">Посл. въезд</div></div>
            <div class="stat-item action" id="openBarrierBtn" onclick="openBarrier()" title="Открыть шлагбаум вручную">
                <div class="stat-value"><i class="fas fa-arrow-up"></i></div>
                <div class="stat-label">Открыть</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span><i class="fas fa-list-check"></i> Доступ</span>
            <button class="add-btn" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="fas fa-plus"></i>
            </button>
        </div>
        <div class="card-body">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" class="form-control" placeholder="Поиск номера или ФИО...">
            </div>
            <div class="scroll-area">
                <table class="plate-table">
                    <tbody id="platesTableBody">
                        <?php
                        $res = $conn->query("SELECT * FROM allowed_cars ORDER BY id DESC");
                        while($res && $r = $res->fetch_assoc()):
                            $is_guest = !empty($r['expires_at']);
                            $is_expired = $is_guest && strtotime($r['expires_at']) < time();
                            // Вычисляем оставшееся время для гостевых
                            $time_left = '';
                            if ($is_guest && !$is_expired) {
                                $diff = strtotime($r['expires_at']) - time();
                                $h = floor($diff / 3600);
                                $m = floor(($diff % 3600) / 60);
                                $time_left = "ещё {$h}ч {$m}м";
                            }
                        ?>
                            <tr style="<?= $is_expired ? 'opacity:0.45;' : '' ?>">
                                <td>
                                    <div style="display:flex; align-items:center; gap:6px;">
                                        <?php if ($is_guest): ?>
                                            <span title="Гостевой пропуск" style="color:#ffb400; font-size:12px;">
                                                <i class="fas fa-user-clock"></i>
                                            </span>
                                        <?php endif; ?>
                                        <div class="plate-number"><?= htmlspecialchars($r['plate_number']) ?></div>
                                    </div>
                                    <div class="plate-owner"><?= htmlspecialchars($r['owner_name']) ?></div>
                                    <?php if ($is_guest): ?>
                                        <div style="font-size:11px; margin-top:2px; color: <?= $is_expired ? '#ff4d4d' : '#ffb400' ?>;">
                                            <?= $is_expired 
                                                ? '<i class="fas fa-times-circle"></i> Истёк ' . date('d.m H:i', strtotime($r['expires_at']))
                                                : '<i class="fas fa-hourglass-half"></i> До ' . date('d.m H:i', strtotime($r['expires_at'])) . ' (' . $time_left . ')' ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="plate-action">
                                    <button type="button" class="btn-icon text-accent" 
                                            onclick="openEditModal(<?= $r['id'] ?>, '<?= htmlspecialchars($r['plate_number']) ?>', '<?= htmlspecialchars($r['owner_name']) ?>')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="?delete_id=<?= $r['id'] ?>" class="btn-icon" onclick="return confirm('Удалить?')"><i class="fas fa-trash-alt"></i></a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно: Добавить автомобиль (постоянный + гостевой в одном) -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content" id="addCarForm">
            <!-- Скрытое поле — какую кнопку нажали -->
            <input type="hidden" name="add_plate" id="form_action_permanent" value="">
            <input type="hidden" name="add_guest"  id="form_action_guest"    value="">

            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-car me-2"></i>Добавить автомобиль</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <!-- Гос. номер -->
                <div class="mb-3">
                    <label class="form-label">Гос. номер</label>
                    <input type="text" name="plate_number" id="add_plate_input"
                           class="form-control" placeholder="А123ВС77" required
                           autocomplete="off">
                    <div style="font-size:11px; color:#666; margin-top:4px;">
                        Латиница автоматически заменяется на кириллицу
                    </div>
                </div>

                <!-- Владелец -->
                <div class="mb-3">
                    <label class="form-label">Владелец</label>
                    <input type="text" name="owner_name" class="form-control" placeholder="Иванов И.И.">
                </div>

                <!-- Переключатель тип пропуска -->
                <div class="mb-3">
                    <label class="form-label">Тип пропуска</label>
                    <div class="d-flex gap-2">
                        <label class="guest-duration-btn">
                            <input type="radio" name="pass_type" value="permanent" checked style="display:none;">
                            <span><i class="fas fa-infinity"></i> Постоянный</span>
                        </label>
                        <label class="guest-duration-btn">
                            <input type="radio" name="pass_type" value="guest" style="display:none;">
                            <span><i class="fas fa-user-clock"></i> Гостевой</span>
                        </label>
                    </div>
                </div>

                <!-- Блок срока — показывается только для гостевого -->
                <div id="guest_duration_block" style="display:none;">
                    <div class="mb-3">
                        <label class="form-label">Срок действия</label>
                        <div class="d-flex gap-2">
                            <label class="guest-duration-btn">
                                <input type="radio" name="guest_hours" value="12" checked style="display:none;">
                                <span><i class="fas fa-clock"></i> 12 часов</span>
                            </label>
                            <label class="guest-duration-btn">
                                <input type="radio" name="guest_hours" value="24" style="display:none;">
                                <span><i class="fas fa-calendar-day"></i> 24 часа</span>
                            </label>
                        </div>
                    </div>
                    <div style="background: rgba(255,180,0,0.08); border: 1px solid rgba(255,180,0,0.2); border-radius: 8px; padding: 10px 14px; font-size: 13px; color: #aaa;">
                        <i class="fas fa-info-circle" style="color:#ffb400;"></i>
                        Пропуск истечёт: <strong id="guest-expires-preview" style="color:#ffb400;">—</strong>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="submit" id="addSubmitBtn" class="btn-save">
                    <i class="fas fa-save me-1"></i>Сохранить
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Модальное окно: Редактирование -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content">
            <input type="hidden" name="id" id="edit_id">
            <div class="modal-header">
                <h5 class="modal-title">Редактировать запись</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Гос. номер</label>
                    <input type="text" name="plate_number" id="edit_plate_number" class="form-control text-uppercase" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Владелец</label>
                    <input type="text" name="owner_name" id="edit_owner_name" class="form-control">
                </div>
            </div>
            <div class="modal-footer"><button type="submit" name="edit_plate" class="btn-save">Обновить</button></div>
        </form>
    </div>
</div>

<!-- Модальное окно: Lightbox -->
<div class="modal fade" id="lightboxModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content lightbox-content">
            <button type="button" class="btn-close btn-close-white lightbox-close" data-bs-dismiss="modal"></button>
            <img id="lightboxImage" src="" alt="Snapshot" class="img-fluid rounded">
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="script.js"></script>
<script>
// ===== АВТО-КОНВЕРТАЦИЯ НОМЕРА: латиница → кириллица + заглавные =====
const latinToRus = {
    'A':'А','B':'В','C':'С','E':'Е','H':'Н','K':'К',
    'M':'М','O':'О','P':'Р','T':'Т','X':'Х','Y':'У'
};

function convertPlateInput(input) {
    const pos = input.selectionStart;
    let val = input.value.toUpperCase();
    val = val.split('').map(ch => latinToRus[ch] ?? ch).join('');
    input.value = val;
    // Восстанавливаем позицию курсора
    input.setSelectionRange(pos, pos);
}

// Применяем ко всем полям номера на странице
document.querySelectorAll('#add_plate_input, #edit_plate_number, input[name="plate_number"]')
    .forEach(el => el.addEventListener('input', () => convertPlateInput(el)));

// ===== ОБЪЕДИНЁННАЯ МОДАЛКА: переключение тип пропуска =====
function updatePassType() {
    const isGuest = document.querySelector('input[name="pass_type"]:checked')?.value === 'guest';
    const block = document.getElementById('guest_duration_block');
    const btn   = document.getElementById('addSubmitBtn');

    if (!block || !btn) return;

    if (isGuest) {
        block.style.display = 'block';
        btn.style.background = 'rgba(255,180,0,0.2)';
        btn.style.color      = '#ffb400';
        btn.style.border     = '1px solid rgba(255,180,0,0.4)';
        btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Выдать гостевой пропуск';
        updateGuestPreview();
    } else {
        block.style.display = 'none';
        btn.style.background = '';
        btn.style.color      = '';
        btn.style.border     = '';
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Сохранить';
    }
}

function updateGuestPreview() {
    const radio = document.querySelector('input[name="guest_hours"]:checked');
    if (!radio) return;
    const hours   = parseInt(radio.value);
    const expires = new Date(Date.now() + hours * 3600 * 1000);
    document.getElementById('guest-expires-preview').textContent =
        expires.toLocaleString('ru-RU', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' });
}

// Слушатели переключателей
document.querySelectorAll('input[name="pass_type"]')
    .forEach(r => r.addEventListener('change', updatePassType));
document.querySelectorAll('input[name="guest_hours"]')
    .forEach(r => r.addEventListener('change', updateGuestPreview));

// Сброс модалки при открытии
document.getElementById('addModal').addEventListener('show.bs.modal', () => {
    document.querySelector('input[name="pass_type"][value="permanent"]').checked = true;
    document.querySelector('input[name="guest_hours"][value="12"]').checked = true;
    document.getElementById('add_plate_input').value = '';
    updatePassType();
});

// При сабмите — ставим нужный hidden name
document.getElementById('addCarForm').addEventListener('submit', function() {
    const isGuest = document.querySelector('input[name="pass_type"]:checked')?.value === 'guest';
    // PHP смотрит на isset($_POST['add_plate']) или isset($_POST['add_guest'])
    // Поэтому просто ставим значение нужному hidden полю
    if (isGuest) {
        document.getElementById('form_action_guest').name    = 'add_guest';
        document.getElementById('form_action_permanent').name = '_unused';
        // guest_hours уже есть в форме
    } else {
        document.getElementById('form_action_permanent').name = 'add_plate';
        document.getElementById('form_action_guest').name     = '_unused';
    }
});
</script>
</body>
</html>