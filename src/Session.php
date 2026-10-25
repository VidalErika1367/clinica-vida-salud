<?php
declare(strict_types=1);
namespace App;

final class Session
{
    public static function start(): void
    {
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
    public static function token(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }
    public static function verifyToken(mixed $token): void
    {
        if (!is_string($token) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
            throw new HttpException(419, 'El formulario venció. Recarga la página e inténtalo de nuevo.');
        }
    }
    public static function login(int $id, string $passwordHash): void
    {
        $_SESSION = []; session_regenerate_id(true);
        $_SESSION['uid'] = $id; $_SESSION['last_seen'] = time();
        $_SESSION['auth_version'] = hash('sha256', $passwordHash);
        self::token();
    }
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
    public static function flash(?string $message = null): ?string
    {
        if ($message !== null) { $_SESSION['flash'] = $message; return null; }
        $message = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $message;
    }
}
