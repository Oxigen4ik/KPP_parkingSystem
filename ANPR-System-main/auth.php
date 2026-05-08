<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>false,'httponly'=>true,'samesite'=>'Strict']);
    session_start();
}
define('SESSION_LIFETIME', 8 * 3600);

function session_is_valid(): bool {
    if (empty($_SESSION['user_id'])) return false;
    if (empty($_SESSION['login_time'])) return false;
    if (time() - $_SESSION['login_time'] > SESSION_LIFETIME) { session_unset(); session_destroy(); return false; }
    return true;
}
function require_auth(): void { if (!session_is_valid()) { header('Location: login.php'); exit; } }
function require_admin(): void { require_auth(); if ($_SESSION['role'] !== 'admin') { header('Location: index.php'); exit; } }
function is_admin(): bool { return isset($_SESSION['role']) && $_SESSION['role'] === 'admin'; }
function is_guard(): bool { return isset($_SESSION['role']) && $_SESSION['role'] === 'guard'; }
function current_user(): string { return $_SESSION['username'] ?? ''; }
