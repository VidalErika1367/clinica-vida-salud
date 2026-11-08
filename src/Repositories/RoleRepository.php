<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Database;
use PDO;

/**
 * Roles y permisos en base de datos (CU05). La tabla rol_permiso guarda la matriz.
 */
final class RoleRepository
{
    /**
     * Todos los roles.
     *
     * @return array roles
     */
    public static function all(): array
    {
        return Database::run('SELECT * FROM rol ORDER BY id')->fetchAll();
    }

    /**
     * Busca un rol por id.
     *
     * @param int $id rol
     * @return array|false rol o false
     */
    public static function find(int $id): array|false
    {
        return Database::run('SELECT * FROM rol WHERE id=?', [$id])->fetch();
    }

    /**
     * Busca un rol por su código (por ejemplo "paciente").
     *
     * @param string $code código del rol
     * @return array|false rol o false
     */
    public static function byCode(string $code): array|false
    {
        return Database::run('SELECT * FROM rol WHERE codigo=?', [$code])->fetch();
    }

    /**
     * Códigos de permiso (CU) que tiene la cuenta según la matriz actual. Se lee en cada petición.
     *
     * @param int $id usuario
     * @return string[] códigos de CU
     */
    public static function forUser(int $id): array
    {
        return Database::run('SELECT p.codigo FROM permiso p
            JOIN rol_permiso rp ON rp.permiso_id=p.id JOIN usuario u ON u.rol_id=rp.rol_id
            WHERE u.id=?', [$id])->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Indica si un rol tiene un permiso en la matriz.
     *
     * @param string $role código del rol
     * @param string $permission código del CU
     * @return bool
     */
    public static function allows(string $role, string $permission): bool
    {
        return (bool) Database::run('SELECT EXISTS(SELECT 1 FROM rol r
            JOIN rol_permiso rp ON rp.rol_id=r.id JOIN permiso p ON p.id=rp.permiso_id
            WHERE r.codigo=? AND p.codigo=?)', [$role, $permission])->fetchColumn();
    }

    /**
     * Matriz completa: para cada permiso, los roles que lo tienen.
     *
     * @return array<string,array<string,bool>> permiso => rol => true
     */
    public static function matrix(): array
    {
        $matrix = [];
        $rows = Database::run('SELECT r.codigo AS rol,p.codigo AS permiso
            FROM rol r JOIN rol_permiso rp ON rp.rol_id=r.id JOIN permiso p ON p.id=rp.permiso_id')->fetchAll();
        foreach ($rows as $row) { $matrix[$row['permiso']][$row['rol']] = true; }
        return $matrix;
    }

    /**
     * Bloquea las cuentas y la tabla de permisos antes de cambiarlos, para que dos cambios simultáneos no se pisen.
     *
     * @return void
     */
    public static function lockPermissions(): void
    {
        UserRepository::lockAccounts();
        Database::run('LOCK TABLE rol_permiso IN SHARE ROW EXCLUSIVE MODE');
    }

    /**
     * Sustituye los permisos de cada rol por la selección validada. Falla si algún CU no existe en el catálogo.
     *
     * @param array $selection permisos por rol
     * @return void
     * @throws \RuntimeException si el catálogo de roles o permisos no contiene un código
     */
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
