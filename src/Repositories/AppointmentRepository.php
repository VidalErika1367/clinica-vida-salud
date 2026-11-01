<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Database;

final class AppointmentRepository
{
    public static function find(int $id, bool $lock=false): array|false
    {
        return Database::run("SELECT c.*,c.inicio>now() AS futura,c.fin>now() AS vigente,
            p.usuario_id AS propietario_id,p.activo AS paciente_activo,p.ci,m.usuario_id AS medico_usuario_id,
            p.nombres || ' ' || p.apellidos AS paciente,m.nombres || ' ' || m.apellidos AS medico,
            e.nombre AS especialidad FROM cita c JOIN paciente p ON p.id=c.paciente_id
            JOIN medico m ON m.id=c.medico_id JOIN especialidad e ON e.id=c.especialidad_id WHERE c.id=?"
            . ($lock ? ' FOR UPDATE OF c' : ''),[$id])->fetch();
    }
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
    public static function actor(int $user): void { Database::run("SELECT set_config('app.actor_id',?::text,true)",[(string) $user]); }
    public static function create(int $patient, int $doctor, int $specialty, string $start, int $actor, string $reason): int
    {
        Database::run('CALL sp_programar_cita(?,?,?,?,?,?)',[$patient,$doctor,$specialty,$start,$actor,$reason ?: null]);
        return (int) Database::run('SELECT id FROM cita WHERE paciente_id=? AND medico_id=? AND inicio=? AND creado_por=?
            ORDER BY id DESC LIMIT 1',[$patient,$doctor,$start,$actor])->fetchColumn();
    }
    public static function confirm(int $id): void
    {
        // La ratificación es del paciente; la causa de una reprogramación previa queda en su historial.
        Database::run("UPDATE cita SET estado='confirmada',origen_cambio='paciente',justificada=false WHERE id=?",[$id]);
    }
    public static function reschedule(int $id, int $doctor, string $start, string $reason, string $origin, bool $justified): void
    {
        Database::run("UPDATE cita SET medico_id=?,inicio=?,fin=(?::timestamptz+interval '30 minutes'),
            estado='programada',motivo_cambio=?,origen_cambio=?,justificada=?,aviso_cancelacion_en=NULL WHERE id=?",
            [$doctor,$start,$start,$reason,$origin,$justified,$id]);
    }
    public static function cancel(int $id, int $actor, string $reason, string $origin, bool $justified, ?string $notice): void
    {
        Database::run('CALL sp_cancelar_cita(?,?,?,?,?,?)',[$id,$actor,$reason,$origin,$justified,$notice]);
    }
    public static function attend(int $id): void
    {
        // El historial y el aviso se guardan por los triggers en la misma transacción.
        Database::run("UPDATE cita SET estado='atendida' WHERE id=?",[$id]);
    }
    public static function createUrgent(int $patient, int $doctor, int $specialty, string $start, int $actor, string $reason): int
    {
        return (int) Database::run("INSERT INTO cita(paciente_id,medico_id,especialidad_id,inicio,fin,tipo,motivo,creado_por)
            VALUES(?,?,?,?,?::timestamptz+interval '30 minutes','urgente',?,?) RETURNING id",
            [$patient,$doctor,$specialty,$start,$start,mb_substr($reason,0,250),$actor])->fetchColumn();
    }
    public static function noShow(int $id, string $reason, string $origin, bool $justified): void
    {
        Database::run("UPDATE cita SET estado='inasistencia',motivo_cambio=?,origen_cambio=?,justificada=? WHERE id=?",
            [$reason,$origin,$justified,$id]);
    }
    public static function doctorAbsent(array $row): bool
    {
        return (bool) Database::run("SELECT EXISTS(SELECT 1 FROM ausencia_medico
            WHERE medico_id=? AND tstzrange(inicio,fin,'[)') && tstzrange(?::timestamptz,?::timestamptz,'[)'))",
            [(int) $row['medico_id'],$row['inicio'],$row['fin']])->fetchColumn();
    }
    public static function debt(int $patient): bool
    {
        return !(bool) Database::run('SELECT fn_paciente_sin_deuda(?)',[$patient])->fetchColumn();
    }
    public static function policy(): array { return Database::run('SELECT plazo_cancelacion_horas,multa_bs FROM parametro_sistema WHERE id=1')->fetch(); }
    public static function history(int $id, int $page=1): array
    {
        return Database::run("SELECT h.accion,h.datos_antes,h.datos_despues,h.creado_en,
            ma.nombres || ' ' || ma.apellidos AS medico_anterior,md.nombres || ' ' || md.apellidos AS medico_nuevo
            FROM historial_cita h LEFT JOIN medico ma ON ma.id=(h.datos_antes->>'medico_id')::bigint
            LEFT JOIN medico md ON md.id=(h.datos_despues->>'medico_id')::bigint
            WHERE h.cita_id=? ORDER BY h.id DESC LIMIT 21 OFFSET ?",[$id,($page-1)*20])->fetchAll();
    }
    public static function incident(int $id): array|false
    {
        return Database::run('SELECT i.id,i.tipo,m.monto,m.estado FROM incumplimiento i
            LEFT JOIN multa m ON m.incumplimiento_id=i.id WHERE i.cita_id=?',[$id])->fetch();
    }
}
