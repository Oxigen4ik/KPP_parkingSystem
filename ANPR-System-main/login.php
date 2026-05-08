<?php
require_once 'auth.php';
if (session_is_valid()) { header('Location: index.php'); exit; }
require_once 'db_config.php';

$error = ''; $locked = false; $lock_sec = 0;
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Проверка блокировки
$stmt = $conn->prepare("SELECT attempts, locked_until FROM login_attempts WHERE ip=?");
$stmt->bind_param('s', $ip); $stmt->execute();
$row = $stmt->get_result()->fetch_assoc(); $stmt->close();

if ($row && $row['locked_until']) {
    $lock_sec = strtotime($row['locked_until']) - time();
    if ($lock_sec > 0) {
        $locked = true;
    } else {
        $s = $conn->prepare("UPDATE login_attempts SET attempts=0,locked_until=NULL WHERE ip=?");
        $s->bind_param('s',$ip); $s->execute();
        $locked = false; $lock_sec = 0;
    }
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $stmt = $conn->prepare("SELECT id,password,role,full_name,is_active FROM users WHERE username=?");
    $stmt->bind_param('s',$username); $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc(); $stmt->close();

    if ($user && $user['is_active'] && password_verify($password, $user['password'])) {
        $s = $conn->prepare("DELETE FROM login_attempts WHERE ip=?"); $s->bind_param('s',$ip); $s->execute();
        $s = $conn->prepare("UPDATE users SET last_login=NOW() WHERE id=?"); $s->bind_param('i',$user['id']); $s->execute();
        session_regenerate_id(true);
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['username']   = $username;
        $_SESSION['role']       = $user['role'];
        $_SESSION['full_name']  = $user['full_name'];
        $_SESSION['login_time'] = time();
        header('Location: index.php'); exit;
    } else {
        $stmt = $conn->prepare("INSERT INTO login_attempts (ip,attempts,locked_until) VALUES (?,1,NULL)
            ON DUPLICATE KEY UPDATE
                attempts=attempts+1,
                locked_until=IF(attempts+1>=4, DATE_ADD(NOW(), INTERVAL 2 MINUTE), NULL)");
        $stmt->bind_param('s',$ip); $stmt->execute();

        $s = $conn->prepare("SELECT attempts,locked_until FROM login_attempts WHERE ip=?");
        $s->bind_param('s',$ip); $s->execute();
        $row = $s->get_result()->fetch_assoc();

        if ($row && $row['locked_until']) {
            $lock_sec = max(0, strtotime($row['locked_until']) - time());
            $locked = ($lock_sec > 0);
            $error  = 'locked';
        } else {
            $rem = max(0, 3 - ($row['attempts'] ?? 1));
            $error = "Неверный логин или пароль. Осталось попыток: $rem";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Вход — КПП</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=Golos+Text:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --bg:#080c10; --panel:#0d1117; --panel-raised:#111820; --border:#1c2a35;
    --text:#cdd9e5; --text-dim:#627282; --text-bright:#e6f0f8;
    --accent:#00e07a; --accent-dim:rgba(0,224,122,0.1);
    --danger:#f85149; --danger-dim:rgba(248,81,73,0.1);
    --warning:#e3b341; --warning-dim:rgba(227,179,65,0.07);
    --mono:'JetBrains Mono',ui-monospace,monospace;
    --sans:'Golos Text',system-ui,sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body {
    background-color:var(--bg);
    background-image:linear-gradient(rgba(0,200,120,.022) 1px,transparent 1px),
                     linear-gradient(90deg,rgba(0,200,120,.022) 1px,transparent 1px);
    background-size:32px 32px;
    font-family:var(--sans);
    min-height:100vh;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    -webkit-font-smoothing:antialiased;
}
body::before,body::after{
    content:'';position:fixed;width:180px;height:180px;
    border:1px solid rgba(0,224,122,.07);pointer-events:none;
}
body::before{top:24px;left:24px;border-right:none;border-bottom:none}
body::after{bottom:24px;right:24px;border-left:none;border-top:none}

.login-wrap{width:100%;max-width:380px;padding:0 16px}

.login-header{text-align:center;margin-bottom:28px}
.login-logo{
    width:54px;height:54px;
    background:rgba(0,224,122,.08);
    border:1px solid rgba(0,224,122,.25);
    border-radius:14px;
    display:inline-flex;align-items:center;justify-content:center;
    margin-bottom:14px;position:relative;
}
.login-logo i{font-size:1.4rem;color:var(--accent)}
.login-logo::after{
    content:'';position:absolute;inset:-7px;
    border-radius:21px;border:1px solid rgba(0,224,122,.13);
    animation:rpulse 2.5s ease-in-out infinite;
}
@keyframes rpulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.25;transform:scale(1.08)}}
.login-title{font-family:var(--mono);font-size:.7rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--text-dim);margin-top:2px}
.login-subtitle{font-family:var(--mono);font-size:.98rem;font-weight:700;color:var(--text-bright);letter-spacing:.04em;margin-top:5px}

.login-card{
    background:var(--panel);border:1px solid var(--border);border-radius:16px;
    padding:28px 28px 22px;box-shadow:0 20px 60px rgba(0,0,0,.5);position:relative;overflow:hidden;
}
.login-card::before{
    content:'';position:absolute;top:0;left:20%;right:20%;height:1px;
    background:linear-gradient(90deg,transparent,var(--accent),transparent);opacity:.45;
}

.field{margin-bottom:15px}
.field label{display:block;font-family:var(--mono);font-size:.68rem;font-weight:600;
    letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim);margin-bottom:7px}
