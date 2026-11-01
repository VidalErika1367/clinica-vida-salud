<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Database;

final class FineRepository
{
    public static function find(int $id, bool $lock=false): array|false
    {
        return Database::run("SELECT mu.*,i.cita_id,i.tipo,c.paciente_id,p.ci,p.activo AS paciente_activo,
            p.nombres || ' ' || p.apellidos AS paciente,pm.pagado_en AS pago_fecha,pm.comprobante AS pago_comprobante
            FROM multa mu JOIN incumplimiento i ON i.id=mu.incumplimiento_id
            JOIN cita c ON c.id=i.cita_id JOIN paciente p ON p.id=c.paciente_id
            LEFT JOIN pago_multa pm ON pm.multa_id=mu.id WHERE mu.id=?"
            .($lock ? ' FOR UPDATE OF mu' : ''),[$id])->fetch();
    }
    public static function summary(int $patient): array
    {
        return Database::run("SELECT count(*) FILTER(WHERE mu.id IS NULL) AS advertencias,
            count(*) FILTER(WHERE mu.estado='pendiente') AS pendientes,
            count(*) FILTER(WHERE mu.estado='pagada') AS pagadas,
            COALESCE(sum(mu.monto) FILTER(WHERE mu.estado='pendiente'),0)::text AS saldo
            FROM incumplimiento i JOIN cita c ON c.id=i.cita_id LEFT JOIN multa mu ON mu.incumplimiento_id=i.id
            WHERE c.paciente_id=?",[$patient])->fetch();
    }
    public static function listing(int $patient, string $state, int $page): array
    {
        $where=' WHERE c.paciente_id=?'; $values=[$patient];
        if ($state==='advertencias') { $where.=' AND mu.id IS NULL'; }
        elseif ($state!=='todas') { $where.=' AND mu.estado=?'; $values[]=$state==='pendientes' ? 'pendiente' : 'pagada'; }
        $joins=' FROM incumplimiento i JOIN cita c ON c.id=i.cita_id LEFT JOIN multa mu ON mu.incumplimiento_id=i.id
            LEFT JOIN pago_multa pm ON pm.multa_id=mu.id';
        $total=(int) Database::run('SELECT count(*)'.$joins.$where,$values)->fetchColumn();
        $items=Database::run('SELECT i.id AS incidencia_id,i.tipo,i.creado_en,c.id AS cita_id,c.inicio,
            mu.id AS multa_id,mu.monto,mu.estado,pm.comprobante,pm.pagado_en'
            .$joins.$where.' ORDER BY i.id DESC LIMIT 50 OFFSET ?',[...$values,($page-1)*50])->fetchAll();
        return ['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>max(1,(int) ceil($total/50))];
    }
    public static function pay(int $id, int $actor, string $amount, string $receipt): void
    {
        Database::run('CALL sp_registrar_pago(?,?,?::numeric,?)',[$id,$actor,$amount,$receipt]);
    }
}
