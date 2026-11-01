<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Database;

final class UrgentRepository
{
    public static function find(int $id, bool $lock=false): array|false
    {
        return Database::run("SELECT s.*,p.nombres || ' ' || p.apellidos AS paciente,p.ci,p.telefono,
            p.usuario_id AS propietario_id,p.activo AS paciente_activo,c.id AS cita_vinculada_id,
            c.inicio AS cita_inicio,c.estado AS cita_estado,m.nombres || ' ' || m.apellidos AS medico,
            e.nombre AS especialidad FROM solicitud_urgente s JOIN paciente p ON p.id=s.paciente_id
            LEFT JOIN cita c ON c.id=s.cita_id AND c.paciente_id=s.paciente_id
            LEFT JOIN medico m ON m.id=c.medico_id LEFT JOIN especialidad e ON e.id=c.especialidad_id
            WHERE s.id=?".($lock ? ' FOR UPDATE OF s' : ''),[$id])->fetch();
    }
    public static function listing(?int $owner, string $state, int $page): array
    {
        $where=' WHERE true'; $values=[];
        if ($owner!==null) { $where.=' AND p.usuario_id=?'; $values[]=$owner; }
        if ($state!=='todas') { $where.=' AND s.estado=?'; $values[]=$state; }
        $joins=' FROM solicitud_urgente s JOIN paciente p ON p.id=s.paciente_id';
        $total=(int) Database::run('SELECT count(*)'.$joins.$where,$values)->fetchColumn();
        $items=Database::run("SELECT s.id,s.estado,s.creado_en,s.cita_id,p.nombres || ' ' || p.apellidos AS paciente"
            .$joins.$where.' ORDER BY s.id DESC LIMIT 50 OFFSET ?',[...$values,($page-1)*50])->fetchAll();
        return ['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>max(1,(int) ceil($total/50))];
    }
    public static function sent(int $patient, string $key): array|false
    {
        return Database::run('SELECT id,motivo,contacto FROM solicitud_urgente WHERE paciente_id=? AND clave_envio=?',[$patient,$key])->fetch();
    }
    public static function create(int $patient, string $reason, string $contact, string $key): int
    {
        return (int) Database::run('INSERT INTO solicitud_urgente(paciente_id,motivo,contacto,clave_envio)
            VALUES(?,?,?,?) RETURNING id',[$patient,$reason,$contact,$key])->fetchColumn();
    }
    public static function change(int $id, string $state, string $resolution, int $actor, ?int $appointment): void
    {
        Database::run('UPDATE solicitud_urgente SET estado=?,resolucion=?,gestionado_por=?,cita_id=? WHERE id=?',
            [$state,$resolution,$actor,$appointment,$id]);
    }
    public static function record(int $id, int $actor, string $action, string $resolution): void
    {
        Database::run('INSERT INTO historial_solicitud_urgente(solicitud_id,actor_id,accion,resolucion) VALUES(?,?,?,?)',
            [$id,$actor,$action,$resolution]);
    }
    public static function history(int $id): array
    {
        return Database::run('SELECT accion,resolucion,creado_en FROM historial_solicitud_urgente WHERE solicitud_id=? ORDER BY id DESC',[$id])->fetchAll();
    }
    public static function notify(int $patient, ?int $appointment, string $key, string $type, string $text): void
    {
        Database::run('INSERT INTO aviso(paciente_id,cita_id,clave_evento,tipo,texto) VALUES(?,?,?,?,?)',
            [$patient,$appointment,$key,$type,$text]);
    }
}
