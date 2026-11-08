<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Database;

/**
 * Acceso a médicos, sus especialidades y cuentas (CU08, CU10, CU26). Los filtros se pasan como parámetros.
 */
final class DoctorRepository
{
    /**
     * Busca médicos por registro profesional o nombre, con filtro de estado y especialidad activa (CU08).
     * El filtro de especialidad exige que la asociación y la especialidad estén activas. Máximo 200 filas.
     *
     * @param string $query texto de búsqueda
     * @param string $state "activos", "inactivos" o cualquier otro valor para todos
     * @param int|null $specialtyId especialidad a filtrar, o null
     * @return array médicos con su lista de especialidades activas
     */
    public static function search(string $query, string $state, ?int $specialtyId = null): array
    {
        $filter = $state === 'activos' ? ' AND m.activo' : ($state === 'inactivos' ? ' AND NOT m.activo' : '');
        $pattern = '%' . mb_strtolower($query) . '%';
        $params = [$pattern, $pattern];
        if ($specialtyId !== null) {
            $filter .= ' AND EXISTS(SELECT 1 FROM medico_especialidad mf JOIN especialidad ef ON ef.id=mf.especialidad_id
                WHERE mf.medico_id=m.id AND mf.especialidad_id=? AND mf.activo AND ef.activo)';
            $params[] = $specialtyId;
        }
        return Database::run("SELECT m.*,(SELECT string_agg(e.nombre,', ' ORDER BY e.nombre)
            FROM medico_especialidad me JOIN especialidad e ON e.id=me.especialidad_id
            WHERE me.medico_id=m.id AND me.activo AND e.activo) AS especialidades_actuales
            FROM medico m WHERE (lower(m.registro_profesional) LIKE ? OR lower(m.nombres || ' ' || m.apellidos) LIKE ?)"
            . $filter . ' ORDER BY m.apellidos,m.nombres,m.id LIMIT 200', $params)->fetchAll();
    }
    /**
     * Busca un médico con los datos de su cuenta de acceso, si la tiene.
     *
     * @param int $id médico
     * @param bool $lock si es true, bloquea la fila (FOR UPDATE)
     * @return array|false médico o false
     */
    public static function find(int $id, bool $lock = false): array|false
    {
        return Database::run('SELECT m.*,u.nombre_usuario,u.habilitado AS cuenta_habilitada,r.codigo AS rol_cuenta
            FROM medico m LEFT JOIN usuario u ON u.id=m.usuario_id LEFT JOIN rol r ON r.id=u.rol_id
            WHERE m.id=?' . ($lock ? ' FOR UPDATE OF m' : ''), [$id])->fetch();
    }
    /**
     * Especialidades asociadas al médico, incluidas las inactivas, con la marca de asignación.
     *
     * @param int $id médico
     * @return array especialidades
     */
    public static function specialties(int $id): array
    {
        return Database::run('SELECT e.id,e.nombre,e.activo,me.activo AS asignada FROM medico_especialidad me
            JOIN especialidad e ON e.id=me.especialidad_id WHERE me.medico_id=? ORDER BY e.id', [$id])->fetchAll();
    }
    /**
     * Cuentas de rol Médico habilitadas que aún no tienen ficha de médico, más la cuenta actual del médico editado.
     *
     * @param int|null $id médico en edición, o null para alta
     * @return array cuentas disponibles para vincular
     */
    public static function accounts(?int $id): array
    {
        return Database::run("SELECT u.id,u.nombre_usuario,u.habilitado,r.codigo FROM usuario u JOIN rol r ON r.id=u.rol_id
            LEFT JOIN medico m ON m.usuario_id=u.id
            WHERE (r.codigo='medico' AND u.habilitado AND m.id IS NULL) OR m.id=?
            ORDER BY u.nombre_usuario", [$id])->fetchAll();
    }
    /**
     * Indica si otro médico ya usa el mismo registro profesional, ignorando espacios y mayúsculas.
     *
     * @param string $registration registro normalizado en mayúsculas
     * @param int|null $id médico en edición, excluido de la comparación
     * @return bool
     */
    public static function equivalentRegistration(string $registration, ?int $id): bool
    {
        return (bool) Database::run("SELECT id FROM medico
            WHERE upper(regexp_replace(btrim(registro_profesional),'\s+',' ','g'))=? AND id<>?",
            [$registration,$id ?? 0])->fetch();
    }
    /**
     * Cuenta de acceso con su rol y el médico vinculado, si existe.
     *
     * @param int $id usuario
     * @return array|false cuenta o false
     */
    public static function account(int $id): array|false
    {
        return Database::run('SELECT u.id,u.habilitado,r.codigo,m.id AS medico_id
            FROM usuario u JOIN rol r ON r.id=u.rol_id LEFT JOIN medico m ON m.usuario_id=u.id WHERE u.id=?', [$id])->fetch();
    }
    /**
     * Bloqueo de transacción por médico (pg_advisory_xact_lock) para serializar reservas, cambios de horario y ausencias.
     * Porqué: la comprobación de turno libre y la escritura deben ocurrir sin que otra operación del mismo médico se cuele entre ambas.
     *
     * @param int $id médico
     * @return void
     */
    public static function lockForBooking(int $id): void
    {
        // La misma clave protege la comprobación de disponibilidad de una reserva.
        Database::run('SELECT pg_advisory_xact_lock(?)', [$id]);
    }
    /**
     * Inserta o actualiza los datos del médico (sin especialidades; ver assign()).
     *
     * @param array $data datos ya validados
     * @param int|null $id médico; null para alta
     * @return int identificador del médico
     */
    public static function save(array $data, ?int $id): int
    {
        $values = [$data['usuario_id'],$data['registro_profesional'],$data['nombres'],$data['apellidos'],
            $data['telefono'] ?: null,$data['correo'] ?: null,$data['activo']];
        if ($id === null) {
            return (int) Database::run('INSERT INTO medico(usuario_id,registro_profesional,nombres,apellidos,telefono,correo,activo)
                VALUES(?,?,?,?,?,?,?) RETURNING id', $values)->fetchColumn();
        }
        Database::run('UPDATE medico SET usuario_id=?,registro_profesional=?,nombres=?,apellidos=?,telefono=?,correo=?,activo=? WHERE id=?',
            [...$values,$id]);
        return $id;
    }
    /**
     * Reemplaza las especialidades del médico. Las no seleccionadas quedan inactivas, no se borran:
     * así se conserva el historial de citas que las usaron.
     *
     * @param int $id médico
     * @param int[] $specialties especialidades que quedan activas
     * @return void
     */
    public static function assign(int $id, array $specialties): void
    {
        Database::run('UPDATE medico_especialidad SET activo=false WHERE medico_id=?', [$id]);
        foreach ($specialties as $specialty) {
            Database::run('INSERT INTO medico_especialidad(medico_id,especialidad_id,activo) VALUES(?,?,true)
                ON CONFLICT(medico_id,especialidad_id) DO UPDATE SET activo=true', [$id,$specialty]);
        }
    }
    /**
     * Próximas citas (programadas o confirmadas) de un médico o de una especialidad, máximo 200.
     * La columna "revisar" marca citas cuyo médico, especialidad o asociación ya no está activa.
     *
     * @param int|null $doctorId médico, o null
     * @param int|null $specialtyId especialidad, o null; se usa si no hay médico
     * @return array citas
     */
    public static function upcoming(?int $doctorId = null, ?int $specialtyId = null): array
    {
        $where = $doctorId !== null ? 'c.medico_id=?' : 'c.especialidad_id=?';
        return Database::run("SELECT c.id,c.inicio,c.fin,c.estado,p.nombres || ' ' || p.apellidos AS paciente,
            m.nombres || ' ' || m.apellidos AS medico,e.nombre AS especialidad,
            NOT (m.activo AND e.activo AND me.activo) AS revisar
            FROM cita c JOIN paciente p ON p.id=c.paciente_id JOIN medico m ON m.id=c.medico_id
            JOIN especialidad e ON e.id=c.especialidad_id
            JOIN medico_especialidad me ON me.medico_id=c.medico_id AND me.especialidad_id=c.especialidad_id
            WHERE " . $where . " AND c.estado IN ('programada','confirmada') AND c.fin>=now()
            ORDER BY c.inicio,c.id LIMIT 200", [$doctorId ?? $specialtyId])->fetchAll();
    }
}
