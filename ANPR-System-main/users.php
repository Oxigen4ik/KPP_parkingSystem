<?php
session_start();
include 'db_config.php';

// Только для админа
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php"); exit;
}

$alert_msg = "";

// Удалить пользователя
if (isset($_GET['delete_id'])) {
    $id = intval($_GET['delete_id']);
    if ($id !== $_SESSION['user_id']) { // нельзя удалить себя
        $conn->query("DELETE FROM users WHERE id=$id");
    }
    header("Location: users.php"); exit;
}

// Переключить активность
if (isset($_GET['toggle_id'])) {
    $id = intval($_GET['toggle_id']);
    if ($id !== $_SESSION['user_id']) {
        $conn->query("UPDATE users SET is_active = 1 - is_active WHERE id=$id");
    }
    header("Location: users.php"); exit;
}

// Добавить пользователя
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $username  = trim($conn->real_escape_string($_POST['username']));
    $full_name = trim($conn->real_escape_string($_POST['full_name']));
    $role      = $_POST['role'] === 'admin' ? 'admin' : 'guard';
    $password  = $_POST['password'];

    if (strlen($password) < 4) {
        $alert_msg = "danger|Пароль должен быть не менее 4 символов";
    } elseif (!empty($username)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $sql  = "INSERT INTO users (username, password_hash, role, full_name) VALUES ('$username', '$hash', '$role', '$full_name')";
        if ($conn->query($sql)) $alert_msg = "success|Пользователь $username создан";
        else $alert_msg = "danger|Логин уже занят или ошибка БД";
    }
}

// Изменить пароль
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $id       = intval($_POST['id']);
    $password = $_POST['new_password'];
    if (strlen($password) < 4) {
        $alert_msg = "danger|Пароль должен быть не менее 4 символов";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $conn->query("UPDATE users SET password_hash='$hash' WHERE id=$id");
        $alert_msg = "success|Пароль обновлён";
    }
}

// Список пользователей
$users = [];
$res = $conn->query("SELECT id, username, full_name, role, is_active, created_at, last_login FROM users ORDER BY role ASC, id ASC");
while ($r = $res->fetch_assoc()) $users[] = $r;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление пользователями — КПП</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=Golos+Text:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        body { overflow: auto; height: auto; background-attachment: fixed; }

        .users-page {
            max-width: 860px;
            margin: 0 auto;
            padding: 24px 20px 48px;
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }

        .page-header h1 {
            font-family: var(--mono);
            font-size: 0.85rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--text-bright);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-header h1 i { color: var(--accent); }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 14px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--text-dim);
            font-family: var(--mono);
            font-size: 0.75rem;
            text-decoration: none;
            transition: all 0.18s;
        }

        .back-btn:hover {
            border-color: var(--accent);
            color: var(--accent);
            background: var(--accent-dim);
        }

        /* Таблица пользователей */
        .users-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 24px;
        }

        .users-table th {
            padding: 11px 16px;
            background: var(--panel-raised);
            border-bottom: 1px solid var(--border);
            font-family: var(--mono);
            font-size: 0.65rem;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--text-dim);
            text-align: left;
        }

        .users-table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
            font-size: 0.9rem;
        }

        .users-table tr:last-child td { border-bottom: none; }
        .users-table tr:hover td { background: var(--panel-raised); }

        .role-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 4px;
            font-family: var(--mono);
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        .role-badge.admin {
            background: rgba(56,139,253,0.12);
            border: 1px solid rgba(56,139,253,0.25);
            color: var(--blue);
        }

        .role-badge.guard {
            background: rgba(0,224,122,0.1);
            border: 1px solid rgba(0,224,122,0.2);
            color: var(--accent);
        }

        .status-dot {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: var(--mono);
            font-size: 0.75rem;
        }

        .status-dot::before {
            content: '';
            width: 6px; height: 6px;
            border-radius: 50%;
        }

        .status-dot.active::before  { background: var(--accent); box-shadow: 0 0 6px rgba(0,224,122,0.5); }
        .status-dot.inactive::before { background: var(--text-dim); }
        .status-dot.active  { color: var(--accent); }
        .status-dot.inactive { color: var(--text-dim); }

        .user-meta { font-family: var(--mono); font-size: 0.72rem; color: var(--text-dim); margin-top: 2px; }
        .user-name { font-weight: 600; color: var(--text-bright); }
        .me-tag {
            font-family: var(--mono);
            font-size: 0.6rem;
            padding: 1px 6px;
            border-radius: 3px;
            background: rgba(227,179,65,0.12);
            border: 1px solid rgba(227,179,65,0.25);
            color: var(--warning);
            margin-left: 6px;
            letter-spacing: 0.06em;
        }

        /* Карточка добавления */
        .add-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        .add-card-header {
            padding: 12px 18px;
            background: var(--panel-raised);
            border-bottom: 1px solid var(--border);
            font-family: var(--mono);
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--text-dim);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .add-card-header i { color: var(--accent); }
        .add-card-body { padding: 20px; }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        @media (max-width: 600px) {
            .form-row { grid-template-columns: 1fr; }
        }

        .role-select {
            width: 100%;
            background: var(--bg);
            border: 1px solid var(--border);
            color: var(--text-bright);
            padding: 9px 13px;
            border-radius: 9px;
            font-family: var(--mono);
            font-size: 0.85rem;
            outline: none;
            cursor: pointer;
            transition: all 0.18s;
        }

        .role-select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(0,224,122,0.1);
        }

        /* Alert */
        .alert-box {
            border-radius: 9px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-family: var(--mono);
            font-size: 0.8rem;
            border-left: 3px solid;
        }

        .alert-box.success {
            background: rgba(0,224,122,0.07);
            border-left-color: var(--accent);
            color: var(--accent);
        }

        .alert-box.danger {
            background: rgba(248,81,73,0.07);
            border-left-color: var(--danger);
            color: var(--danger);
        }

        .alert-box span { color: var(--text); }
    </style>
