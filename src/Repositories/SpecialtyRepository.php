<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Database;

/**
 * Acceso al catálogo de especialidades (CU09, CU08, CU11). Las especialidades inactivas no se borran.
 */
final class SpecialtyRepository
{
    /**
     * Busca especialidades por nombre, con filtro de estado (máximo 200).
     *
     * @param string $query texto de búsqueda
     * @param string $state "activos", "inactivos" o cualquier otro valor para todos
     * @return array especialidades
     */
    public static function search(string $query, string $state): array
    {
        $filter = $state === 'activos' ? ' AND activo' : ($state === 'inactivos' ? ' AND NOT activo' : '');
        return Database::run('SELECT * FROM especialidad WHERE lower(nombre) LIKE ?' . $filter
            . ' ORDER BY nombre,id LIMIT 200', ['%' . mb_strtolower($query) . '%'])->fetchAll();
    }
    /**
     * Busca una especialidad por id.
     *
     * @param int $id especialidad
     * @param bool $lock si es true, bloquea la fila
     * @return array|false especialidad o false
     */
    public static function find(int $id, bool $lock = false): array|false
    {
        return Database::run('SELECT * FROM especialidad WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [$id])->fetch();
    }
    /**
     * Especialidades activas para los selectores de formularios y filtros.
     *
     * @return array especialidades activas
     */
    public static function active(): array
    {
        return Database::run('SELECT * FROM especialidad WHERE activo ORDER BY nombre,id')->fetchAll();
    }
    /**
     * Inserta o actualiza una especialidad. La descripción vacía se guarda como NULL.
     *
     * @param array $data datos ya validados
     * @param int|null $id especialidad; null para alta
     * @return int identificador
     */
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
    /**
     * Médicos asociados a la especialidad, con la marca de si la asociación está activa.
     *
     * @param int $id especialidad
     * @return array médicos
     */
    public static function doctors(int $id): array
    {
        return Database::run('SELECT m.id,m.nombres,m.apellidos,m.activo,me.activo AS asignada
            FROM medico_especialidad me JOIN medico m ON m.id=me.medico_id
            WHERE me.especialidad_id=? ORDER BY m.apellidos,m.nombres', [$id])->fetchAll();
    }
}
