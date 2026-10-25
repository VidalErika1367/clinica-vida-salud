<?php
declare(strict_types=1);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('#\A/assets/[a-zA-Z0-9_.-]+\.(css|js|svg)\z#', $path) && is_file(__DIR__ . '/public' . $path)) {
    return false;
}
require __DIR__ . '/public/index.php';
