<?php
// includes/auth.php
if (session_status() === PHP_SESSION_NONE) session_start();

function is_worker() {
    return isset($_SESSION['worker_id']);
}

function is_manager() {
    return isset($_SESSION['manager_id']);
}

function require_worker() {
    if (!is_worker()) {
        header('Location: login.php');
        exit;
    }
}

function require_manager() {
    if (!is_manager()) {
        header('Location: manager_login.php');
        exit;
    }
}

function esc($str) {
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
