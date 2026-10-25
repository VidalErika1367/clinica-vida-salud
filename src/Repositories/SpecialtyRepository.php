<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Database;

final class SpecialtyRepository
{
    public static function search(string $query, string $state): array
    {
        $filter = $state === 'activos' ? ' AND activo' : ($state === 'inactivos' ? ' AND NOT activo' : '');
        return Database::run('SELECT * FROM especialidad WHERE lower(nombre) LIKE ?' . $filter
            . ' ORDER BY nombre,id LIMIT 200', ['%' . mb_strtolower($query) . '%'])->fetchAll();
    }
    public static function find(int $id, bool $lock = false): array|false
    {
        return Database::run('SELECT * FROM especialidad WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [$id])->fetch();
    }
    public static function active(): array
    {
        return Database::run('SELECT * FROM especialidad WHERE activo ORDER BY nombre,id')->fetchAll();
    }
    public static function save(array $data, ?int $id): int
    {
        if ($id === null) {
            return (int) Database::run('INSERT INTO especialidad(nombre,descripcion,activo) VALUES(?,?,?) RETURNING id',
                [$data['nombre'],$data['descripcion'] ?: null,$data['activo']])->fetchColumn();
        }
        Database::run('UPDATE especialidad SET nombre=?,descripcion=?,activo=? WHERE id=?',
            [$data['nombre'],$data['descripcion'] ?: null,$data['activo'],$id]);
        return $id;
    }
    public static function doctors(int $id): array
    {
        return Database::run('SELECT m.id,m.nombres,m.apellidos,m.activo,me.activo AS asignada
            FROM medico_especialidad me JOIN medico m ON m.id=me.medico_id
            WHERE me.especialidad_id=? ORDER BY m.apellidos,m.nombres', [$id])->fetchAll();
    }
}
