<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Log out automatically after 30 minutes without activity.
if (!defined('SESSION_TIMEOUT')) {
    define('SESSION_TIMEOUT', 1800);
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Roles used in the system: 'admin', 'teacher', 'head'
function dashboard_url(string $role): string {
    $map = [
        'admin'   => BASE_URL . 'admin/dashboard.php',
        'teacher' => BASE_URL . 'teacher/dashboard.php',
        'head'    => BASE_URL . 'head/dashboard.php',
    ];
    return $map[$role] ?? BASE_URL . 'login.php';
}

function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        redirect(BASE_URL . 'login.php');
    }
    if (time() - ($_SESSION['last_activity'] ?? 0) > SESSION_TIMEOUT) {
        $_SESSION = [];
        session_destroy();
        redirect(BASE_URL . 'login.php?timeout=1');
    }
    $_SESSION['last_activity'] = time();
}

// Put this at the top of every page inside admin/, teacher/ and head/.
// Not logged in -> login page. Logged in with another role -> own dashboard.
function require_role(string $role): void {
    require_login();
    if (($_SESSION['role'] ?? '') !== $role) {
        redirect(dashboard_url($_SESSION['role'] ?? ''));
    }
}
