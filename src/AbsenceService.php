<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AbsenceRepository;
use App\Repositories\AppointmentRepository;
use App\Repositories\AuditRepository;
use App\Repositories\DoctorRepository;
use App\Repositories\PatientRepository;
use App\Repositories\UserRepository;

/**
 * Ausencias de médicos (CU26): registro del intervalo, aviso a los pacientes con citas afectadas
 * y gestión de cada cita (reprogramar o cancelar con origen clínica, sin sanción).
 */
final class AbsenceService
{
    /**
     * Valida una fecha y hora del formulario en zona de la clínica.
     *
     * @param mixed $value texto Y-m-d\TH:i
     * @param string $field nombre del campo para el mensaje de error
     * @return \DateTimeImmutable fecha validada
     * @throws ValidationException si el formato o la fecha no son válidos
     */
    public static function date(mixed $value, string $field): \DateTimeImmutable
    {
        $date=is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new \DateTimeZone('America/La_Paz')) : false;
        if (!$date || (int) $date->format('Y')<1 || $date->format('Y-m-d\TH:i')!==$value) { throw new ValidationException([$field=>'Escribe una fecha y hora válidas.']); }
        return $date;
    }
    /**
     * Busca una ausencia por id.
     *
     * @param int $id ausencia
     * @return array datos de la ausencia
     * @throws HttpException 404 si no existe
     */
    public static function find(int $id): array
    {
        return AbsenceRepository::find($id) ?: throw new HttpException(404,'No se encontró la ausencia.');
    }
    /**
     * Registra la ausencia (CU26) y crea un aviso para cada cita vigente que se cruce con el intervalo.
     * Porqué: la clave de envío hace la operación idempotente; un reenvío idéntico devuelve la ausencia ya creada.
     *
     * @param array $input médico, inicio, fin, motivo y clave de envío
     * @param int $user usuario que registra
     * @return int identificador de la ausencia
     * @throws ValidationException si los datos no son válidos o la clave se usó con otros datos
     * @throws HttpException 403 sin permiso CU26
     */
    public static function create(array $input, int $user): int
    {
        $doctor=AvailabilityService::id($input['medico_id'] ?? null);
        $start=self::date($input['inicio'] ?? null,'inicio'); $end=self::date($input['fin'] ?? null,'fin');
        $reason=AppointmentService::reason($input,'motivo'); $key=CareSupport::text($input,'clave_envio',32);
        if (!$doctor || $end<=$start) { throw new ValidationException(['general'=>'Selecciona un médico y un fin posterior al inicio.']); }
        if (!preg_match('/^[a-f0-9]{32}$/D',$key)) { throw new ValidationException(['general'=>'Abre nuevamente el formulario.']); }
        return CareSupport::transaction(function () use ($doctor,$start,$end,$reason,$key,$user): int {
            UserRepository::lockAccounts(); AppointmentService::actor($user,'CU26'); DoctorRepository::lockForBooking($doctor);
            if (!DoctorRepository::find($doctor)) { throw new ValidationException(['medico_id'=>'No se encontró el médico.']); }
            $sent=Database::run('SELECT * FROM ausencia_medico WHERE clave_envio=?',[$key])->fetch();
            if ($sent) {
                if ((int) $sent['registrado_por']===$user && (int) $sent['medico_id']===$doctor && $sent['motivo']===$reason
                    && (new \DateTimeImmutable($sent['inicio']))==$start && (new \DateTimeImmutable($sent['fin']))==$end) { return (int) $sent['id']; }
                throw new ValidationException(['general'=>'Este formulario ya se utilizó. Revisa la ausencia registrada.']);
            }
            $id=(int) Database::run('INSERT INTO ausencia_medico(medico_id,inicio,fin,motivo,registrado_por,clave_envio)
                VALUES(?,?,?,?,?,?) RETURNING id',[$doctor,$start->format(DATE_ATOM),$end->format(DATE_ATOM),$reason,$user,$key])->fetchColumn();
            Database::run("INSERT INTO aviso(paciente_id,cita_id,clave_evento,tipo,texto)
                SELECT paciente_id,id,'ausencia:' || ?::text || ':cita:' || id,'ausencia_medico',
                'Recepción debe coordinar un cambio de su cita por indisponibilidad del médico. Consulte la web o comuníquese con recepción.'
                FROM cita WHERE medico_id=? AND estado IN ('programada','confirmada')
                AND tstzrange(inicio,fin,'[)') && tstzrange(?::timestamptz,?::timestamptz,'[)')",
                [$id,$doctor,$start->format(DATE_ATOM),$end->format(DATE_ATOM)]);
            AuditRepository::record($user,'registrar_ausencia','Ausencia '.$id.' del médico '.DoctorService::code($doctor).'; intervalo y motivo registrados.');
            return $id;
        });
    }
    /**
     * Indica si una cita sigue pendiente de gestión por una ausencia: mismo médico, vigente y con solape de horario.
     *
     * @param array $absence ausencia
     * @param array $appointment cita
     * @return bool true si la cita aún debe gestionarse
     */
    public static function pending(array $absence, array $appointment): bool
    {
        return (int) $absence['medico_id']===(int) $appointment['medico_id']
            && in_array($appointment['estado'],['programada','confirmada'],true)
            && new \DateTimeImmutable($appointment['inicio'])<new \DateTimeImmutable($absence['fin'])
            && new \DateTimeImmutable($appointment['fin'])>new \DateTimeImmutable($absence['inicio']);
    }
    /**
     * Reprograma o cancela una cita afectada (CU26). El cambio queda con origen "clínica", así no sanciona al paciente.
     * Porqué: exige aceptación del paciente registrada, y revalida el estado de la cita con los médicos bloqueados.
     *
     * @param int $id ausencia
     * @param int $appointment cita afectada
     * @param array $input acción, motivo, aceptación, nuevo turno (si reprograma) y versión
     * @param int $user usuario de recepción
     * @return void
     * @throws ValidationException si falta la aceptación, la acción no es válida o la cita ya no está pendiente
     * @throws HttpException 403 o 404 según el acceso
     */
    public static function manage(int $id, int $appointment, array $input, int $user): void
    {
        $reason=AppointmentService::reason($input,'motivo_cambio');
        CareSupport::confirm($input,'aceptacion_paciente','Registra que el cambio fue acordado con el paciente.');
        $action=$input['accion'] ?? '';
        if (!in_array($action,['reprogramar','cancelar'],true)) { throw new ValidationException(['accion'=>'Selecciona una gestión válida.']); }
        CareSupport::transaction(function () use ($id,$appointment,$input,$user,$reason,$action): void {
            UserRepository::lockAccounts(); $actor=AppointmentService::actor($user,'CU26'); $absence=self::find($id);
            $row=AppointmentService::find($appointment,$actor);
            PatientRepository::find((int) $row['paciente_id'],true);
            $doctor=$action==='reprogramar' ? AvailabilityService::id($input['medico_id'] ?? null) : (int) $row['medico_id'];
            $doctors=array_unique([(int) $row['medico_id'],$doctor]); sort($doctors);
            foreach ($doctors as $d) { if ($d) { DoctorRepository::lockForBooking($d); } }
            $row=AppointmentService::find($appointment,$actor,true);
            CareSupport::checkVersion(AppointmentService::version($row),$input['version'] ?? null);
            if (!self::pending($absence,$row)) { throw new ValidationException(['general'=>'Esta cita ya no está pendiente de gestión por esa ausencia.']); }
            AppointmentRepository::actor($user);
            if ($action==='cancelar') { AppointmentRepository::cancel($appointment,$user,$reason,'clinica',true,null); }
            else {
                if (!$doctor) { throw new ValidationException(['medico_id'=>'Selecciona un médico de la misma especialidad.']); }
                $start=is_string($input['inicio'] ?? null) ? $input['inicio'] : '';
                $parsed=AvailabilityService::assertFree((int) $row['especialidad_id'],$doctor,$start,$appointment);
                AppointmentRepository::reschedule($appointment,$doctor,$parsed->format(DATE_ATOM),$reason,'clinica',true);
            }
            Database::run('INSERT INTO gestion_ausencia(ausencia_id,cita_id,actor_id,accion,motivo) VALUES(?,?,?,?,?)',
                [$id,$appointment,$user,$action,$reason]);
            AuditRepository::record($user,'gestionar_ausencia','Ausencia '.$id.'; '.AppointmentService::code($appointment).'; cambio acordado con el paciente.',(int) $row['paciente_id']);
        });
    }
}