.field-inner{position:relative}
.field-inner i{position:absolute;left:12px;top:50%;transform:translateY(-50%);
    color:var(--text-dim);font-size:.8rem;pointer-events:none;transition:color .18s}
.field-inner:focus-within i{color:var(--accent)}
.field input{
    width:100%;background:var(--bg);border:1px solid var(--border);
    color:var(--text-bright);padding:10px 12px 10px 36px;
    border-radius:9px;font-family:var(--mono);font-size:.88rem;
    outline:none;transition:border-color .18s,box-shadow .18s;
}
.field input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,224,122,.1)}
.field input:disabled{opacity:.4;cursor:not-allowed}
.field input::placeholder{color:var(--text-dim);opacity:.45}

.btn-login{
    width:100%;padding:11px;margin-top:6px;
    background:var(--accent-dim);border:1px solid rgba(0,224,122,.35);
    border-radius:9px;color:var(--accent);
    font-family:var(--mono);font-size:.78rem;font-weight:700;
    letter-spacing:.1em;text-transform:uppercase;cursor:pointer;
    transition:all .18s;display:flex;align-items:center;justify-content:center;gap:8px;
}
.btn-login:hover:not(:disabled){background:var(--accent);color:#000;box-shadow:0 0 24px rgba(0,224,122,.25);transform:translateY(-1px)}
.btn-login:disabled{opacity:.4;cursor:not-allowed;border-color:var(--border);color:var(--text-dim);background:transparent}

.error-msg{
    margin-top:14px;padding:10px 14px;border-radius:8px;
    background:var(--danger-dim);border:1px solid rgba(248,81,73,.22);
    border-left:3px solid var(--danger);
    font-family:var(--mono);font-size:.76rem;color:var(--danger);
    display:flex;align-items:flex-start;gap:8px;
}

.lock-block{
    margin-top:14px;padding:16px;border-radius:10px;
    background:var(--warning-dim);border:1px solid rgba(227,179,65,.22);
    border-left:3px solid var(--warning);
    font-family:var(--mono);font-size:.76rem;color:var(--warning);
}
.lock-title{display:flex;align-items:center;gap:8px;font-weight:600;font-size:.8rem;margin-bottom:6px}
.lock-sub{color:rgba(227,179,65,.65);font-size:.7rem;margin-bottom:10px}
.lock-timer-row{display:flex;align-items:center;gap:10px}
.lock-timer{font-size:1.7rem;font-weight:700;color:var(--text-bright);letter-spacing:.06em;font-variant-numeric:tabular-nums}
.lock-bar-wrap{flex:1;height:4px;background:rgba(227,179,65,.15);border-radius:2px;overflow:hidden}
.lock-bar{height:100%;background:var(--warning);border-radius:2px;transition:width 1s linear}

.login-footer{text-align:center;margin-top:18px;font-family:var(--mono);font-size:.66rem;
    letter-spacing:.06em;color:var(--text-dim);text-transform:uppercase}

@keyframes shake{0%,100%{transform:translateX(0)}20%{transform:translateX(-8px)}40%{transform:translateX(8px)}60%{transform:translateX(-5px)}80%{transform:translateX(5px)}}
.shake{animation:shake .4s ease}
</style>
</head>
<body>
<div class="login-wrap">
    <div class="login-header">
        <div class="login-logo"><i class="fas fa-shield-halved"></i></div>
        <div class="login-title">Информационная система</div>
        <div class="login-subtitle">КПП — Контроль доступа</div>
    </div>

    <div class="login-card" id="loginCard">
        <form method="POST" id="loginForm" autocomplete="off">
            <div class="field">
                <label for="username">Логин</label>
                <div class="field-inner">
                    <input type="text" id="username" name="username" placeholder="Введите логин"
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                        <?= $locked ? 'disabled' : '' ?> required>
                    <i class="fas fa-user"></i>
                </div>
            </div>
            <div class="field">
                <label for="password">Пароль</label>
                <div class="field-inner">
                    <input type="password" id="password" name="password" placeholder="••••••••"
                        <?= $locked ? 'disabled' : '' ?> required>
                    <i class="fas fa-lock"></i>
                </div>
            </div>
            <button type="submit" class="btn-login" id="loginBtn" <?= $locked ? 'disabled' : '' ?>>
                <i class="fas fa-right-to-bracket"></i> Войти в систему
            </button>
        </form>

        <?php if ($locked): ?>
        <div class="lock-block" id="lockBlock">
            <div class="lock-title"><i class="fas fa-lock"></i> Доступ заблокирован</div>
            <div class="lock-sub">Превышено число неудачных попыток входа (3 из 3)</div>
            <div class="lock-timer-row">
                <span class="lock-timer" id="lockTimer">--:--</span>
                <div class="lock-bar-wrap"><div class="lock-bar" id="lockBar" style="width:100%"></div></div>
            </div>
        </div>
        <?php elseif ($error && $error !== 'locked'): ?>
        <div class="error-msg" id="errorMsg">
            <i class="fas fa-triangle-exclamation"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <div class="login-footer">Система КПП &nbsp;·&nbsp; Только авторизованный доступ</div>
</div>

<script>
const LOCK_SEC   = <?= (int)$lock_sec ?>;
const LOCK_TOTAL = 120;

if (LOCK_SEC > 0) {
    let rem = LOCK_SEC;
    const timerEl  = document.getElementById('lockTimer');
    const barEl    = document.getElementById('lockBar');
    const usernameEl = document.getElementById('username');
    const passwordEl = document.getElementById('password');
    const btnEl    = document.getElementById('loginBtn');
    const blockEl  = document.getElementById('lockBlock');

    function fmt(s) { return String(Math.floor(s/60)).padStart(2,'0')+':'+String(s%60).padStart(2,'0'); }

    (function tick() {
        if (rem <= 0) {
            blockEl.style.display = 'none';
            usernameEl.disabled = false;
            passwordEl.disabled = false;
            btnEl.disabled = false;
            btnEl.innerHTML = '<i class="fas fa-right-to-bracket"></i> Войти в систему';
            return;
        }
        timerEl.textContent = fmt(rem);
        barEl.style.width   = (rem / LOCK_TOTAL * 100) + '%';
        rem--;
        setTimeout(tick, 1000);
    })();
}

<?php if ($error && $error !== 'locked'): ?>
document.getElementById('loginCard').classList.add('shake');
<?php endif; ?>
</script>
</body>
</html>
