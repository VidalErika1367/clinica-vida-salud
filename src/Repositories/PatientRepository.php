<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Database;

/**
 * Acceso a la tabla paciente (CU06, CU07, CU25). Las fichas nunca se borran; se desactivan.
 */
final class PatientRepository
{
    /**
     * Busca pacientes por CI, nombres, apellidos o nombre completo. Máximo 200 filas.
     *
     * @param string $query texto de búsqueda
     * @param string $state "activos", "inactivos" o cualquier otro valor para todos
     * @return array pacientes
     */
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

    /**
     * Busca un paciente con los datos de su cuenta de acceso, si la tiene.
     *
     * @param int $id paciente
     * @param bool $lock si es true, bloquea la fila (FOR UPDATE)
     * @return array|false paciente o false
     */
    public static function find(int $id, bool $lock = false): array|false
    {
        return Database::run('SELECT p.*,u.nombre_usuario,u.habilitado AS cuenta_habilitada
            FROM paciente p LEFT JOIN usuario u ON u.id=p.usuario_id WHERE p.id=?'
            . ($lock ? ' FOR UPDATE OF p' : ''), [$id])->fetch();
    }

    /**
     * Inserta un paciente nuevo.
     *
     * @param array $data datos ya validados
     * @return int identificador del paciente
     */
    public static function create(array $data): int
    {
        return (int) Database::run('INSERT INTO paciente(ci,nombres,apellidos,fecha_nacimiento,
            sexo,telefono,correo,direccion) VALUES(?,?,?,?,?,?,?,?) RETURNING id',
            self::values($data))->fetchColumn();
    }

    /**
     * Convierte las cadenas vacías en NULL para las columnas opcionales, en el orden de los parámetros SQL.
     *
     * @param array $data datos del paciente
     * @return array valores para INSERT o UPDATE
     */
    private static function values(array $data): array
    {
        return [$data['ci'],$data['nombres'],$data['apellidos'],
            $data['fecha_nacimiento'] === '' ? null : $data['fecha_nacimiento'],
            $data['sexo'] === '' ? null : $data['sexo'],$data['telefono'],
            $data['correo'] === '' ? null : $data['correo'],
            $data['direccion'] === '' ? null : $data['direccion']];
    }

    /**
     * Actualiza los datos administrativos de un paciente.
     *
     * @param int $id paciente
     * @param array $data datos ya validados
     * @return void
     */
    public static function update(int $id, array $data): void
    {
        Database::run('UPDATE paciente SET ci=?,nombres=?,apellidos=?,fecha_nacimiento=?,
            sexo=?,telefono=?,correo=?,direccion=? WHERE id=?', [...self::values($data), $id]);
    }

    /**
     * Desactiva el paciente. No borra citas, multas ni la cuenta.
     *
     * @param int $id paciente
     * @return void
     */
    public static function deactivate(int $id): void
    {
        Database::run('UPDATE paciente SET activo=false WHERE id=?', [$id]);
    }

    /**
     * Resumen para la ficha: total de citas, citas vigentes y multas pendientes.
     *
     * @param int $id paciente
     * @return array contadores
     */
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

    /**
     * Crea o actualiza la ficha vinculada a una cuenta de paciente (registro público, CU01).
     * Porqué: una cuenta tiene una sola ficha; el ON CONFLICT evita duplicados si se repite el registro.
     *
     * @param int $userId cuenta de usuario
     * @param array $data identidad y contacto
     * @return void
     */
    public static function saveAccountIdentity(int $userId, array $data): void
    {
        Database::run('INSERT INTO paciente(usuario_id,ci,nombres,apellidos,telefono,correo) VALUES(?,?,?,?,?,?)
            ON CONFLICT(usuario_id) DO UPDATE SET ci=EXCLUDED.ci,nombres=EXCLUDED.nombres,
            apellidos=EXCLUDED.apellidos,telefono=EXCLUDED.telefono,correo=EXCLUDED.correo',
            [$userId,$data['ci'],$data['nombres'],$data['apellidos'],$data['telefono'],$data['correo']]);
    }
}