</head>
<body>

<nav class="navbar">
    <div class="brand">
        <img src="favicon.ico" alt="" style="height:28px; filter: brightness(0) invert(1) sepia(1) saturate(3) hue-rotate(100deg); opacity:.85;">
        <span>Управление пользователями</span>
    </div>
    <div class="navbar-actions">
        <span style="font-family:var(--mono);font-size:0.75rem;color:var(--text-dim);">
            <i class="fas fa-user-shield" style="color:var(--blue);margin-right:5px;"></i>
            <?= htmlspecialchars($_SESSION['full_name'] ?: $_SESSION['username']) ?>
        </span>
        <a href="index.php" class="btn-minimal"><i class="fas fa-arrow-left"></i> На главную</a>
        <a href="logout.php" class="btn-minimal"><i class="fas fa-sign-out-alt"></i></a>
    </div>
</nav>

<div class="users-page">

    <?php if ($alert_msg):
        $parts = explode('|', $alert_msg);
    ?>
    <div class="alert-box <?= $parts[0] ?>">
        <i class="fas fa-<?= $parts[0]==='success'?'check-circle':'exclamation-circle' ?>"></i>
        <span><?= htmlspecialchars($parts[1]) ?></span>
    </div>
    <?php endif; ?>

    <!-- Таблица пользователей -->
    <table class="users-table">
        <thead>
            <tr>
                <th>Пользователь</th>
                <th>Роль</th>
                <th>Статус</th>
                <th>Последний вход</th>
                <th style="text-align:right;">Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): $is_me = $u['id'] == $_SESSION['user_id']; ?>
            <tr>
                <td>
                    <div class="user-name">
                        <?= htmlspecialchars($u['username']) ?>
                        <?php if ($is_me): ?><span class="me-tag">ВЫ</span><?php endif; ?>
                    </div>
                    <div class="user-meta"><?= htmlspecialchars($u['full_name'] ?: '—') ?></div>
                </td>
                <td>
                    <span class="role-badge <?= $u['role'] ?>">
                        <i class="fas fa-<?= $u['role']==='admin'?'user-shield':'user' ?>"></i>
                        <?= $u['role'] === 'admin' ? 'Админ' : 'Охранник' ?>
                    </span>
                </td>
                <td>
                    <span class="status-dot <?= $u['is_active']?'active':'inactive' ?>">
                        <?= $u['is_active'] ? 'Активен' : 'Заблокирован' ?>
                    </span>
                </td>
                <td style="font-family:var(--mono);font-size:0.75rem;color:var(--text-dim);">
                    <?= $u['last_login'] ? date('d.m.Y H:i', strtotime($u['last_login'])) : '—' ?>
                </td>
                <td style="text-align:right;white-space:nowrap;">
                    <?php if (!$is_me): ?>
                        <!-- Смена пароля -->
                        <button class="btn-icon text-accent"
                                onclick="openPassModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>')"
                                title="Сменить пароль">
                            <i class="fas fa-key"></i>
                        </button>
                        <!-- Блок/разблок -->
                        <a href="?toggle_id=<?= $u['id'] ?>"
                           class="btn-icon"
                           style="color:<?= $u['is_active']?'var(--warning)':'var(--accent)' ?>"
                           title="<?= $u['is_active']?'Заблокировать':'Разблокировать' ?>"
                           onclick="return confirm('<?= $u['is_active']?'Заблокировать':'Разблокировать' ?> пользователя?')">
                            <i class="fas fa-<?= $u['is_active']?'ban':'unlock' ?>"></i>
                        </a>
                        <!-- Удалить -->
                        <a href="?delete_id=<?= $u['id'] ?>"
                           class="btn-icon"
                           title="Удалить"
                           onclick="return confirm('Удалить пользователя <?= htmlspecialchars($u['username']) ?>?')">
                            <i class="fas fa-trash-alt"></i>
                        </a>
                    <?php else: ?>
                        <span style="font-family:var(--mono);font-size:0.65rem;color:var(--text-dim);padding:0 8px;">нельзя изменить себя</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Добавить пользователя -->
    <div class="add-card">
        <div class="add-card-header">
            <i class="fas fa-user-plus"></i>
            Добавить пользователя
        </div>
        <div class="add-card-body">
            <form method="POST">
                <div class="form-row mb-3">
                    <div>
                        <label class="form-label">Логин</label>
                        <input type="text" name="username" class="form-control" placeholder="ivanov_g" required autocomplete="off">
                    </div>
                    <div>
                        <label class="form-label">ФИО</label>
                        <input type="text" name="full_name" class="form-control" placeholder="Иванов И.И.">
                    </div>
                </div>
                <div class="form-row mb-3">
                    <div>
                        <label class="form-label">Пароль</label>
                        <input type="password" name="password" class="form-control" placeholder="Минимум 4 символа" required autocomplete="new-password">
                    </div>
                    <div>
                        <label class="form-label">Роль</label>
                        <select name="role" class="role-select">
                            <option value="guard">Охранник</option>
                            <option value="admin">Администратор</option>
                        </select>
                    </div>
                </div>
                <button type="submit" name="add_user" class="btn-save" style="max-width:220px;">
                    <i class="fas fa-user-plus"></i> Создать пользователя
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Модальное окно: Смена пароля -->
<div class="modal fade" id="passModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:360px;">
        <form method="POST" class="modal-content">
            <input type="hidden" name="id" id="pass_user_id">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-key me-2" style="color:var(--accent)"></i>Смена пароля</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p style="font-family:var(--mono);font-size:0.75rem;color:var(--text-dim);margin-bottom:14px;">
                    Пользователь: <strong id="pass_username" style="color:var(--text-bright);"></strong>
                </p>
                <div class="mb-3">
                    <label class="form-label">Новый пароль</label>
                    <input type="password" name="new_password" class="form-control" placeholder="Минимум 4 символа" required autocomplete="new-password">
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="change_password" class="btn-save">
                    <i class="fas fa-save me-1"></i>Сохранить пароль
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openPassModal(id, username) {
    document.getElementById('pass_user_id').value = id;
    document.getElementById('pass_username').textContent = username;
    new bootstrap.Modal(document.getElementById('passModal')).show();
}
</script>
</body>
</html>