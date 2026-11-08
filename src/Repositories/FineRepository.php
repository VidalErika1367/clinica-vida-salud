<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Database;

/**
 * Acceso a incumplimientos, multas y pagos (CU23, CU24). Los pagos se registran con sp_registrar_pago.
 */
final class FineRepository
{
    /**
     * Busca una multa con su incumplimiento, paciente y pago registrado.
     *
     * @param int $id multa
     * @param bool $lock si es true, bloquea la fila de la multa (FOR UPDATE)
     * @return array|false multa o false
     */
    public static function find(int $id, bool $lock=false): array|false
    {
        return Database::run("SELECT mu.*,i.cita_id,i.tipo,c.paciente_id,p.ci,p.activo AS paciente_activo,
            p.nombres || ' ' || p.apellidos AS paciente,pm.pagado_en AS pago_fecha,pm.comprobante AS pago_comprobante
            FROM multa mu JOIN incumplimiento i ON i.id=mu.incumplimiento_id
            JOIN cita c ON c.id=i.cita_id JOIN paciente p ON p.id=c.paciente_id
            LEFT JOIN pago_multa pm ON pm.multa_id=mu.id WHERE mu.id=?"
            .($lock ? ' FOR UPDATE OF mu' : ''),[$id])->fetch();
    }
    /**
     * Resumen de advertencias, multas pendientes y pagadas, y saldo pendiente del paciente.
     * Porqué: el saldo se devuelve como texto para no perder precisión decimal.
     *
     * @param int $patient paciente
     * @return array contadores y saldo
     */
    public static function summary(int $patient): array
    {
        return Database::run("SELECT count(*) FILTER(WHERE mu.id IS NULL) AS advertencias,
            count(*) FILTER(WHERE mu.estado='pendiente') AS pendientes,
            count(*) FILTER(WHERE mu.estado='pagada') AS pagadas,
            COALESCE(sum(mu.monto) FILTER(WHERE mu.estado='pendiente'),0)::text AS saldo
            FROM incumplimiento i JOIN cita c ON c.id=i.cita_id LEFT JOIN multa mu ON mu.incumplimiento_id=i.id
            WHERE c.paciente_id=?",[$patient])->fetch();
    }
    /**
     * Listado de incumplimientos del paciente con su multa, si la hay (50 por página).
     * Las advertencias son incumplimientos sin multa.
     *
     * @param int $patient paciente
     * @param string $state "todas", "advertencias", "pendientes" o "pagadas"
     * @param int $page página (desde 1)
     * @return array items, total y paginación
     */
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
    /**
     * Registra el pago de una multa mediante sp_registrar_pago, que aplica las reglas de la base.
     *
     * @param int $id multa
     * @param int $actor usuario que registra
     * @param string $amount importe con dos decimales
     * @param string $receipt número de comprobante
     * @return void
     * @throws \PDOException si la base rechaza el pago (por ejemplo, comprobante repetido)
     */
    public static function pay(int $id, int $actor, string $amount, string $receipt): void
    {
        Database::run('CALL sp_registrar_pago(?,?,?::numeric,?)',[$id,$actor,$amount,$receipt]);
    }
}
