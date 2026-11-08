<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Database;

/**
 * Acceso a ausencias médicas (CU26): consulta, listado, citas afectadas e historial de gestión.
 */
final class AbsenceRepository
{
    /**
     * Busca una ausencia con el nombre del médico.
     *
     * @param int $id ausencia
     * @return array|false ausencia o false
     */
    public static function find(int $id): array|false
    {
        return Database::run("SELECT a.*,m.nombres || ' ' || m.apellidos AS medico FROM ausencia_medico a
            JOIN medico m ON m.id=a.medico_id WHERE a.id=?",[$id])->fetch();
    }
    /**
     * Listado de ausencias con el número de citas pendientes en cada una (50 por página).
     *
     * @param int $page página (desde 1)
     * @return array items, total y paginación
     */
    public static function listing(int $page): array
    {
        $total=(int) Database::run('SELECT count(*) FROM ausencia_medico')->fetchColumn();
        $items=Database::run("SELECT a.*,m.nombres || ' ' || m.apellidos AS medico,
            (SELECT count(*) FROM cita c WHERE c.medico_id=a.medico_id AND c.estado IN ('programada','confirmada')
              AND tstzrange(c.inicio,c.fin,'[)') && tstzrange(a.inicio,a.fin,'[)')) AS pendientes
            FROM ausencia_medico a JOIN medico m ON m.id=a.medico_id ORDER BY a.id DESC LIMIT 50 OFFSET ?",[($page-1)*50])->fetchAll();
        return ['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>max(1,(int) ceil($total/50))];
    }
    /**
     * Citas vigentes del médico que se cruzan con la ausencia (50 por página).
     *
     * @param array $absence ausencia con médico e intervalo
     * @param int $page página (desde 1)
     * @return array citas afectadas, total y paginación
     */
    public static function affected(array $absence, int $page): array
    {
        $where=" FROM cita c JOIN paciente p ON p.id=c.paciente_id WHERE c.medico_id=?
            AND c.estado IN ('programada','confirmada') AND tstzrange(c.inicio,c.fin,'[)') && tstzrange(?::timestamptz,?::timestamptz,'[)')";
        $values=[(int) $absence['medico_id'],$absence['inicio'],$absence['fin']];
        $total=(int) Database::run('SELECT count(*)'.$where,$values)->fetchColumn();
        $items=Database::run("SELECT c.*,p.nombres || ' ' || p.apellidos AS paciente".$where.' ORDER BY c.inicio,c.id LIMIT 50 OFFSET ?',
            [...$values,($page-1)*50])->fetchAll();
        return ['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>max(1,(int) ceil($total/50))];
    }
    /**
     * Gestiones registradas sobre las citas de una ausencia, más reciente primero.
     *
     * @param int $id ausencia
     * @return array gestiones
     */
    public static function history(int $id): array
    {
        return Database::run('SELECT * FROM gestion_ausencia WHERE ausencia_id=? ORDER BY id DESC LIMIT 50',[$id])->fetchAll();
    }
}
