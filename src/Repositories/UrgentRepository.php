<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Database;

/**
 * Solicitudes de cita urgente (CU21, CU22): consulta, creación, cambio de estado, historial y avisos al paciente.
 */
final class UrgentRepository
{
    /**
     * Busca una solicitud con los datos del paciente y de la cita vinculada, si la tiene.
     *
     * @param int $id solicitud
     * @param bool $lock si es true, bloquea la fila
     * @return array|false solicitud o false
     */
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
    /**
     * Listado paginado de solicitudes (50 por página), limitado al paciente propietario si se indica.
     *
     * @param int|null $owner usuario paciente, o null para todas
     * @param string $state "todas" o un estado concreto
     * @param int $page página (desde 1)
     * @return array items, total y paginación
     */
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
    /**
     * Busca una solicitud ya enviada con esa clave, para la idempotencia del formulario.
     *
     * @param int $patient paciente
     * @param string $key clave de envío
     * @return array|false solicitud previa o false
     */
    public static function sent(int $patient, string $key): array|false
    {
        return Database::run('SELECT id,motivo,contacto FROM solicitud_urgente WHERE paciente_id=? AND clave_envio=?',[$patient,$key])->fetch();
    }
    /**
     * Inserta una solicitud nueva en estado pendiente.
     *
     * @param int $patient paciente
     * @param string $reason motivo
     * @param string $contact teléfono de contacto
     * @param string $key clave de envío única
     * @return int identificador de la solicitud
     */
    public static function create(int $patient, string $reason, string $contact, string $key): int
    {
        return (int) Database::run('INSERT INTO solicitud_urgente(paciente_id,motivo,contacto,clave_envio)
            VALUES(?,?,?,?) RETURNING id',[$patient,$reason,$contact,$key])->fetchColumn();
    }
    /**
     * Actualiza el estado, la resolución, el responsable y la cita vinculada de una solicitud.
     *
     * @param int $id solicitud
     * @param string $state nuevo estado
     * @param string $resolution resolución escrita
     * @param int $actor usuario que gestiona
     * @param int|null $appointment cita asignada, o null
     * @return void
     */
    public static function change(int $id, string $state, string $resolution, int $actor, ?int $appointment): void
    {
        Database::run('UPDATE solicitud_urgente SET estado=?,resolucion=?,gestionado_por=?,cita_id=? WHERE id=?',
            [$state,$resolution,$actor,$appointment,$id]);
    }
    /**
     * Agrega una entrada al historial de la solicitud (cada gestión queda registrada).
     *
     * @param int $id solicitud
     * @param int $actor usuario
     * @param string $action acción realizada
     * @param string $resolution texto de la resolución
     * @return void
     */
    public static function record(int $id, int $actor, string $action, string $resolution): void
    {
        Database::run('INSERT INTO historial_solicitud_urgente(solicitud_id,actor_id,accion,resolucion) VALUES(?,?,?,?)',
            [$id,$actor,$action,$resolution]);
    }
    /**
     * Historial de gestiones de una solicitud, más reciente primero.
     *
     * @param int $id solicitud
     * @return array entradas del historial
     */
    public static function history(int $id): array
    {
        return Database::run('SELECT accion,resolucion,creado_en FROM historial_solicitud_urgente WHERE solicitud_id=? ORDER BY id DESC',[$id])->fetchAll();
    }
    /**
     * Crea un aviso para el paciente. La clave de evento es única en la base: si la misma operación se repite, falla en lugar de duplicar el aviso.
     *
     * @param int $patient paciente
     * @param int|null $appointment cita relacionada, o null
     * @param string $key clave única del evento
     * @param string $type tipo de aviso
     * @param string $text texto del aviso, sin datos clínicos
     * @return void
     */
    public static function notify(int $patient, ?int $appointment, string $key, string $type, string $text): void
    {
        Database::run('INSERT INTO aviso(paciente_id,cita_id,clave_evento,tipo,texto) VALUES(?,?,?,?,?)',
            [$patient,$appointment,$key,$type,$text]);
    }
}
