<?php
// Small helper functions used across the project.

// Escape text before printing it in HTML.
function e(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
