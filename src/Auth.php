<?php
declare(strict_types=1);
namespace App;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;

/**
 * Autenticación y autorización de la sesión actual.
 * Comprueba que la cuenta siga habilitada y que la contraseña no haya cambiado desde el ingreso.
 * Casos de uso: CU02 (iniciar sesión) y control de permisos de los demás CU protegidos.
 */
final class Auth
{
    /** Indica si ya se consultó la sesión en esta petición (evita repetir la consulta). */
    private static bool $loaded = false;
    /** Usuario autenticado de esta petición, o null. */
    private static ?array $user = null;
    /**
     * Devuelve el usuario de la sesión con sus permisos, o null si no hay sesión válida.
     * Invalida la sesión si la cuenta fue deshabilitada o si la contraseña cambió después del ingreso.
     *
     * @return array|null datos del usuario con la lista de permisos (CU) o null
     */
    public static function current(): ?array
    {
        if (self::$loaded) { return self::$user; }
        self::$loaded = true;
        if (!isset($_SESSION['uid'])) { return null; }
        $user = UserRepository::sessionAccount((int) $_SESSION['uid']);
        // La huella del hash en sesión obliga a cerrar sesiones antiguas tras un cambio de contraseña.
        if (!$user || !hash_equals(hash('sha256', $user['password_hash']), (string) ($_SESSION['auth_version'] ?? ''))) {
            unset($_SESSION['uid'], $_SESSION['auth_version']); return null;
        }
        unset($user['password_hash']);
        $user['permisos'] = RoleRepository::forUser((int) $user['id']);
        return self::$user = $user;
    }
    /**
     * Exige una sesión iniciada. Si no existe, redirige a /ingresar y termina la petición.
     *
     * @return array usuario autenticado
     */
    public static function requireUser(): array
    {
        $user = self::current();
        if (!$user) { redirect('/ingresar'); }
        return $user;
    }
    /**
     * Exige rol administrador y el permiso del CU indicado.
     *
     * @param string $permission código del caso de uso, por ejemplo "CU08"
     * @return array usuario autenticado
     * @throws HttpException 403 si la cuenta no tiene el permiso
     */
    public static function requireAdmin(string $permission): array
    {
        return self::requireRolePermission($permission, ['administrador']);
    }
    /**
     * Exige que el rol de la cuenta esté entre los permitidos y que tenga el permiso del CU.
     * Ambas condiciones son necesarias: el permiso se configura en la matriz de roles (CU05).
     *
     * @param string $permission código del caso de uso, por ejemplo "CU12"
     * @param string[] $roles códigos de rol permitidos
     * @return array usuario autenticado
     * @throws HttpException 403 si el rol o el permiso no cumplen
     */
    public static function requireRolePermission(string $permission, array $roles): array
    {
        $user = self::requireUser();
        if (!in_array($user['rol'], $roles, true) || !in_array($permission, $user['permisos'], true)) {
            throw new HttpException(403, 'Tu cuenta no tiene permiso para acceder a este apartado.');
        }
        return $user;
    }
    /**
     * Verifica credenciales e inicia sesión si son correctas (CU02).
     *
     * @param string $identity nombre de usuario o correo
     * @param string $password contraseña en texto plano (solo se compara con el hash)
     * @return bool true si el ingreso fue correcto
     */
    public static function attempt(string $identity, string $password): bool
    {
        $user = UserRepository::credentials($identity);
        // También se verifica un hash cuando la identidad no existe para reducir diferencias de tiempo.
        $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid = password_verify($password, $hash);
        if (!$user || !$valid || !$user['habilitado']) { return false; }
        // Se actualiza el hash si PHP cambió el algoritmo por defecto.
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            UserRepository::updatePassword((int) $user['id'], $hash);
        }
        Session::login((int) $user['id'], $hash); return true;
    }
    /**
     * Si la cuenta tiene una contraseña temporal (restablecida por recepción o administración), solo permite
     * abrir "Cambiar contraseña" o cerrar sesión; cualquier otra ruta redirige a esa página.
     *
     * @param string $method método HTTP de la petición
     * @param string $path ruta solicitada
     * @return void
     */
    public static function enforcePasswordChange(string $method, string $path): void
    {
        $user = self::current();
        if (!$user || !$user['debe_cambiar_password']) { return; }
        $allowed = ($path === '/cambiar-password' && in_array($method, ['GET', 'POST'], true)) || ($path === '/salir' && $method === 'POST');
        if (!$allowed) { redirect('/cambiar-password'); }
    }
}
