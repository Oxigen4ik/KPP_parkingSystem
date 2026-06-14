<?php
/**
 * emergency.php — обработчик тревоги и ручного открытия шлагбаума
 *
 * POST JSON { "action": "open_barrier" }  → только Arduino (открыть шлагбаум)
 * POST (без тела / action=emergency)      → Telegram + Arduino (тревога)
 *
 * Arduino подключено через USB-COM порт.
 * PHP пишет команду в COM-порт через mode + echo (Windows) или stty (Linux).
 */

require_once 'auth.php';
require_auth();

header('Content-Type: application/json');

// ── Настройки ──────────────────────────────────────────────
const TELEGRAM_BOT_TOKEN = 'ВАШ_BOT_TOKEN';   // @BotFather → /newbot
const TELEGRAM_CHAT_ID   = 'ВАШ_CHAT_ID';     // @userinfobot → ваш chat_id

// COM-порт Arduino (Windows: 'COM3', 'COM4' и т.д. — смотри Диспетчер устройств)
// Linux/Mac: '/dev/ttyUSB0' или '/dev/ttyACM0'
const ARDUINO_PORT = 'COM3';
const ARDUINO_BAUD = 9600;

// ── Читаем тело запроса ────────────────────────────────────
$body   = json_decode(file_get_contents('php://input'), true);
$action = $body['action'] ?? 'emergency';   // 'open_barrier' или 'emergency'

$results = [];

// ── 1. Сигнал на Arduino ──────────────────────────────────
/**
 * Команды которые понимает Arduino:
 *   'O' — открыть шлагбаум (Open)
 *   'A' — тревога (Alarm): открыть + включить сирену/лампу
 */
$arduino_cmd = ($action === 'open_barrier') ? 'O' : 'A';
$results['arduino'] = sendArduino($arduino_cmd);

// ── 2. Telegram (только при тревоге) ──────────────────────
if ($action === 'emergency') {
    $guard   = htmlspecialchars($_SESSION['username'] ?? 'unknown');
    $now     = date('d.m.Y H:i:s');
    $message =
        "🚨 *ТРЕВОГА — КПП КузГТУ*\n\n" .
        "Охранник: `{$guard}`\n" .
        "Время: `{$now}`\n\n" .
        "Шлагбаум открыт. Требуется немедленная реакция.";

    $results['telegram'] = sendTelegram($message);
}

echo json_encode(['ok' => true, 'action' => $action, 'results' => $results]);


// ══════════════════════════════════════════════════════════
//  ФУНКЦИИ
// ══════════════════════════════════════════════════════════

/**
 * Отправляет одиночный байт-команду на Arduino через COM-порт.
 *
 * Windows: использует 'mode' для настройки порта + 'echo' для отправки.
 * Linux  : использует 'stty' + 'echo'.
 *
 * Возвращает строку-статус: 'sent:O' / 'error:...'
 */
function sendArduino(string $cmd): string {
    $port = ARDUINO_PORT;
    $baud = ARDUINO_BAUD;

    try {
        if (PHP_OS_FAMILY === 'Windows') {
            // Настраиваем порт (один раз при каждом вызове — дёшево)
            shell_exec("mode {$port}: baud={$baud} parity=N data=8 stop=1 xon=off 2>&1");
            // Отправляем команду
            $out = shell_exec("echo {$cmd} > {$port} 2>&1");
        } else {
            // Linux / Mac
            shell_exec("stty -F {$port} {$baud} cs8 -cstopb -parenb 2>&1");
            $out = shell_exec("echo -n {$cmd} > {$port} 2>&1");
        }
        return 'sent:' . $cmd . ($out ? ' ' . trim($out) : '');
    } catch (Throwable $e) {
        return 'error:' . $e->getMessage();
    }
}

/**
 * Отправляет сообщение в Telegram через Bot API.
 * Возвращает 'sent' / 'error:...'
 */
function sendTelegram(string $text): string {
    $token = TELEGRAM_BOT_TOKEN;
    $chat  = TELEGRAM_CHAT_ID;

    if ($token === 'ВАШ_BOT_TOKEN' || $chat === 'ВАШ_CHAT_ID') {
        return 'not_configured';
    }

    $url  = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = http_build_query([
        'chat_id'    => $chat,
        'text'       => $text,
        'parse_mode' => 'Markdown',
    ]);

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $data,
        'timeout' => 5,
    ]]);

    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) {
        return 'error:no_response';
    }

    $json = json_decode($response, true);
    return ($json['ok'] ?? false) ? 'sent' : 'error:' . ($json['description'] ?? 'unknown');
}