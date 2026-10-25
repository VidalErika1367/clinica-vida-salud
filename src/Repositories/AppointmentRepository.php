<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Database;

final class AppointmentRepository
{
    public static function find(int $id, bool $lock=false): array|false
    {
        return Database::run("SELECT c.*,c.inicio>now() AS futura,c.fin>now() AS vigente,
            p.usuario_id AS propietario_id,p.activo AS paciente_activo,p.ci,
            p.nombres || ' ' || p.apellidos AS paciente,m.nombres || ' ' || m.apellidos AS medico,
            e.nombre AS especialidad FROM cita c JOIN paciente p ON p.id=c.paciente_id
            JOIN medico m ON m.id=c.medico_id JOIN especialidad e ON e.id=c.especialidad_id WHERE c.id=?"
            . ($lock ? ' FOR UPDATE OF c' : ''),[$id])->fetch();
    }
    public static function search(?int $owner, string $query, string $state): array
    {
        $filter=$owner===null ? '' : ' AND p.usuario_id=?';
        $values=['%'.mb_strtolower($query).'%','%'.mb_strtolower($query).'%'];
        if ($owner!==null) { $values[]=$owner; }
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
    public static function debt(int $patient): bool
    {
        return !(bool) Database::run('SELECT fn_paciente_sin_deuda(?)',[$patient])->fetchColumn();
    }
    public static function policy(): array { return Database::run('SELECT plazo_cancelacion_horas,multa_bs FROM parametro_sistema WHERE id=1')->fetch(); }
    public static function history(int $id): array
    {
        return Database::run("SELECT h.accion,h.datos_antes,h.datos_despues,h.creado_en,
            ma.nombres || ' ' || ma.apellidos AS medico_anterior,md.nombres || ' ' || md.apellidos AS medico_nuevo
            FROM historial_cita h LEFT JOIN medico ma ON ma.id=(h.datos_antes->>'medico_id')::bigint
            LEFT JOIN medico md ON md.id=(h.datos_despues->>'medico_id')::bigint
            WHERE h.cita_id=? ORDER BY h.id DESC LIMIT 20",[$id])->fetchAll();
    }
    public static function incident(int $id): array|false
    {
        return Database::run('SELECT i.id,i.tipo,m.monto,m.estado FROM incumplimiento i
            LEFT JOIN multa m ON m.incumplimiento_id=i.id WHERE i.cita_id=?',[$id])->fetch();
    }
}
