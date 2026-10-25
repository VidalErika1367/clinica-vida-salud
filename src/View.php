<?php
declare(strict_types=1);
namespace App;
final class View
{
    public static function render(string $template, array $data = [], int $status = 200): void
    {
        http_response_code($status);
        $user = array_key_exists('user', $data) ? $data['user'] : Auth::current();
        $errors = $data['errors'] ?? []; $old = $data['old'] ?? [];
        $title = $data['title'] ?? 'Clínica Vida Salud';
        extract($data, EXTR_SKIP);
        ob_start(); require BASE_PATH . '/views/' . $template . '.php'; $content = ob_get_clean();
        require BASE_PATH . '/views/layout.php';
    }
}
