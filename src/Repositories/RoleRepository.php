<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Database;
use PDO;

final class RoleRepository
{
    public static function all(): array
    {
        return Database::run('SELECT * FROM rol ORDER BY id')->fetchAll();
    }

    public static function find(int $id): array|false
    {
        return Database::run('SELECT * FROM rol WHERE id=?', [$id])->fetch();
    }

    public static function byCode(string $code): array|false
    {
        return Database::run('SELECT * FROM rol WHERE codigo=?', [$code])->fetch();
    }

    public static function forUser(int $id): array
    {
        return Database::run('SELECT p.codigo FROM permiso p
            JOIN rol_permiso rp ON rp.permiso_id=p.id JOIN usuario u ON u.rol_id=rp.rol_id
            WHERE u.id=?', [$id])->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function allows(string $role, string $permission): bool
    {
        return (bool) Database::run('SELECT EXISTS(SELECT 1 FROM rol r
            JOIN rol_permiso rp ON rp.rol_id=r.id JOIN permiso p ON p.id=rp.permiso_id
            WHERE r.codigo=? AND p.codigo=?)', [$role, $permission])->fetchColumn();
    }

    public static function matrix(): array
    {
        $matrix = [];
        $rows = Database::run('SELECT r.codigo AS rol,p.codigo AS permiso
            FROM rol r JOIN rol_permiso rp ON rp.rol_id=r.id JOIN permiso p ON p.id=rp.permiso_id')->fetchAll();
        foreach ($rows as $row) { $matrix[$row['permiso']][$row['rol']] = true; }
        return $matrix;
    }

    public static function lockPermissions(): void
    {
        UserRepository::lockAccounts();
        Database::run('LOCK TABLE rol_permiso IN SHARE ROW EXCLUSIVE MODE');
    }

    public static function replace(array $selection): void
    {
        foreach ($selection as $role => $permissions) {
            Database::run('DELETE FROM rol_permiso WHERE rol_id=(SELECT id FROM rol WHERE codigo=?)', [$role]);
            foreach ($permissions as $code) {
                $insert = Database::run('INSERT INTO rol_permiso(rol_id,permiso_id)
                    SELECT r.id,p.id FROM rol r CROSS JOIN permiso p WHERE r.codigo=? AND p.codigo=?',
                    [$role, $code]);
                if ($insert->rowCount() !== 1) {
                    throw new \RuntimeException('El catálogo de roles o permisos está incompleto.');
                }
            }
        }
    }
}
