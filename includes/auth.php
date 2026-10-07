<?php
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Roles used in the system: 'admin', 'teacher', 'head'
function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }
}

// Put this at the top of every page inside admin/, teacher/ and head/
function require_role(string $role): void {
    require_login();
    if (($_SESSION['role'] ?? '') !== $role) {
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }
}
