<?php
session_start();
include 'db_config.php';

// Уже залогинен — редирект
if (isset($_SESSION['user_id'])) {
    header("Location: index.php"); exit;
}

$error = "";
$locked_until = $_SESSION['locked_until'] ?? 0;
$attempts     = $_SESSION['login_attempts'] ?? 0;
$now          = time();
$lock_seconds = max(0, $locked_until - $now);

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $lock_seconds <= 0) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $stmt = $conn->prepare("SELECT id, password_hash, role, full_name, is_active FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user && $user['is_active'] && password_verify($password, $user['password_hash'])) {
        // Успех — сбрасываем попытки
        $_SESSION['login_attempts'] = 0;
        $_SESSION['locked_until']   = 0;
        $_SESSION['user_id']        = $user['id'];
        $_SESSION['username']       = $username;
        $_SESSION['role']           = $user['role'];
        $_SESSION['full_name']      = $user['full_name'];

        // Обновляем last_login
        $conn->query("UPDATE users SET last_login = NOW() WHERE id = " . intval($user['id']));

        header("Location: index.php"); exit;
    } else {
        $_SESSION['login_attempts'] = $attempts + 1;
        if ($_SESSION['login_attempts'] >= 3) {
            $_SESSION['locked_until']   = $now + 120; // 2 минуты
            $_SESSION['login_attempts'] = 0;
            $lock_seconds = 120;
        }
        $error = "Неверный логин или пароль";
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>КПП — Вход в систему</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=Golos+Text:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg:         #080c10;
            --panel:      #0d1117;
            --panel-r:    #111820;
            --border:     #1c2a35;
            --text:       #cdd9e5;
            --text-dim:   #627282;
            --text-bright:#e6f0f8;
            --accent:     #00e07a;
            --accent-dim: rgba(0,224,122,0.12);
            --danger:     #f85149;
            --danger-dim: rgba(248,81,73,0.12);
            --warning:    #e3b341;
            --mono:       'JetBrains Mono', monospace;
            --sans:       'Golos Text', system-ui, sans-serif;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background-color: var(--bg);
            background-image:
                linear-gradient(rgba(0,200,120,0.022) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0,200,120,0.022) 1px, transparent 1px);
            background-size: 32px 32px;
            font-family: var(--sans);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--text);
            -webkit-font-smoothing: antialiased;
        }

        /* Угловые декорации */
        .corner {
            position: fixed;
            width: 60px; height: 60px;
            border-color: rgba(0,224,122,0.18);
            border-style: solid;
        }
        .corner.tl { top: 24px; left: 24px; border-width: 2px 0 0 2px; }
        .corner.tr { top: 24px; right: 24px; border-width: 2px 2px 0 0; }
        .corner.bl { bottom: 24px; left: 24px; border-width: 0 0 2px 2px; }
        .corner.br { bottom: 24px; right: 24px; border-width: 0 2px 2px 0; }

        /* Системный статус вверху */
        .sys-status {
            position: fixed;
            top: 28px;
            font-family: var(--mono);
            font-size: 0.68rem;
            letter-spacing: 0.1em;
            color: var(--text-dim);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sys-status::before {
            content: '';
            width: 6px; height: 6px;
            background: var(--accent);
            border-radius: 50%;
            box-shadow: 0 0 8px var(--accent);
            animation: blink 2.4s ease-in-out infinite;
        }

        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.2; }
        }

        /* Карточка входа */
        .login-wrap {
            width: 100%;
            max-width: 380px;
            padding: 0 16px;
        }

        .login-logo {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 32px;
            gap: 12px;
        }

        .login-logo img {
            height: 48px;
            filter: brightness(0) invert(1) sepia(1) saturate(3) hue-rotate(100deg);
            opacity: 0.9;
        }

        .login-logo .sys-title {
            font-family: var(--mono);
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--accent);
        }

        .login-logo .sys-sub {
            font-family: var(--mono);
            font-size: 0.65rem;
            letter-spacing: 0.1em;
            color: var(--text-dim);
            text-transform: uppercase;
        }

        .login-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 20px 80px rgba(0,0,0,0.6), 0 0 0 1px rgba(0,224,122,0.04);
        }

        .login-card-header {
            background: var(--panel-r);
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: var(--mono);
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--text-dim);
        }

        .login-card-header i { color: var(--accent); }

        .login-card-body { padding: 24px 20px; }

        .form-group { margin-bottom: 16px; }

        label {
            display: block;
            font-family: var(--mono);
            font-size: 0.68rem;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--text-dim);
            margin-bottom: 7px;
        }

        .input-wrap {
            position: relative;
        }

        .input-wrap i {
            position: absolute;
            left: 13px; top: 50%;
            transform: translateY(-50%);
            color: var(--text-dim);
            font-size: 0.8rem;
            pointer-events: none;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            background: var(--bg);
            border: 1px solid var(--border);
            color: var(--text-bright);
            padding: 10px 13px 10px 36px;
            border-radius: 9px;
            font-family: var(--mono);
            font-size: 0.88rem;
            outline: none;
            transition: all 0.18s ease;
        }

        input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(0,224,122,0.1);
        }

        input::placeholder { color: var(--text-dim); opacity: 0.45; }

        /* Блок блокировки */
        .lockout-block {
            background: rgba(248,81,73,0.07);
            border: 1px solid rgba(248,81,73,0.25);
            border-radius: 9px;
            padding: 14px 16px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .lockout-block i { color: var(--danger); font-size: 1.1rem; flex-shrink: 0; }

        .lockout-text {
            font-family: var(--mono);
            font-size: 0.75rem;
            color: var(--danger);
            line-height: 1.5;
        }

        .lockout-timer {
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            display: block;
            margin-top: 4px;
        }

        /* Ошибка */
        .error-msg {
            background: var(--danger-dim);
            border: 1px solid rgba(248,81,73,0.25);
            border-left: 3px solid var(--danger);
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 16px;
            font-family: var(--mono);
            font-size: 0.78rem;
            color: var(--danger);
            display: flex;
            align-items: center;
            gap: 8px;
            animation: shake 0.35s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%       { transform: translateX(-6px); }
            60%       { transform: translateX(6px); }
        }

        /* Кнопка входа */
        .btn-login {
            width: 100%;
            padding: 11px;
            border: 1px solid rgba(0,224,122,0.35);
            border-radius: 9px;
            background: var(--accent-dim);
            color: var(--accent);
            font-family: var(--mono);
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.18s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 8px;
        }

        .btn-login:hover:not(:disabled) {
            background: var(--accent);
            color: #000;
            box-shadow: 0 0 24px rgba(0,224,122,0.25);
            transform: translateY(-1px);
        }

        .btn-login:disabled {
            opacity: 0.35;
            cursor: not-allowed;
        }

        /* Счётчик попыток */
        .attempts-hint {
            text-align: center;
            font-family: var(--mono);
            font-size: 0.65rem;
            color: var(--text-dim);
            margin-top: 14px;
            letter-spacing: 0.06em;
        }

        .attempts-hint span { color: var(--warning); }

        /* Версия внизу */
        .footer-note {
            margin-top: 20px;
            text-align: center;
            font-family: var(--mono);
            font-size: 0.62rem;
            letter-spacing: 0.08em;
            color: var(--text-dim);
            opacity: 0.5;
            text-transform: uppercase;
        }
    </style>
</head>
<body>

<div class="corner tl"></div>
<div class="corner tr"></div>
<div class="corner bl"></div>
<div class="corner br"></div>

<div class="sys-status">СИСТЕМА КОНТРОЛЯ ДОСТУПА — ОНЛАЙН</div>

<div class="login-wrap">
    <div class="login-logo">
        <img src="favicon.ico" alt="КПП">
        <div class="sys-title">Информационная система КПП</div>
        <div class="sys-sub">Авторизация персонала</div>
    </div>

    <div class="login-card">
        <div class="login-card-header">
            <i class="fas fa-shield-alt"></i>
            Вход в систему
        </div>
        <div class="login-card-body">

            <?php if ($lock_seconds > 0): ?>
                <div class="lockout-block">
                    <i class="fas fa-lock"></i>
                    <div class="lockout-text">
                        Слишком много попыток. Доступ временно заблокирован.
                        <span class="lockout-timer" id="lockTimer">--:--</span>
                    </div>
                </div>
            <?php elseif ($error): ?>
                <div class="error-msg">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="form-group">
                    <label for="username"><i class="fas fa-user" style="margin-right:5px;opacity:.5"></i>Логин</label>
                    <div class="input-wrap">
                        <i class="fas fa-terminal"></i>
                        <input type="text" id="username" name="username"
                               placeholder="Введите логин"
                               <?= $lock_seconds > 0 ? 'disabled' : '' ?>
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                               autofocus>
                    </div>
                </div>
                <div class="form-group">
                    <label for="password"><i class="fas fa-key" style="margin-right:5px;opacity:.5"></i>Пароль</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password"
                               placeholder="••••••••"
                               <?= $lock_seconds > 0 ? 'disabled' : '' ?>>
                    </div>
                </div>

                <button type="submit" class="btn-login" <?= $lock_seconds > 0 ? 'disabled' : '' ?>>
                    <i class="fas fa-sign-in-alt"></i>
                    Войти
                </button>
            </form>

            <?php
            $remaining = $_SESSION['login_attempts'] ?? 0;
            if ($remaining > 0 && $lock_seconds <= 0):
            ?>
            <div class="attempts-hint">
                Неудачных попыток: <span><?= $remaining ?>/3</span> — после 3-й блокировка на 2 мин.
            </div>
            <?php endif; ?>

        </div>
    </div>

    <div class="footer-note">КПП v2.0 &nbsp;·&nbsp; защищённый доступ</div>
</div>

<script>
// Таймер обратного отсчёта блокировки
const lockSeconds = <?= $lock_seconds ?>;

if (lockSeconds > 0) {
    const timerEl = document.getElementById('lockTimer');
    let remaining = lockSeconds;

    const tick = () => {
        const m = String(Math.floor(remaining / 60)).padStart(2, '0');
        const s = String(remaining % 60).padStart(2, '0');
        timerEl.textContent = m + ':' + s;

        if (remaining <= 0) {
            // Перезагружаем страницу когда таймер вышел
            location.reload();
            return;
        }
        remaining--;
        setTimeout(tick, 1000);
    };
    tick();
}
</script>
</body>
</html>