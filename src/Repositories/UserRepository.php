<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Database;

final class UserRepository
{
    public static function lockAccounts(): void
    {
        Database::run('LOCK TABLE usuario IN SHARE ROW EXCLUSIVE MODE');
    }

    public static function enabledAdministratorCount(): int
    {
        return (int) Database::run("SELECT count(*) FROM usuario u JOIN rol r ON r.id=u.rol_id
            WHERE r.codigo='administrador' AND u.habilitado")->fetchColumn();
    }

    public static function find(int $id): array|false
    {
        return Database::run('SELECT u.id,u.rol_id,u.nombre_usuario,u.correo,u.habilitado,
            p.ci,p.nombres,p.apellidos,p.telefono
            FROM usuario u LEFT JOIN paciente p ON p.usuario_id=u.id WHERE u.id=?', [$id])->fetch();
    }

    public static function search(string $query): array
    {
        $pattern = '%' . mb_strtolower($query) . '%';
        return Database::run('SELECT u.id,u.nombre_usuario,u.correo,u.habilitado,r.nombre AS rol_nombre
            FROM usuario u JOIN rol r ON r.id=u.rol_id
            WHERE lower(u.nombre_usuario) LIKE ? OR lower(u.correo) LIKE ?
            ORDER BY u.id DESC LIMIT 200', [$pattern, $pattern])->fetchAll();
    }

    public static function counts(): array
    {
        return Database::run('SELECT count(*) AS total,
            count(*) FILTER(WHERE habilitado) AS habilitados,
            count(*) FILTER(WHERE NOT habilitado) AS deshabilitados FROM usuario')->fetch();
    }

    public static function credentials(string $identity): array|false
    {
        $identity = mb_strtolower(trim($identity));
        return Database::run('SELECT id,password_hash,habilitado FROM usuario
            WHERE lower(nombre_usuario)=? OR lower(btrim(correo))=?', [$identity, $identity])->fetch();
    }

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

    public static function create(array $data, int $roleId, string $hash): int
    {
        return (int) Database::run('INSERT INTO usuario(rol_id,nombre_usuario,correo,password_hash,habilitado)
            VALUES(?,?,?,?,?) RETURNING id',
            [$roleId,$data['nombre_usuario'],$data['correo'],$hash,(bool) ($data['habilitado'] ?? true)])->fetchColumn();
    }

    public static function update(int $id, array $data, int $roleId): void
    {
        Database::run('UPDATE usuario SET nombre_usuario=?,correo=?,rol_id=?,habilitado=? WHERE id=?',
            [$data['nombre_usuario'],$data['correo'],$roleId,(bool) $data['habilitado'],$id]);
    }

    public static function updatePassword(int $id, string $hash): void
    {
        Database::run('UPDATE usuario SET password_hash=? WHERE id=?', [$hash, $id]);
    }
}
