<?php
declare(strict_types=1);
namespace App;
/**
 * Error con código HTTP asociado. Lo captura public/index.php y muestra la pantalla de error.
 * Códigos usados en la aplicación: 403 (permisos), 404 (no encontrado), 419 (token CSRF vencido) y 422 (datos no válidos).
 */
final class HttpException extends \RuntimeException
{
    /**
     * @param int $status código HTTP de la respuesta (403, 404, 419, etc.)
     * @param string $message texto que se muestra al usuario; no debe incluir datos internos
     */
    public function __construct(public readonly int $status, string $message) { parent::__construct($message); }
}
