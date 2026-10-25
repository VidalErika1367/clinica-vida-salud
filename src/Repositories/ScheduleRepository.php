<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Database;

final class ScheduleRepository
{
    public static function doctors(bool $activeOnly=false, ?int $current=null): array
    {
        return Database::run('SELECT id,nombres,apellidos,activo FROM medico'
            . ($activeOnly ? ' WHERE activo OR id=?' : '') . ' ORDER BY apellidos,nombres,id',
            $activeOnly ? [$current] : [])->fetchAll();
    }
    public static function search(int $doctorId, int $day, string $state): array
    {
        $filters=[]; $values=[];
        if ($doctorId>0) { $filters[]='h.medico_id=?'; $values[]=$doctorId; }
        if ($day>0) { $filters[]='h.dia_semana=?'; $values[]=$day; }
        if ($state==='activos') { $filters[]='h.activo'; }
        if ($state==='inactivos') { $filters[]='NOT h.activo'; }
        return Database::run("SELECT h.*,m.nombres || ' ' || m.apellidos AS medico,m.activo AS medico_activo
            FROM horario h JOIN medico m ON m.id=h.medico_id"
            . ($filters ? ' WHERE ' . implode(' AND ',$filters) : '')
            . ' ORDER BY m.apellidos,m.nombres,h.dia_semana,h.hora_inicio,h.id LIMIT 200',$values)->fetchAll();
    }
    public static function find(int $id, bool $lock=false): array|false
    {
        return Database::run("SELECT h.*,m.nombres || ' ' || m.apellidos AS medico,m.activo AS medico_activo
            FROM horario h JOIN medico m ON m.id=h.medico_id WHERE h.id=?"
            . ($lock ? ' FOR UPDATE OF h' : ''),[$id])->fetch();
    }
    public static function save(array $data, ?int $id): int
    {
        $values=[(int) $data['medico_id'],(int) $data['dia_semana'],$data['hora_inicio'],$data['hora_fin'],$data['activo']];
        if ($id===null) {
            return (int) Database::run('INSERT INTO horario(medico_id,dia_semana,hora_inicio,hora_fin,activo)
                VALUES(?,?,?,?,?) RETURNING id',$values)->fetchColumn();
        }
        Database::run('UPDATE horario SET medico_id=?,dia_semana=?,hora_inicio=?,hora_fin=?,activo=? WHERE id=?',
            [...$values,$id]);
        return $id;
    }
    public static function uncovered(array $doctorIds): array
    {
        $marks=implode(',',array_fill(0,count($doctorIds),'?'));
        return Database::run("SELECT c.id,c.inicio,c.fin,c.estado,p.nombres || ' ' || p.apellidos AS paciente,
            m.nombres || ' ' || m.apellidos AS medico,e.nombre AS especialidad
            FROM cita c JOIN paciente p ON p.id=c.paciente_id JOIN medico m ON m.id=c.medico_id
            JOIN especialidad e ON e.id=c.especialidad_id
            WHERE c.medico_id IN (" . $marks . ") AND c.estado IN ('programada','confirmada') AND c.fin>now()
            AND NOT EXISTS(SELECT 1 FROM horario h WHERE h.medico_id=c.medico_id AND h.activo
                AND h.dia_semana=extract(isodow FROM c.inicio AT TIME ZONE 'America/La_Paz')
                AND (c.inicio AT TIME ZONE 'America/La_Paz')::date=(c.fin AT TIME ZONE 'America/La_Paz')::date
                AND h.hora_inicio<=(c.inicio AT TIME ZONE 'America/La_Paz')::time
                AND h.hora_fin>=(c.fin AT TIME ZONE 'America/La_Paz')::time)
            ORDER BY c.inicio,c.id",$doctorIds)->fetchAll();
    }
}
