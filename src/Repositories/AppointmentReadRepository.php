<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Database;

/**
 * Consultas de lectura para agenda (CU18), historial (CU19) y reportes (CU20). Solo SELECT; sin escrituras.
 * Todos los filtros van como parámetros; los nombres de columna se toman de listas fijas del código.
 */
final class AppointmentReadRepository
{
    /** Uniones comunes de cita con paciente, médico y especialidad. */
    private const JOINS=" FROM cita c JOIN paciente p ON p.id=c.paciente_id
        JOIN medico m ON m.id=c.medico_id JOIN especialidad e ON e.id=c.especialidad_id ";

    /**
     * Médicos para los filtros; con usuario, solo el vinculado a esa cuenta.
     *
     * @param int|null $userId usuario médico, o null para todos
     * @return array médicos con id, nombre y estado
     */
    public static function doctors(?int $userId=null): array
    {
        return Database::run("SELECT id,nombres || ' ' || apellidos AS nombre,activo FROM medico"
            . ($userId===null ? '' : ' WHERE usuario_id=?') . ' ORDER BY apellidos,nombres,id',
            $userId===null ? [] : [$userId])->fetchAll();
    }
    /**
     * Primera fecha con actividad del paciente, para usarla como inicio del historial. Nunca es posterior a hoy.
     *
     * @param int $patientId paciente
     * @return string fecha Y-m-d
     */
    public static function firstDate(int $patientId): string
    {
        return Database::run('SELECT LEAST(COALESCE(min(inicio)::date,current_date),current_date)::text FROM cita WHERE paciente_id=?',[$patientId])->fetchColumn();
    }
    /**
     * Construye la cláusula WHERE con parámetros. Solo añade condiciones de columnas conocidas.
     *
     * @param array $filters filtros normalizados
     * @param string $dateColumn columna de fecha a filtrar
     * @return array{0:string,1:array} cláusula y valores en orden
     */
    private static function where(array $filters, string $dateColumn='c.inicio'): array
    {
        $where=["$dateColumn>=?::date","$dateColumn<(?::date+1)"];
        $values=[$filters['desde'],$filters['hasta']];
        foreach (['medico_id'=>'c.medico_id','especialidad_id'=>'c.especialidad_id',
            'paciente_id'=>'c.paciente_id','propietario_id'=>'p.usuario_id','medico_usuario_id'=>'m.usuario_id'] as $key=>$column) {
            if (isset($filters[$key])) { $where[]="$column=?"; $values[]=$filters[$key]; }
        }
        if ($dateColumn==='c.inicio' && $filters['estado']!=='todos') {
            $where[]='c.estado=?'; $values[]=$filters['estado'];
        }
        return [' WHERE '.implode(' AND ',$where),$values];
    }
    /**
     * Listado paginado de citas (50 por página) con sus totales.
     *
     * @param array $filters filtros normalizados
     * @param int $page página (desde 1)
     * @return array items, total, página y número de páginas
     */
    public static function appointments(array $filters, int $page): array
    {
        [$where,$values]=self::where($filters);
        $total=(int) Database::run('SELECT count(*)'.self::JOINS.$where,$values)->fetchColumn();
        $items=Database::run("SELECT c.id,c.inicio,c.fin,c.estado,c.tipo,p.ci,
            p.nombres || ' ' || p.apellidos AS paciente,m.nombres || ' ' || m.apellidos AS medico,
            e.nombre AS especialidad,c.creado_en".self::JOINS.$where
            .' ORDER BY c.inicio DESC,c.id DESC LIMIT 50 OFFSET ?',[...$values,($page-1)*50])->fetchAll();
        return ['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>max(1,(int) ceil($total/50))];
    }
    /**
     * Totales de citas por estado dentro del rango (resumen del reporte de citas).
     *
     * @param array $filters filtros normalizados
     * @return array|false contadores
     */
    public static function activity(array $filters): array
    {
        [$where,$values]=self::where($filters);
        return Database::run("SELECT count(*) AS total,count(DISTINCT c.paciente_id) AS pacientes,
            count(*) FILTER(WHERE c.estado='programada') AS programadas,
            count(*) FILTER(WHERE c.estado='confirmada') AS confirmadas,
            count(*) FILTER(WHERE c.estado='atendida') AS atendidas,
            count(*) FILTER(WHERE c.estado='cancelada') AS canceladas,
            count(*) FILTER(WHERE c.estado='inasistencia') AS inasistencias".self::JOINS.$where,$values)->fetch();
    }
    /**
     * Pacientes dados de alta en el rango, con conteo de activos e inactivos (reporte de pacientes).
     *
     * @param array $filters filtros normalizados, incluido el estado del paciente
     * @param int $page página (desde 1)
     * @return array items, totales por estado y paginación
     */
    public static function registeredPatients(array $filters, int $page): array
    {
        $where=' WHERE p.creado_en>=?::date AND p.creado_en<(?::date+1)';
        $where.=match ($filters['estado_paciente']) {
            'activos'=>' AND p.activo',
            'inactivos'=>' AND NOT p.activo',
            default=>'',
        };
        $values=[$filters['desde'],$filters['hasta']];
        // La fecha de alta pertenece a la ficha: incluye pacientes sin cuenta ni citas.
        $summary=Database::run('SELECT count(*) AS total,count(*) FILTER(WHERE p.activo) AS activos,
            count(*) FILTER(WHERE NOT p.activo) AS inactivos FROM paciente p'.$where,$values)->fetch();
        $items=Database::run('SELECT p.id,p.ci,p.nombres,p.apellidos,p.activo,p.creado_en
            FROM paciente p'.$where.' ORDER BY p.creado_en DESC,p.id DESC LIMIT 50 OFFSET ?',
            [...$values,($page-1)*50])->fetchAll();
        return ['items'=>$items,'total'=>(int) $summary['total'],'activos'=>(int) $summary['activos'],
            'inactivos'=>(int) $summary['inactivos'],'page'=>$page,
            'pages'=>max(1,(int) ceil((int) $summary['total']/50))];
    }
    /**
     * Pagos de multas registrados en el rango (reporte de pagos). Una fila por pago.
     *
     * @param array $filters filtros normalizados
     * @param int $page página (desde 1)
     * @return array items, total de pagos, monto total y paginación
     */
    public static function payments(array $filters, int $page): array
    {
        [$where,$values]=self::where($filters,'pm.pagado_en');
        // Una fila por pago. Nunca se unen pagos con historial_cita ni con avisos.
        $joins=' FROM pago_multa pm JOIN multa mu ON mu.id=pm.multa_id
            JOIN incumplimiento i ON i.id=mu.incumplimiento_id JOIN cita c ON c.id=i.cita_id
            JOIN paciente p ON p.id=c.paciente_id JOIN medico m ON m.id=c.medico_id
            JOIN especialidad e ON e.id=c.especialidad_id ';
        $summary=Database::run('SELECT count(*) AS total,COALESCE(sum(pm.monto),0)::text AS monto'
            .$joins.$where,$values)->fetch();
        $items=Database::run("SELECT pm.id,pm.pagado_en,pm.monto,pm.comprobante,c.id AS cita_id,
            p.nombres || ' ' || p.apellidos AS paciente,m.nombres || ' ' || m.apellidos AS medico"
            .$joins.$where.' ORDER BY pm.pagado_en DESC,pm.id DESC LIMIT 50 OFFSET ?',
            [...$values,($page-1)*50])->fetchAll();
        return ['items'=>$items,'total'=>(int) $summary['total'],'monto'=>$summary['monto'],
            'page'=>$page,'pages'=>max(1,(int) ceil((int) $summary['total']/50))];
    }
    /**
     * Ausencias de médicos que se cruzan con el rango de fechas (se muestran en la agenda).
     *
     * @param array $filters filtros normalizados
     * @return array ausencias con médico, intervalo y motivo
     */
    public static function absences(array $filters): array
    {
        $where=["a.inicio<(?::date+1)",'a.fin>?::date']; $values=[$filters['hasta'],$filters['desde']];
        if (isset($filters['medico_id'])) { $where[]='a.medico_id=?'; $values[]=$filters['medico_id']; }
        if (isset($filters['medico_usuario_id'])) { $where[]='m.usuario_id=?'; $values[]=$filters['medico_usuario_id']; }
        return Database::run("SELECT a.inicio,a.fin,a.motivo,m.nombres || ' ' || m.apellidos AS medico
            FROM ausencia_medico a JOIN medico m ON m.id=a.medico_id WHERE ".implode(' AND ',$where)
            .' ORDER BY a.inicio,a.id',$values)->fetchAll();
    }
}
