<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Database;

/**
 * Acceso a la tabla cita y a sus procedimientos (CU12-CU17, CU26). Las reglas de estado y de auditoría
 * viven en la base: procedimientos sp_* y disparadores tr_*. Este repositorio solo las invoca.
 */
final class AppointmentRepository
{
    /**
     * Busca una cita con datos del paciente, médico y especialidad.
     *
     * @param int $id cita
     * @param bool $lock si es true, bloquea la fila de la cita (FOR UPDATE)
     * @return array|false fila de la cita o false si no existe
     */
    public static function find(int $id, bool $lock=false): array|false
    {
        return Database::run("SELECT c.*,c.inicio>now() AS futura,c.fin>now() AS vigente,
            p.usuario_id AS propietario_id,p.activo AS paciente_activo,p.ci,m.usuario_id AS medico_usuario_id,
            p.nombres || ' ' || p.apellidos AS paciente,m.nombres || ' ' || m.apellidos AS medico,
            e.nombre AS especialidad FROM cita c JOIN paciente p ON p.id=c.paciente_id
            JOIN medico m ON m.id=c.medico_id JOIN especialidad e ON e.id=c.especialidad_id WHERE c.id=?"
            . ($lock ? ' FOR UPDATE OF c' : ''),[$id])->fetch();
    }
    /**
     * Busca citas por CI, nombre o código CM, con filtro de propietario, médico y estado. Máximo 200 filas.
     *
     * @param int|null $owner usuario paciente propietario, o null para no filtrar
     * @param string $query texto de búsqueda (se compara en minúsculas)
     * @param string $state "vigentes", un estado de cita o cualquier otro valor para no filtrar
     * @param int|null $doctorUser usuario médico, o null
     * @return array citas
     */
    public static function search(?int $owner, string $query, string $state, ?int $doctorUser=null): array
    {
        $filter=$owner===null ? '' : ' AND p.usuario_id=?';
        $values=['%'.mb_strtolower($query).'%','%'.mb_strtolower($query).'%'];
        if ($owner!==null) { $values[]=$owner; }
        if ($doctorUser!==null) { $filter.=' AND m.usuario_id=?'; $values[]=$doctorUser; }
        if ($state==='vigentes') { $filter.=" AND c.estado IN ('programada','confirmada') AND c.fin>now()"; }
        elseif (in_array($state,['programada','confirmada','atendida','cancelada','inasistencia'],true)) {
            $filter.=' AND c.estado=?'; $values[]=$state;
        }
        return Database::run("SELECT c.id,c.inicio,c.fin,c.estado,p.nombres || ' ' || p.apellidos AS paciente,
            m.nombres || ' ' || m.apellidos AS medico,e.nombre AS especialidad FROM cita c
            JOIN paciente p ON p.id=c.paciente_id JOIN medico m ON m.id=c.medico_id JOIN especialidad e ON e.id=c.especialidad_id
            WHERE (lower(p.ci || ' ' || p.nombres || ' ' || p.apellidos) LIKE ?
            OR lower('CM-' || lpad(c.id::text,6,'0')) LIKE ?)" . $filter . ' ORDER BY c.inicio DESC,c.id DESC LIMIT 200',$values)->fetchAll();
    }
    /**
     * Fija el usuario responsable de la transacción actual para que los disparadores de auditoría lo registren.
     *
     * @param int $user usuario que opera
     * @return void
     */
    public static function actor(int $user): void { Database::run("SELECT set_config('app.actor_id',?::text,true)",[(string) $user]); }
    /**
     * Crea una cita ordinaria mediante sp_programar_cita, que valida el turno en la base.
     *
     * @param int $patient paciente
     * @param int $doctor médico
     * @param int $specialty especialidad
     * @param string $start inicio en formato ISO 8601
     * @param int $actor usuario que reserva
     * @param string $reason motivo opcional
     * @return int identificador de la cita creada
     * @throws \PDOException si el procedimiento rechaza la reserva
     */
    public static function create(int $patient, int $doctor, int $specialty, string $start, int $actor, string $reason): int
    {
        Database::run('CALL sp_programar_cita(?,?,?,?,?,?)',[$patient,$doctor,$specialty,$start,$actor,$reason ?: null]);
        return (int) Database::run('SELECT id FROM cita WHERE paciente_id=? AND medico_id=? AND inicio=? AND creado_por=?
            ORDER BY id DESC LIMIT 1',[$patient,$doctor,$start,$actor])->fetchColumn();
    }
    /**
     * Marca la cita como confirmada por el paciente (CU13).
     *
     * @param int $id cita
     * @return void
     */
    public static function confirm(int $id): void
    {
        // La ratificación es del paciente; la causa de una reprogramación previa queda en su historial.
        Database::run("UPDATE cita SET estado='confirmada',origen_cambio='paciente',justificada=false WHERE id=?",[$id]);
    }
    /**
     * Mueve la cita a un nuevo médico y turno (CU14). Restablece el estado a programada y elimina el aviso de cancelación previo.
     *
     * @param int $id cita
     * @param int $doctor nuevo médico
     * @param string $start nuevo inicio
     * @param string $reason motivo del cambio
     * @param string $origin "paciente" o "clinica"
     * @param bool $justified si el cambio está justificado
     * @return void
     */
    public static function reschedule(int $id, int $doctor, string $start, string $reason, string $origin, bool $justified): void
    {
        Database::run("UPDATE cita SET medico_id=?,inicio=?,fin=(?::timestamptz+interval '30 minutes'),
            estado='programada',motivo_cambio=?,origen_cambio=?,justificada=?,aviso_cancelacion_en=NULL WHERE id=?",
            [$doctor,$start,$start,$reason,$origin,$justified,$id]);
    }
    /**
     * Cancela la cita mediante sp_cancelar_cita, que libera el horario y conserva el registro (CU15).
     *
     * @param int $id cita
     * @param int $actor usuario que cancela
     * @param string $reason motivo
     * @param string $origin "paciente" o "clinica"
     * @param bool $justified si está justificada
     * @param string|null $notice fecha del aviso previo, si existe
     * @return void
     */
    public static function cancel(int $id, int $actor, string $reason, string $origin, bool $justified, ?string $notice): void
    {
        Database::run('CALL sp_cancelar_cita(?,?,?,?,?,?)',[$id,$actor,$reason,$origin,$justified,$notice]);
    }
    /**
     * Marca la cita como atendida (CU16).
     *
     * @param int $id cita
     * @return void
     */
    public static function attend(int $id): void
    {
        // El historial y el aviso se guardan por los triggers en la misma transacción.
        Database::run("UPDATE cita SET estado='atendida' WHERE id=?",[$id]);
    }
    /**
     * Crea una cita de tipo urgente asignada desde una solicitud (CU22). Dura 30 minutos desde el inicio.
     *
     * @param int $patient paciente
     * @param int $doctor médico
     * @param int $specialty especialidad
     * @param string $start inicio en formato ISO 8601
     * @param int $actor usuario de recepción
     * @param string $reason motivo, recortado a 250 caracteres
     * @return int identificador de la cita
     */
    public static function createUrgent(int $patient, int $doctor, int $specialty, string $start, int $actor, string $reason): int
    {
        return (int) Database::run("INSERT INTO cita(paciente_id,medico_id,especialidad_id,inicio,fin,tipo,motivo,creado_por)
            VALUES(?,?,?,?,?::timestamptz+interval '30 minutes','urgente',?,?) RETURNING id",
            [$patient,$doctor,$specialty,$start,$start,mb_substr($reason,0,250),$actor])->fetchColumn();
    }
    /**
     * Marca la cita como inasistencia (CU17). Las sanciones las genera la base mediante disparadores.
     *
     * @param int $id cita
     * @param string $reason motivo
     * @param string $origin "paciente" o "clinica"
     * @param bool $justified si está justificada
     * @return void
     */
    public static function noShow(int $id, string $reason, string $origin, bool $justified): void
    {
        Database::run("UPDATE cita SET estado='inasistencia',motivo_cambio=?,origen_cambio=?,justificada=? WHERE id=?",
            [$reason,$origin,$justified,$id]);
    }
    /**
     * Indica si el médico de la cita tenía una ausencia registrada que se cruza con el turno.
     *
     * @param array $row cita con médico, inicio y fin
     * @return bool
     */
    public static function doctorAbsent(array $row): bool
    {
        return (bool) Database::run("SELECT EXISTS(SELECT 1 FROM ausencia_medico
            WHERE medico_id=? AND tstzrange(inicio,fin,'[)') && tstzrange(?::timestamptz,?::timestamptz,'[)'))",
            [(int) $row['medico_id'],$row['inicio'],$row['fin']])->fetchColumn();
    }
    /**
     * Indica si el paciente tiene multas pendientes, según la función de la base fn_paciente_sin_deuda.
     *
     * @param int $patient paciente
     * @return bool true si tiene deuda
     */
    public static function debt(int $patient): bool
    {
        return !(bool) Database::run('SELECT fn_paciente_sin_deuda(?)',[$patient])->fetchColumn();
    }
    /**
     * Parámetros vigentes del sistema: plazo de cancelación en horas y valor de la multa.
     *
     * @return array|false parámetros
     */
    public static function policy(): array { return Database::run('SELECT plazo_cancelacion_horas,multa_bs FROM parametro_sistema WHERE id=1')->fetch(); }
    /**
     * Historial de cambios de una cita, 20 por página, más reciente primero.
     *
     * @param int $id cita
     * @param int $page página (desde 1)
     * @return array filas del historial; se pide una más para saber si hay página siguiente
     */
    public static function history(int $id, int $page=1): array
    {
        return Database::run("SELECT h.accion,h.datos_antes,h.datos_despues,h.creado_en,
            ma.nombres || ' ' || ma.apellidos AS medico_anterior,md.nombres || ' ' || md.apellidos AS medico_nuevo
            FROM historial_cita h LEFT JOIN medico ma ON ma.id=(h.datos_antes->>'medico_id')::bigint
            LEFT JOIN medico md ON md.id=(h.datos_despues->>'medico_id')::bigint
            WHERE h.cita_id=? ORDER BY h.id DESC LIMIT 21 OFFSET ?",[$id,($page-1)*20])->fetchAll();
    }
    /**
     * Incidencia registrada para la cita (inasistencia) y su multa, si existe.
     *
     * @param int $id cita
     * @return array|false incidencia o false si no hay
     */
    public static function incident(int $id): array|false
    {
        return Database::run('SELECT i.id,i.tipo,m.monto,m.estado FROM incumplimiento i
            LEFT JOIN multa m ON m.incumplimiento_id=i.id WHERE i.cita_id=?',[$id])->fetch();
    }
}
