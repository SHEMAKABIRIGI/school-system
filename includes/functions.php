<?php
// Small helper functions used across the project.

// Escape text before printing it in HTML.
function e($text): string {
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

// Send the browser to another page and stop.
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

// CSRF protection for forms: put csrf_field() inside every POST form,
// and call csrf_check() when the form is submitted.
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(?string $token): bool {
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}
