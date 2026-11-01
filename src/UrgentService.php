<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AppointmentRepository;
use App\Repositories\AuditRepository;
use App\Repositories\DoctorRepository;
use App\Repositories\PatientRepository;
use App\Repositories\UrgentRepository;
use App\Repositories\UserRepository;

final class UrgentService
{
    public static function code(int $id): string { return 'SU-'.str_pad((string) $id,6,'0',STR_PAD_LEFT); }
    public static function actor(int $id): array
    {
        $user=UserRepository::sessionAccount($id);
        if (!$user) { throw new HttpException(403,'La cuenta ya no tiene acceso.'); }
        return AppointmentService::actor($id,$user['rol']==='paciente' ? 'CU21' : 'CU22');
    }
    public static function find(int $id, array $actor, bool $lock=false): array
    {
        $row=UrgentRepository::find($id,$lock);
        if (!$row) { throw new HttpException(404,'No se encontró la solicitud urgente.'); }
        if ($actor['rol']==='paciente' && (int) ($row['propietario_id'] ?? 0)!==(int) $actor['id']) {
            throw new HttpException(403,'Solo puedes consultar tus propias solicitudes urgentes.');
        }
        return $row;
    }
    public static function version(array $row): string
    {
        return CareSupport::version($row,['id','paciente_id','motivo','contacto','estado','cita_id','resolucion','gestionado_por']);
    }
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
