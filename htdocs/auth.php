<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';

function current_user() {
    return $_SESSION['display_name'] ?? null;
}

function require_login() {
    if (!current_user()) {
        header('Location: login.php');
        exit;
    }
}

function other_person($p) {
    return $p === 'Ozi' ? 'Ceyda' : 'Ozi';
}
