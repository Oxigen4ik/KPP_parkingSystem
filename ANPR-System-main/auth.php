<?php
session_start();

function require_auth() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php"); exit;
    }
}

function is_admin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function require_admin() {
    require_auth();
    if (!is_admin()) {
        header("Location: index.php"); exit;
    }
}