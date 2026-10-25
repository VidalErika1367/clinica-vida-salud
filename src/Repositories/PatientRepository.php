<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Database;

final class PatientRepository
{
    public static function search(string $query, string $state): array
    {
        $filter = match ($state) {
            'activos' => ' AND p.activo',
            'inactivos' => ' AND NOT p.activo',
            default => '',
        };
        $pattern = '%' . mb_strtolower($query) . '%';
        return Database::run('SELECT p.id,p.ci,p.nombres,p.apellidos,p.telefono,p.activo
            FROM paciente p WHERE (lower(p.ci) LIKE ? OR lower(p.nombres) LIKE ?
            OR lower(p.apellidos) LIKE ? OR lower(p.nombres || \' \' || p.apellidos) LIKE ?)'
            . $filter . ' ORDER BY p.apellidos,p.nombres,p.id LIMIT 200',
            [$pattern,$pattern,$pattern,$pattern])->fetchAll();
    }

    public static function find(int $id, bool $lock = false): array|false
    {
        return Database::run('SELECT p.*,u.nombre_usuario,u.habilitado AS cuenta_habilitada
            FROM paciente p LEFT JOIN usuario u ON u.id=p.usuario_id WHERE p.id=?'
            . ($lock ? ' FOR UPDATE OF p' : ''), [$id])->fetch();
    }

    public static function create(array $data): int
    {
        return (int) Database::run('INSERT INTO paciente(ci,nombres,apellidos,fecha_nacimiento,
            sexo,telefono,correo,direccion) VALUES(?,?,?,?,?,?,?,?) RETURNING id',
            self::values($data))->fetchColumn();
    }

    private static function values(array $data): array
    {
        return [$data['ci'],$data['nombres'],$data['apellidos'],
            $data['fecha_nacimiento'] === '' ? null : $data['fecha_nacimiento'],
            $data['sexo'] === '' ? null : $data['sexo'],$data['telefono'],
            $data['correo'] === '' ? null : $data['correo'],
            $data['direccion'] === '' ? null : $data['direccion']];
    }

    public static function update(int $id, array $data): void
    {
        Database::run('UPDATE paciente SET ci=?,nombres=?,apellidos=?,fecha_nacimiento=?,
            sexo=?,telefono=?,correo=?,direccion=? WHERE id=?', [...self::values($data), $id]);
    }

    public static function deactivate(int $id): void
    {
        Database::run('UPDATE paciente SET activo=false WHERE id=?', [$id]);
    }

    public static function summary(int $id): array
    {
        return [
            'citas' => (int) Database::run('SELECT count(*) FROM cita WHERE paciente_id=?', [$id])->fetchColumn(),
            'vigentes' => (int) Database::run("SELECT count(*) FROM cita WHERE paciente_id=?
                AND estado IN ('programada','confirmada') AND fin>=now()", [$id])->fetchColumn(),
            'multas_pendientes' => (int) Database::run("SELECT count(*) FROM multa m
                JOIN incumplimiento i ON i.id=m.incumplimiento_id JOIN cita c ON c.id=i.cita_id
                WHERE c.paciente_id=? AND m.estado='pendiente'", [$id])->fetchColumn(),
        ];
    }

    public static function saveAccountIdentity(int $userId, array $data): void
    {
        Database::run('INSERT INTO paciente(usuario_id,ci,nombres,apellidos,telefono,correo) VALUES(?,?,?,?,?,?)
            ON CONFLICT(usuario_id) DO UPDATE SET ci=EXCLUDED.ci,nombres=EXCLUDED.nombres,
            apellidos=EXCLUDED.apellidos,telefono=EXCLUDED.telefono,correo=EXCLUDED.correo',
            [$userId,$data['ci'],$data['nombres'],$data['apellidos'],$data['telefono'],$data['correo']]);
    }
}
