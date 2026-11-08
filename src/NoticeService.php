<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AuditRepository;
use App\Repositories\UserRepository;

/**
 * Avisos del sistema (CU28): listado con filtros, control de versión y reintento autorizado por recepción.
 * Los avisos no contienen diagnósticos; solo indican que hay un cambio y dónde consultarlo en la web.
 */
final class NoticeService
{
    /** Asunto visible de cada tipo de aviso. */
    public const SUBJECTS=['cambio_cita'=>'Cambio en su cita','advertencia'=>'Advertencia de cita','multa'=>'Multa pendiente',
        'solicitud_urgente'=>'Solicitud urgente recibida','gestion_urgente'=>'Gestión de solicitud urgente',
        'pago_multa'=>'Pago de multa registrado','ausencia_medico'=>'Cambio pendiente por ausencia médica',
        'telegram_vinculado'=>'Telegram vinculado','recordatorio'=>'Recordatorio de cita'];
    /**
     * Huella del aviso para control de versión al reintentar.
     *
     * @param array $row aviso
     * @return string SHA-256 en hexadecimal
     */
    public static function version(array $row): string
    {
        return CareSupport::version($row,['id','estado','intentos','ultimo_intento_en']);
    }
    /**
     * Lista avisos: el paciente solo ve los suyos; el personal puede filtrar por paciente y estado (CU28).
     *
     * @param array $actor usuario con su rol
     * @param array $input filtros de paciente, estado y página
     * @return array listado paginado y filtros aplicados
     * @throws HttpException 403 si un paciente pide avisos de otro
     * @throws ValidationException si los filtros no son válidos
     */
    public static function listing(array $actor, array $input): array
    {
        $where=' WHERE true'; $values=[];
        $patient=CareSupport::optionalId($input,'paciente_id');
        if ($actor['rol']==='paciente') {
            if ($patient!==null && $patient!==(int) $actor['paciente_id']) { throw new HttpException(403,'Solo puedes consultar tus propios avisos.'); }
            $where.=' AND p.usuario_id=?'; $values[]=(int) $actor['id'];
        } elseif ($patient!==null) { $where.=' AND a.paciente_id=?'; $values[]=$patient; }
        $state=is_string($input['estado'] ?? null) ? $input['estado'] : 'todos';
        if (!in_array($state,['todos','pendiente','enviado','error','omitido'],true)) { throw new ValidationException(['estado'=>'Selecciona un estado válido.']); }
        if ($state!=='todos') { $where.=' AND a.estado=?'; $values[]=$state; }
        $page=AppointmentReadService::page($input['pagina'] ?? null);
        $join=' FROM aviso a JOIN paciente p ON p.id=a.paciente_id LEFT JOIN cita c ON c.id=a.cita_id AND c.paciente_id=a.paciente_id';
        $total=(int) Database::run('SELECT count(*)'.$join.$where,$values)->fetchColumn();
        $items=Database::run("SELECT a.*,p.nombres || ' ' || p.apellidos AS paciente,c.id AS cita_visible,
            EXISTS(SELECT 1 FROM vinculo_telegram v WHERE v.paciente_id=a.paciente_id AND v.activo) AS vinculado"
            .$join.$where.' ORDER BY a.id DESC LIMIT 50 OFFSET ?',[...$values,($page-1)*50])->fetchAll();
        return ['listing'=>['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>max(1,(int) ceil($total/50))],
            'state'=>$state,'patientId'=>$patient];
    }
    /**
     * Autoriza el reintento de un aviso con error (CU28). Solo personal, máximo 10 intentos y con Telegram vinculado.
     * Porqué: la confirmación explícita existe porque un envío anterior podría haberse recibido aunque la API no lo confirmara.
     *
     * @param int $id aviso
     * @param array $input confirmación de reintento y versión
     * @param int $user usuario de recepción
     * @return void
     * @throws ValidationException si el aviso no admite reintento o el paciente no tiene Telegram activo
     * @throws HttpException 403 si lo solicita un paciente; 404 si no existe
     */
    public static function retry(int $id, array $input, int $user): void
    {
        CareSupport::confirm($input,'reintento_autorizado','Confirma el reintento: un envío anterior podría haberse recibido.');
        CareSupport::transaction(function () use ($id,$input,$user): void {
            UserRepository::lockAccounts(); $actor=AppointmentService::actor($user,'CU28');
            if ($actor['rol']==='paciente') { throw new HttpException(403,'Solicita a recepción la revisión del envío.'); }
            $row=Database::run('SELECT * FROM aviso WHERE id=? FOR UPDATE',[$id])->fetch();
            if (!$row) { throw new HttpException(404,'No se encontró el aviso.'); }
            CareSupport::checkVersion(self::version($row),$input['version'] ?? null);
            if ($row['estado']!=='error' || (int) $row['intentos']>=10) { throw new ValidationException(['general'=>'El aviso no admite otro reintento.']); }
            if (!Database::run('SELECT 1 FROM vinculo_telegram WHERE paciente_id=? AND activo',[(int) $row['paciente_id']])->fetchColumn()) {
                throw new ValidationException(['general'=>'El paciente debe volver a habilitar Telegram antes de reintentar.']);
            }
            Database::run("UPDATE aviso SET estado='pendiente',ultimo_error=NULL WHERE id=?",[$id]);
            AuditRepository::record($user,'reintentar_aviso','Reintento autorizado del aviso '.$id.'.',(int) $row['paciente_id']);
        });
    }
}
