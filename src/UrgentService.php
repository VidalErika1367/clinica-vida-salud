<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AppointmentRepository;
use App\Repositories\AuditRepository;
use App\Repositories\DoctorRepository;
use App\Repositories\PatientRepository;
use App\Repositories\UrgentRepository;
use App\Repositories\UserRepository;

/**
 * Solicitudes de cita urgente: creación por el paciente (CU21) y gestión por recepción (CU22).
 * Una solicitud sin cita queda pendiente; al asignar turno se crea una cita de tipo urgente.
 */
final class UrgentService
{
    /**
     * Código visible de una solicitud, por ejemplo "SU-000007".
     *
     * @param int $id identificador interno
     * @return string código con seis dígitos
     */
    public static function code(int $id): string { return 'SU-'.str_pad((string) $id,6,'0',STR_PAD_LEFT); }
    /**
     * Actor para solicitudes urgentes: CU21 para pacientes y CU22 para personal.
     *
     * @param int $id usuario
     * @return array actor autorizado
     * @throws HttpException 403 si la cuenta no tiene acceso
     */
    public static function actor(int $id): array
    {
        $user=UserRepository::sessionAccount($id);
        if (!$user) { throw new HttpException(403,'La cuenta ya no tiene acceso.'); }
        return AppointmentService::actor($id,$user['rol']==='paciente' ? 'CU21' : 'CU22');
    }
    /**
     * Busca una solicitud urgente respetando el alcance del actor (el paciente solo ve las suyas).
     *
     * @param int $id solicitud
     * @param array $actor usuario autenticado
     * @param bool $lock si es true, bloquea la fila
     * @return array datos de la solicitud
     * @throws HttpException 404 si no existe; 403 si el paciente no es su propietario
     */
    public static function find(int $id, array $actor, bool $lock=false): array
    {
        $row=UrgentRepository::find($id,$lock);
        if (!$row) { throw new HttpException(404,'No se encontró la solicitud urgente.'); }
        if ($actor['rol']==='paciente' && (int) ($row['propietario_id'] ?? 0)!==(int) $actor['id']) {
            throw new HttpException(403,'Solo puedes consultar tus propias solicitudes urgentes.');
        }
        return $row;
    }
    /**
     * Huella de la solicitud para control de versión.
     *
     * @param array $row solicitud
     * @return string SHA-256 en hexadecimal
     */
    public static function version(array $row): string
    {
        return CareSupport::version($row,['id','paciente_id','motivo','contacto','estado','cita_id','resolucion','gestionado_por']);
    }
    /**
     * Crea la solicitud urgente del paciente (CU21). Es idempotente por la clave de envío del formulario.
     * Porqué: si el formulario se reenvía, se devuelve la solicitud ya creada en lugar de duplicarla;
     * si llega con otros datos, se rechaza.
     *
     * @param array $input motivo, contacto, clave de envío y paciente opcional
     * @param int $userId usuario paciente
     * @return int identificador de la solicitud
     * @throws ValidationException si los datos no son válidos o la clave ya se usó con otros datos
     * @throws HttpException 403 si la cuenta no tiene ficha de paciente o pide para otro paciente
     */
    public static function create(array $input, int $userId): int
    {
        $reason=CareSupport::text($input,'motivo',500);
        $contact=CareSupport::text($input,'contacto',30);
        $key=CareSupport::text($input,'clave_envio',64);
        if (!Validation::validPhone($contact)) { throw new ValidationException(['contacto'=>'Escribe un teléfono válido para que recepción pueda contactarte.']); }
        if (!preg_match('/^[a-f0-9]{32}$/D',$key)) { throw new ValidationException(['clave_envio'=>'Abre nuevamente el formulario de solicitud.']); }
        return CareSupport::transaction(function () use ($input,$reason,$contact,$key,$userId): int {
            UserRepository::lockAccounts(); $actor=AppointmentService::actor($userId,'CU21');
            $patientId=(int) ($actor['paciente_id'] ?? 0);
            if (!$patientId) { throw new HttpException(403,'La cuenta no tiene una ficha de paciente vinculada.'); }
            $requested=CareSupport::optionalId($input,'paciente_id');
            if ($requested!==null && $requested!==$patientId) { throw new HttpException(403,'Solo puedes solicitar atención para tu propio paciente.'); }
            PatientRepository::find($patientId,true);
            $sent=UrgentRepository::sent($patientId,$key);
            if ($sent) {
                if ($sent['motivo']!==$reason || $sent['contacto']!==$contact) {
                    throw new ValidationException(['general'=>'Este formulario ya se envió con otros datos. Abre una nueva solicitud si necesitas registrar otra.']);
                }
                return (int) $sent['id'];
            }
            $id=UrgentRepository::create($patientId,$reason,$contact,$key);
            UrgentRepository::record($id,$userId,'solicitar','Solicitud recibida y pendiente de revisión.');
            UrgentRepository::notify($patientId,null,'urgencia:'.$id.':recibida','solicitud_urgente',
                'Su solicitud '.self::code($id).' quedó pendiente de revisión por recepción. Todavía no tiene una cita asignada.');
            return $id;
        });
    }
    /**
     * Gestiona una solicitud pendiente (CU22): seguimiento, asignación de cita o cierre.
     * Porqué: asignar a un paciente inactivo o con multas exige una excepción explícita registrada;
     * la asignación revalida el turno dentro de la transacción.
     *
     * @param int $id solicitud
     * @param array $input acción, resolución, versión y datos de asignación
     * @param int $userId usuario de recepción
     * @return void
     * @throws ValidationException si la acción no es válida, falta una confirmación o el turno ya no está libre
     * @throws HttpException 403 sin permiso CU22; 404 si no existe
     */
    public static function manage(int $id, array $input, int $userId): void
    {
        $resolution=CareSupport::text($input,'resolucion',500);
        $action=CareSupport::text($input,'accion',20);
        if (!in_array($action,['seguimiento','asignar','cerrar'],true)) { throw new ValidationException(['accion'=>'Selecciona una gestión válida.']); }
        CareSupport::transaction(function () use ($id,$input,$userId,$resolution,$action): void {
            UserRepository::lockAccounts(); $actor=AppointmentService::actor($userId,'CU22');
            $row=self::find($id,$actor,true);
            CareSupport::checkVersion(self::version($row),$input['version'] ?? null);
            if ($row['estado']!=='pendiente') { throw new ValidationException(['general'=>'La solicitud ya fue resuelta. Consulta su resultado.']); }
            $patient=PatientRepository::find((int) $row['paciente_id'],true);
            $appointment=null; $state=$action==='cerrar' ? 'cerrada' : 'pendiente';
            if ($action==='asignar') {
                CareSupport::confirm($input,'aceptacion_paciente','Registra que el paciente aceptó el médico, la especialidad y el turno propuestos.');
                if (!$patient['activo'] || AppointmentRepository::debt((int) $patient['id'])) {
                    CareSupport::confirm($input,'excepcion_autorizada','Registra la excepción urgente autorizada para atender al paciente con restricción de reservas ordinarias.');
                }
                $specialty=CareSupport::optionalId($input,'especialidad_id') ?? 0;
                $doctor=CareSupport::optionalId($input,'medico_id') ?? 0;
                DoctorRepository::lockForBooking($doctor);
                $start=AvailabilityService::assertFree($specialty,$doctor,CareSupport::text($input,'inicio',32));
                AppointmentRepository::actor($userId);
                $appointment=AppointmentRepository::createUrgent((int) $patient['id'],$doctor,$specialty,$start->format(DATE_ATOM),$userId,$row['motivo']);
                $state='gestionada';
                AuditRepository::record($userId,'asignar_cita_urgente',self::code($id).' vinculada a '.AppointmentService::code($appointment), (int) $patient['id']);
            }
            UrgentRepository::change($id,$state,$resolution,$userId,$appointment);
            UrgentRepository::record($id,$userId,$action,$resolution);
            $event=Database::run('SELECT max(id) FROM historial_solicitud_urgente WHERE solicitud_id=?',[$id])->fetchColumn();
            UrgentRepository::notify((int) $patient['id'],$appointment,'urgencia:'.$id.':gestion:'.$event,'gestion_urgente',
                'Recepción registró una gestión de su solicitud '.self::code($id).($appointment ? '. Cita '.AppointmentService::code($appointment).' Programada; revise el turno en la web.' : '. Consulte el detalle en la web.'));
        });
    }
}
