<?php
declare(strict_types=1);
namespace App;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;

final class Auth
{
    private static bool $loaded = false;
    private static ?array $user = null;
    public static function current(): ?array
    {
        if (self::$loaded) { return self::$user; }
        self::$loaded = true;
        if (!isset($_SESSION['uid'])) { return null; }
        $user = UserRepository::sessionAccount((int) $_SESSION['uid']);
        if (!$user || !hash_equals(hash('sha256', $user['password_hash']), (string) ($_SESSION['auth_version'] ?? ''))) {
            unset($_SESSION['uid'], $_SESSION['auth_version']); return null;
        }
        unset($user['password_hash']);
        $user['permisos'] = RoleRepository::forUser((int) $user['id']);
        return self::$user = $user;
    }
    public static function requireUser(): array
    {
        $user = self::current();
        if (!$user) { redirect('/ingresar'); }
        return $user;
    }
    public static function requireAdmin(string $permission): array
    {
        return self::requireRolePermission($permission, ['administrador']);
    }
    public static function requireRolePermission(string $permission, array $roles): array
    {
        $user = self::requireUser();
        if (!in_array($user['rol'], $roles, true) || !in_array($permission, $user['permisos'], true)) {
            throw new HttpException(403, 'Tu cuenta no tiene permiso para acceder a este apartado.');
        }
        return $user;
    }
    public static function attempt(string $identity, string $password): bool
    {
        $user = UserRepository::credentials($identity);
        // También se verifica un hash cuando la identidad no existe para reducir diferencias de tiempo.
        $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid = password_verify($password, $hash);
        if (!$user || !$valid || !$user['habilitado']) { return false; }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            UserRepository::updatePassword((int) $user['id'], $hash);
        }
        Session::login((int) $user['id'], $hash); return true;
    }
}
