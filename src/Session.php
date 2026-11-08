<?php
declare(strict_types=1);
namespace App;

/**
 * Sesión HTTP: cookie segura, expiración por inactividad, token CSRF y mensajes de un solo uso.
 * Es transversal a todos los CU; el ingreso es CU02 y el cierre es CU03.
 */
final class Session
{
    /**
     * Inicia la sesión con cookie HttpOnly y SameSite=Lax y aplica el límite de inactividad.
     * Si la sesión venció, la reinicia y avisa al usuario.
     *
     * @return void
     */
    public static function start(): void
    {
        // Strict mode impide aceptar identificadores de sesión que el navegador no obtuvo del servidor.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('vida_salud');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/',
            'secure' => Config::bool('SESSION_SECURE'), 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
        $last = (int) ($_SESSION['last_seen'] ?? 0);
        $idle = max(1, (int) Config::get('SESSION_IDLE_MINUTES', '30')) * 60;
        if (isset($_SESSION['uid']) && $last > 0 && time() - $last > $idle) {
            $_SESSION = []; session_regenerate_id(true);
            self::flash('Tu sesión venció. Vuelve a ingresar.');
        }
        if (isset($_SESSION['uid'])) { $_SESSION['last_seen'] = time(); }
    }
    /**
     * Devuelve el token CSRF de la sesión y lo crea si todavía no existe.
     *
     * @return string token de 64 caracteres hexadecimales
     */
    public static function token(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }
    /**
     * Valida el token CSRF enviado en cada formulario POST.
     *
     * @param mixed $token valor recibido en el campo "csrf"
     * @return void
     * @throws HttpException 419 si el token falta o no coincide
     */
    public static function verifyToken(mixed $token): void
    {
        // Comparación en tiempo constante para no revelar el token por diferencias de tiempo.
        if (!is_string($token) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
            throw new HttpException(419, 'El formulario venció. Recarga la página e inténtalo de nuevo.');
        }
    }
    /**
     * Inicia la sesión de un usuario ya autenticado (CU02).
     * Regenera el identificador para evitar la fijación de sesión.
     *
     * @param int $id identificador del usuario
     * @param string $passwordHash hash vigente; se guarda solo una huella para invalidar sesiones tras un cambio de clave
     * @return void
     */
    public static function login(int $id, string $passwordHash): void
    {
        $_SESSION = []; session_regenerate_id(true);
        $_SESSION['uid'] = $id; $_SESSION['last_seen'] = time();
        $_SESSION['auth_version'] = hash('sha256', $passwordHash);
        self::token();
    }
    /**
     * Cierra la sesión y elimina la cookie (CU03).
     *
     * @return void
     */
    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'],
                'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        }
        session_destroy();
    }
    /**
     * Guarda un mensaje para la siguiente página o lo lee y lo borra.
     *
     * @param string|null $message mensaje a guardar; null para leer el pendiente
     * @return string|null mensaje pendiente al leer, o null
     */
    public static function flash(?string $message = null): ?string
    {
        if ($message !== null) { $_SESSION['flash'] = $message; return null; }
        $message = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $message;
    }
}
