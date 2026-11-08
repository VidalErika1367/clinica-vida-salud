<?php
declare(strict_types=1);
namespace App;

/**
 * Renderiza las vistas de views/ dentro del layout común.
 * Lo usan todos los controladores; no contiene reglas de negocio.
 */
final class View
{
    /**
     * Renderiza una plantilla: primero captura su HTML y luego lo inserta en views/layout.php.
     *
     * @param string $template nombre de la vista en views/, sin extensión
     * @param array $data variables que recibe la vista ("title", "user", "errors", "old", etc.)
     * @param int $status código HTTP de la respuesta
     * @return void
     */
    public static function render(string $template, array $data = [], int $status = 200): void
    {
        http_response_code($status);
        $user = array_key_exists('user', $data) ? $data['user'] : Auth::current();
        $errors = $data['errors'] ?? []; $old = $data['old'] ?? [];
        $title = $data['title'] ?? 'Clínica Vida Salud';
        // EXTR_SKIP no sobrescribe las variables ya definidas arriba.
        extract($data, EXTR_SKIP);
        ob_start(); require BASE_PATH . '/views/' . $template . '.php'; $content = ob_get_clean();
        require BASE_PATH . '/views/layout.php';
    }
}
