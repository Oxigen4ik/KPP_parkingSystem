<?php
require_once 'auth.php';
require_admin();
require_once 'db_config.php';

$alert = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $uname = trim($conn->real_escape_string($_POST['new_username']));
    $fname = trim($conn->real_escape_string($_POST['new_fullname']));
    $role  = $_POST['new_role'] === 'admin' ? 'admin' : 'guard';
    $pass  = $_POST['new_password'];
    if (strlen($uname) >= 3 && strlen($pass) >= 4) {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO users (username,password,role,full_name) VALUES (?,?,?,?)");
        $stmt->bind_param('ssss', $uname, $hash, $role, $fname);
        $alert = $stmt->execute() ? "success|Пользователь «$uname» создан" : "danger|Логин уже занят";
    } else { $alert = "danger|Логин ≥ 3 символа, пароль ≥ 4 символа"; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_pass'])) {
    $uid = intval($_POST['uid']); $pass = $_POST['new_pass'];
    if ($uid > 0 && strlen($pass) >= 4) {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        $stmt->bind_param('si', $hash, $uid); $stmt->execute();
        $alert = "success|Пароль обновлён";
    } else { $alert = "danger|Пароль слишком короткий (мин. 4 символа)"; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_role'])) {
    $uid = intval($_POST['uid']); $role = $_POST['new_role'] === 'admin' ? 'admin' : 'guard';
    if ($uid > 0 && $uid !== (int)$_SESSION['user_id']) {
        $stmt = $conn->prepare("UPDATE users SET role=? WHERE id=?");
        $stmt->bind_param('si', $role, $uid); $stmt->execute();
        $alert = "success|Роль изменена";
    } else { $alert = "danger|Нельзя изменить свою собственную роль"; }
}

if (isset($_GET['toggle_id'])) {
    $uid = intval($_GET['toggle_id']);
    if ($uid !== (int)$_SESSION['user_id']) $conn->query("UPDATE users SET is_active=1-is_active WHERE id=$uid");
    header('Location: users.php'); exit;
}

if (isset($_GET['delete_id'])) {
    $uid = intval($_GET['delete_id']);
    if ($uid !== (int)$_SESSION['user_id']) $conn->query("DELETE FROM users WHERE id=$uid");
    header('Location: users.php'); exit;
}

$users = $conn->query("SELECT * FROM users ORDER BY role ASC, id ASC");
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Пользователи — КПП</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=Golos+Text:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="style.css">
<style>
.users-wrap{max-width:860px;margin:0 auto;padding:24px;overflow-y:auto;height:calc(100vh - 52px)}
.page-title{font-family:var(--mono);font-size:.72rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim);margin-bottom:20px;display:flex;align-items:center;gap:8px}
.page-title i{color:var(--accent)}
.section-card{background:var(--panel);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:20px}
.section-head{padding:12px 18px;border-bottom:1px solid var(--border);background:var(--panel-raised);font-family:var(--mono);font-size:.72rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--text-dim);display:flex;align-items:center;gap:8px}
.section-head i{color:var(--accent)}
.users-table{width:100%;border-collapse:collapse}
.users-table td,.users-table th{padding:11px 16px;border-bottom:1px solid var(--border);vertical-align:middle;font-size:.88rem}
.users-table th{font-family:var(--mono);font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--text-dim);background:var(--panel-raised)}
.users-table tr:last-child td{border-bottom:none}
.users-table tr{transition:background .15s}
.users-table tr:hover td{background:var(--panel-raised)}
.uname{font-family:var(--mono);font-weight:600;color:var(--text-bright)}
.ufull{font-size:.78rem;color:var(--text-dim);margin-top:2px}
.role-badge{display:inline-flex;align-items:center;gap:5px;padding:2px 10px;border-radius:4px;font-family:var(--mono);font-size:.65rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
.role-admin{background:rgba(56,139,253,.12);color:#388bfd;border:1px solid rgba(56,139,253,.2)}
.role-guard{background:rgba(0,224,122,.10);color:var(--accent);border:1px solid rgba(0,224,122,.2)}
.status-dot{width:7px;height:7px;border-radius:50%;display:inline-block}
.dot-active{background:var(--accent);box-shadow:0 0 6px rgba(0,224,122,.5)}
.dot-inactive{background:var(--text-dim)}
.last-login{font-family:var(--mono);font-size:.72rem;color:var(--text-dim)}
.td-actions{text-align:right;white-space:nowrap}
.add-form{padding:18px;display:grid;grid-template-columns:1fr 1fr 1fr auto auto;gap:10px;align-items:end}
.self-badge{font-family:var(--mono);font-size:.65rem;color:var(--text-dim);padding:2px 8px;border:1px solid var(--border);border-radius:4px}
.edit-section{margin-top:14px;padding-top:14px;border-top:1px solid var(--border)}
.edit-section-title{font-family:var(--mono);font-size:.68rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--text-dim);margin-bottom:10px}
.input-row{display:flex;gap:8px;align-items:center}
select.form-control{appearance:auto}
</style>
</head>
<body>
<nav class="navbar">
    <div class="brand">
        <img src="favicon.ico" alt="" style="height:28px;width:auto;">
        <span>Информационная система КПП</span>
    </div>
    <div class="navbar-actions">
        <a href="index.php" class="btn-minimal"><i class="fas fa-arrow-left"></i> Назад</a>
        <div style="display:flex;align-items:center;gap:8px;padding:4px 12px;border-radius:8px;border:1px solid var(--border);background:var(--panel-raised);">
            <i class="fas fa-crown" style="font-size:.75rem;color:#388bfd;"></i>
            <span style="font-family:var(--mono);font-size:.75rem;color:var(--text-bright);"><?= htmlspecialchars($_SESSION['username']) ?></span>
            <span style="font-family:var(--mono);font-size:.62rem;color:var(--text-dim);text-transform:uppercase;">admin</span>
        </div>
        <a href="logout.php" class="btn-minimal"><i class="fas fa-right-from-bracket"></i></a>
    </div>
</nav>

<div class="users-wrap">
<?php if ($alert): $p=explode('|',$alert); $cls=$p[0]==='success'?'success':'danger'; ?>
    <div class="alert-minimal <?=$cls?>" style="position:static;margin-bottom:16px;max-width:100%;animation:none;">
        <i class="fas fa-<?=$cls==='success'?'check-circle':'exclamation-circle'?>"></i>
        <span><?= htmlspecialchars($p[1]) ?></span>
    </div>
<?php endif; ?>

    <div class="page-title"><i class="fas fa-users"></i> Управление пользователями системы</div>

    <div class="section-card">
        <div class="section-head"><i class="fas fa-list"></i> Список сотрудников</div>
        <table class="users-table">
            <thead><tr><th>Пользователь</th><th>Роль</th><th>Статус</th><th>Последний вход</th><th></th></tr></thead>
            <tbody>
            <?php while ($u = $users->fetch_assoc()):
                $isSelf = ($u['id'] == $_SESSION['user_id']); ?>
            <tr>
                <td>
                    <div class="uname"><?= htmlspecialchars($u['username']) ?>
                        <?php if ($isSelf): ?> <span class="self-badge">вы</span><?php endif; ?>
                    </div>
                    <div class="ufull"><?= htmlspecialchars($u['full_name']) ?></div>
                </td>
                <td>
                    <span class="role-badge <?= $u['role']==='admin'?'role-admin':'role-guard' ?>">
                        <i class="fas fa-<?= $u['role']==='admin'?'crown':'shield' ?>"></i>
                        <?= $u['role']==='admin'?'Администратор':'Охранник' ?>
                    </span>
                </td>
                <td>
                    <span class="status-dot <?= $u['is_active']?'dot-active':'dot-inactive' ?>"></span>
                    <span style="font-family:var(--mono);font-size:.72rem;color:var(--text-dim);margin-left:6px;"><?= $u['is_active']?'Активен':'Отключён' ?></span>
                </td>
                <td class="last-login"><?= $u['last_login'] ? date('d.m.y H:i',strtotime($u['last_login'])) : '—' ?></td>
                <td class="td-actions">
                    <?php if (!$isSelf): ?>
                        <button class="btn-icon text-accent" onclick="openEdit(<?=$u['id']?>,'<?=htmlspecialchars($u['username'])?>','<?=htmlspecialchars($u['full_name'])?>','<?=$u['role']?>')"><i class="fas fa-edit"></i></button>
                        <a href="?toggle_id=<?=$u['id']?>" class="btn-icon" style="color:<?=$u['is_active']?'var(--warning)':'var(--accent)'?>;"><i class="fas fa-<?=$u['is_active']?'user-slash':'user-check'?>"></i></a>
                        <a href="?delete_id=<?=$u['id']?>" class="btn-icon" onclick="return confirm('Удалить пользователя?')"><i class="fas fa-trash-alt"></i></a>
                    <?php else: ?><span class="self-badge">нельзя изменить</span><?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <div class="section-card">
        <div class="section-head"><i class="fas fa-user-plus"></i> Добавить сотрудника</div>
        <form method="POST" class="add-form">
            <div><label class="form-label">Логин</label><input type="text" name="new_username" class="form-control" placeholder="ivanov" required minlength="3"></div>
            <div><label class="form-label">ФИО</label><input type="text" name="new_fullname" class="form-control" placeholder="Иванов И.И."></div>
            <div><label class="form-label">Пароль</label><input type="password" name="new_password" class="form-control" placeholder="мин. 4 символа" required minlength="4"></div>
            <div><label class="form-label">Роль</label>
                <select name="new_role" class="form-control"><option value="guard">Охранник</option><option value="admin">Администратор</option></select>
            </div>
            <div style="padding-bottom:1px;"><button type="submit" name="add_user" class="btn-save" style="white-space:nowrap;"><i class="fas fa-plus"></i> Добавить</button></div>
        </form>
    </div>
</div>

<!-- Модалка редактирования -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-pen me-2"></i>Редактировать: <span id="editModalName"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="edit-section-title">Новый пароль</div>
                <form method="POST">
                    <input type="hidden" name="uid" id="editUid1">
                    <div class="input-row">
                        <input type="password" name="new_pass" class="form-control" placeholder="Новый пароль" minlength="4" required>
                        <button type="submit" name="change_pass" class="btn-save" style="white-space:nowrap;width:auto;padding:9px 16px;"><i class="fas fa-key"></i> Сменить</button>
                    </div>
                </form>
                <div class="edit-section">
                    <div class="edit-section-title">Изменить роль</div>
                    <form method="POST">
                        <input type="hidden" name="uid" id="editUid2">
                        <div class="input-row">
                            <select name="new_role" id="editRole" class="form-control">
                                <option value="guard">Охранник</option>
                                <option value="admin">Администратор</option>
                            </select>
                            <button type="submit" name="change_role" class="btn-save" style="white-space:nowrap;width:auto;padding:9px 16px;"><i class="fas fa-user-tag"></i> Применить</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openEdit(id, username, fullname, role) {
    document.getElementById('editModalName').textContent = username;
    document.getElementById('editUid1').value = id;
    document.getElementById('editUid2').value = id;
    document.getElementById('editRole').value = role;
    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}
</script>
</body>
</html>
