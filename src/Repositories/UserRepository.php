<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Database;

/**
 * Acceso a cuentas de usuario (CU01, CU02, CU04). Contiene las contraseñas hasheadas; nunca se devuelven al navegador.
 */
final class UserRepository
{
    /**
     * Bloquea la tabla de usuarios en modo exclusivo para cambios administrativos.
     * Porqué: serializa altas, cambios de rol y de permisos, de modo que la comprobación del último administrador sea fiable.
     *
     * @return void
     */
    public static function lockAccounts(): void
    {
        Database::run('LOCK TABLE usuario IN SHARE ROW EXCLUSIVE MODE');
    }

    /**
     * Número de administradores habilitados.
     *
     * @return int
     */
    public static function enabledAdministratorCount(): int
    {
        return (int) Database::run("SELECT count(*) FROM usuario u JOIN rol r ON r.id=u.rol_id
            WHERE r.codigo='administrador' AND u.habilitado")->fetchColumn();
    }

    /**
     * Datos de una cuenta para el formulario de edición (sin contraseña).
     *
     * @param int $id usuario
     * @return array|false datos o false
     */
    public static function find(int $id): array|false
    {
        return Database::run('SELECT u.id,u.rol_id,u.nombre_usuario,u.correo,u.habilitado,
            p.ci,p.nombres,p.apellidos,p.telefono
            FROM usuario u LEFT JOIN paciente p ON p.usuario_id=u.id WHERE u.id=?', [$id])->fetch();
    }

    /**
     * Busca cuentas por nombre de usuario o correo (máximo 200).
     *
     * @param string $query texto de búsqueda
     * @return array cuentas
     */
    public static function search(string $query): array
    {
        $pattern = '%' . mb_strtolower($query) . '%';
        return Database::run('SELECT u.id,u.nombre_usuario,u.correo,u.habilitado,r.nombre AS rol_nombre
            FROM usuario u JOIN rol r ON r.id=u.rol_id
            WHERE lower(u.nombre_usuario) LIKE ? OR lower(u.correo) LIKE ?
            ORDER BY u.id DESC LIMIT 200', [$pattern, $pattern])->fetchAll();
    }

    /**
     * Totales de cuentas: total, habilitadas y deshabilitadas.
     *
     * @return array contadores
     */
    public static function counts(): array
    {
        return Database::run('SELECT count(*) AS total,
            count(*) FILTER(WHERE habilitado) AS habilitados,
            count(*) FILTER(WHERE NOT habilitado) AS deshabilitados FROM usuario')->fetch();
    }

    /**
     * Busca la cuenta por nombre de usuario o correo, sin distinguir mayúsculas, para verificar el ingreso (CU02).
     *
     * @param string $identity nombre de usuario o correo
     * @return array|false id, hash y estado, o false
     */
    public static function credentials(string $identity): array|false
    {
        $identity = mb_strtolower(trim($identity));
        return Database::run('SELECT id,password_hash,habilitado FROM usuario
            WHERE lower(nombre_usuario)=? OR lower(btrim(correo))=?', [$identity, $identity])->fetch();
    }

    /**
     * Cuenta habilitada con rol, datos de paciente o médico y si tiene deuda. Se lee en cada petición.
     *
     * @param int $id usuario
     * @return array|false cuenta o false si no existe o está deshabilitada
     */
    public static function sessionAccount(int $id): array|false
    {
        return Database::run("SELECT u.id,u.nombre_usuario,u.correo,u.password_hash,
            r.codigo AS rol,r.nombre AS rol_nombre,p.id AS paciente_id,p.activo AS paciente_activo,
            COALESCE(p.nombres,m.nombres,u.nombre_usuario) AS nombre,
            CASE WHEN p.id IS NULL THEN false ELSE NOT fn_paciente_sin_deuda(p.id) END AS tiene_deuda
            FROM usuario u JOIN rol r ON r.id=u.rol_id
            LEFT JOIN paciente p ON p.usuario_id=u.id AND r.codigo='paciente'
            LEFT JOIN medico m ON m.usuario_id=u.id AND r.codigo='medico'
            WHERE u.id=? AND u.habilitado", [$id])->fetch();
    }

    /**
     * Inserta una cuenta nueva con su hash de contraseña.
     *
     * @param array $data datos ya validados
     * @param int $roleId rol asignado
     * @param string $hash hash de la contraseña
     * @return int identificador de la cuenta
     */
    public static function create(array $data, int $roleId, string $hash): int
    {
        return (int) Database::run('INSERT INTO usuario(rol_id,nombre_usuario,correo,password_hash,habilitado)
            VALUES(?,?,?,?,?) RETURNING id',
            [$roleId,$data['nombre_usuario'],$data['correo'],$hash,(bool) ($data['habilitado'] ?? true)])->fetchColumn();
    }

    /**
     * Actualiza nombre de usuario, correo, rol y estado de la cuenta (sin tocar la contraseña).
     *
     * @param int $id usuario
     * @param array $data datos ya validados
     * @param int $roleId rol asignado
     * @return void
     */
    public static function update(int $id, array $data, int $roleId): void
    {
        Database::run('UPDATE usuario SET nombre_usuario=?,correo=?,rol_id=?,habilitado=? WHERE id=?',
            [$data['nombre_usuario'],$data['correo'],$roleId,(bool) $data['habilitado'],$id]);
    }

    /**
     * Reemplaza el hash de la contraseña.
     *
     * @param int $id usuario
     * @param string $hash nuevo hash
     * @return void
     */
    public static function updatePassword(int $id, string $hash): void
    {
        Database::run('UPDATE usuario SET password_hash=? WHERE id=?', [$hash, $id]);
    }
}
