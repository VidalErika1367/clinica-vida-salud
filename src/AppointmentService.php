<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AppointmentRepository;
use App\Repositories\AuditRepository;
use App\Repositories\AvailabilityRepository;
use App\Repositories\DoctorRepository;
use App\Repositories\PatientRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use PDOException;

final class AppointmentService
{
    public const ROLES=['administrador','recepcionista','paciente'];
    public const MANAGE=['CU12','CU13','CU14','CU15'];
    public static function code(int $id): string { return 'CM-' . str_pad((string) $id,6,'0',STR_PAD_LEFT); }
    public static function allowed(array $user, string $permission): bool
    {
        return in_array($user['rol'],self::ROLES,true) && in_array($permission,$user['permisos'],true);
    }
    public static function actor(int $id, ?string $permission=null): array
    {
        $user=UserRepository::sessionAccount($id);
        if (!$user) { throw new HttpException(403,'La cuenta ya no tiene acceso. Vuelve a ingresar.'); }
        unset($user['password_hash']);
        $user['permisos']=RoleRepository::forUser($id);
        $permitted=$permission ? in_array($permission,$user['permisos'],true)
            : (bool) array_intersect(self::MANAGE,$user['permisos']);
        if (!in_array($user['rol'],self::ROLES,true) || !$permitted) {
            throw new HttpException(403,'Tu cuenta no tiene permiso para realizar esta operación.');
        }
        return $user;
    }
    public static function find(int $id, array $actor, bool $lock=false): array
    {
        $row=AppointmentRepository::find($id,$lock);
        if (!$row) { throw new HttpException(404,'No se encontró la cita.'); }
        if ($actor['rol']==='paciente' && (int) ($row['propietario_id'] ?? 0)!==(int) $actor['id']) {
            throw new HttpException(403,'Solo puedes consultar y gestionar tus propias citas.');
        }
        return $row;
    }
    public static function version(array $row): string
    {
        $data=[];
        foreach (['id','paciente_id','medico_id','especialidad_id','inicio','fin','estado','tipo',
            'motivo','motivo_cambio','origen_cambio','justificada','aviso_cancelacion_en'] as $key) { $data[$key]=$row[$key]; }
        return hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    private static function checkVersion(array $row, array $input): void
    {
        if (!is_string($input['version'] ?? null) || !hash_equals(self::version($row),$input['version'])) {
            throw new ValidationException(['version'=>'La cita cambió. Revisa sus datos actuales antes de continuar.']);
        }
    }
    public static function reason(array $input, string $key, bool $required=true): string
    {
        $reason=is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        if (($required && $reason==='') || mb_strlen($reason)>250 || preg_match('/[\x00-\x1F<>]/u',$reason)
            || (isset($input[$key]) && !is_string($input[$key]))) {
            throw new ValidationException([$key=>'Escribe un motivo de hasta 250 caracteres, sin etiquetas.']);
        }
        return $reason;
    }
    private static function patient(array $actor, mixed $requested, bool $ordinary=true): array
    {
        if ($actor['rol']==='paciente') {
            $id=(int) ($actor['paciente_id'] ?? 0);
            if ($requested!==null && $requested!=='' && AvailabilityService::id($requested)!==$id) {
                throw new HttpException(403,'Solo puedes reservar para tu propio paciente.');
            }
        } else { $id=AvailabilityService::id($requested); }
        if (!$id) { throw new ValidationException(['paciente_id'=>'Selecciona un paciente registrado.']); }
        $row=PatientRepository::find($id,true);
        if (!$row) { throw new ValidationException(['paciente_id'=>'No se encontró el paciente seleccionado.']); }
        if (!$row['activo']) { throw new ValidationException(['paciente_id'=>'El paciente está inactivo para nuevas reservas. Solicita su reactivación en recepción.']); }
        if ($ordinary && AppointmentRepository::debt($id)) { throw new ValidationException(['paciente_id'=>'El paciente tiene multas pendientes. Debe regularizarlas en recepción antes de una nueva reserva ordinaria.']); }
        return $row;
    }
    private static function changeContext(array $input, array $actor): array
    {
        $origin=is_string($input['origen_cambio'] ?? null) ? $input['origen_cambio'] : 'paciente';
        $justified=($input['justificada'] ?? '')==='1';
        if (!in_array($origin,['paciente','clinica'],true)) { throw new ValidationException(['origen_cambio'=>'Selecciona un origen válido.']); }
        if ($actor['rol']==='paciente' && ($origin!=='paciente' || $justified || !empty($input['aviso_cancelacion_en']))) {
            throw new HttpException(403,'Solo recepción puede validar causas de la clínica, justificaciones y avisos previos.');
        }
        if (isset($input['justificada']) && !in_array($input['justificada'],['0','1'],true)) {
            throw new ValidationException(['justificada'=>'Revisa la justificación.']);
        }
        return [$origin,$justified];
    }
    private static function transaction(callable $callback): mixed
    {
        try { return Database::transaction($callback); }
        catch (PDOException $e) {
            if (in_array($e->getCode(),['23P01','23505','40001','40P01'],true)) {
                throw new ValidationException(['general'=>'El turno o la cita cambió durante la operación. Consulta los datos actuales y vuelve a intentarlo.']);
            }
            if ($e->getCode()==='P0001') {
                throw new ValidationException(['general'=>'Las condiciones de la cita ya no permiten ese cambio. Revisa el estado, el paciente y la disponibilidad.']);
            }
            throw $e;
        }
    }
    private static function lock(int $id, array $actor, ?int $newDoctor=null): array
    {
        $row=self::find($id,$actor);
        PatientRepository::find((int) $row['paciente_id'],true);
        $doctors=[(int) $row['medico_id']];
        if ($newDoctor) { $doctors[]=$newDoctor; }
        $doctors=array_values(array_unique($doctors)); sort($doctors);
        foreach ($doctors as $doctor) { DoctorRepository::lockForBooking($doctor); }
        return self::find($id,$actor,true);
    }
    public static function book(array $input, int $userId): int
    {
        $specialty=AvailabilityService::id($input['especialidad_id'] ?? null);
        $doctor=AvailabilityService::id($input['medico_id'] ?? null);
        $start=is_string($input['inicio'] ?? null) ? $input['inicio'] : '';
        $reason=self::reason($input,'motivo',false);
        return self::transaction(function () use ($input,$userId,$specialty,$doctor,$start,$reason): int {
            UserRepository::lockAccounts();
            $actor=self::actor($userId,'CU12');
            if (isset($input['tipo']) && $input['tipo']!=='ordinaria') {
                throw new HttpException(403,'Las citas urgentes requieren gestión de recepción.');
            }
            $patient=self::patient($actor,$input['paciente_id'] ?? null);
            DoctorRepository::lockForBooking($doctor);
            $parsed=AvailabilityService::assertFree($specialty,$doctor,$start);
            return AppointmentRepository::create((int) $patient['id'],$doctor,$specialty,$parsed->format(DATE_ATOM),$userId,$reason);
        });
    }
    public static function confirm(int $id, array $input, int $userId): void
    {
        self::transaction(function () use ($id,$input,$userId): void {
            UserRepository::lockAccounts(); $actor=self::actor($userId,'CU13');
            $row=self::lock($id,$actor); self::checkVersion($row,$input);
            if ($row['estado']!=='programada' || !$row['futura']) {
                throw new ValidationException(['general'=>'Solo puede confirmarse una cita programada cuyo turno todavía no comenzó.']);
            }
            AppointmentRepository::actor($userId); AppointmentRepository::confirm($id);
        });
    }
    public static function reschedule(int $id, array $input, int $userId): void
    {
        $doctor=AvailabilityService::id($input['medico_id'] ?? null);
        $start=is_string($input['inicio'] ?? null) ? $input['inicio'] : '';
        $reason=self::reason($input,'motivo_cambio');
        self::transaction(function () use ($id,$input,$userId,$doctor,$start,$reason): void {
            UserRepository::lockAccounts(); $actor=self::actor($userId,'CU14');
            $row=self::lock($id,$actor,$doctor); self::checkVersion($row,$input);
            if (!in_array($row['estado'],['programada','confirmada'],true) || !$row['vigente']) {
                throw new ValidationException(['general'=>'La cita ya no puede reprogramarse. Solicita una nueva reserva.']);
            }
            if ($actor['rol']==='paciente' && (!$row['futura'] || $doctor!==(int) $row['medico_id'])) {
                throw new HttpException(403,'Para cambiar de médico o gestionar un turno ya iniciado, comunícate con recepción.');
            }
            if (isset($input['especialidad_id']) && AvailabilityService::id($input['especialidad_id'])!==(int) $row['especialidad_id']) {
                throw new ValidationException(['medico_id'=>'La reprogramación debe conservar la especialidad de la cita.']);
            }
            if ($doctor!==(int) $row['medico_id'] && ($input['aceptacion_medico'] ?? '')!=='1') {
                throw new ValidationException(['aceptacion_medico'=>'Registra la aceptación del paciente para cambiar de médico.']);
            }
            [$origin,$justified]=self::changeContext($input,$actor);
            if ($row['tipo']==='urgente' && $actor['rol']==='paciente') { throw new HttpException(403,'La reprogramación de una cita urgente requiere recepción.'); }
            self::patient($actor,$actor['rol']==='paciente' ? null : (string) $row['paciente_id'],$row['tipo']==='ordinaria');
            $parsed=AvailabilityService::assertFree((int) $row['especialidad_id'],$doctor,$start,$id);
            if ($doctor===(int) $row['medico_id'] && $parsed->getTimestamp()===(new \DateTimeImmutable($row['inicio']))->getTimestamp()) {
                throw new ValidationException(['inicio'=>'Selecciona un turno diferente al actual.']);
            }
            AppointmentRepository::actor($userId);
            AppointmentRepository::reschedule($id,$doctor,$parsed->format(DATE_ATOM),$reason,$origin,$justified);
            if ($doctor!==(int) $row['medico_id']) {
                AuditRepository::record($userId,'cambio_medico_aceptado',
                    'Cita '.self::code($id).'; aceptación registrada para el médico '.DoctorService::code($doctor),(int) $row['paciente_id']);
            }
            $clock=AvailabilityRepository::clock(); $policy=AppointmentRepository::policy();
            if ($origin==='paciente' && (new \DateTimeImmutable($row['inicio']))->getTimestamp()
                -(new \DateTimeImmutable($clock['ahora']))->getTimestamp() < (int) $policy['plazo_cancelacion_horas']*3600) {
                AuditRepository::record($userId,'revisar_reprogramacion',
                    'Cita ' . self::code($id) . ': cambio solicitado dentro del plazo configurado de cancelación.',(int) $row['paciente_id']);
            }
        });
    }
    public static function cancel(int $id, array $input, int $userId): void
    {
        $reason=self::reason($input,'motivo_cambio');
        self::transaction(function () use ($id,$input,$reason,$userId): void {
            UserRepository::lockAccounts(); $actor=self::actor($userId,'CU15');
            $row=self::lock($id,$actor); self::checkVersion($row,$input);
            if (!in_array($row['estado'],['programada','confirmada'],true) || !$row['vigente']) {
                throw new ValidationException(['general'=>'Solo puede cancelarse una cita vigente programada o confirmada.']);
            }
            [$origin,$justified]=self::changeContext($input,$actor);
            $notice=null;
            if (!empty($input['aviso_cancelacion_en'])) {
                $value=is_string($input['aviso_cancelacion_en']) ? $input['aviso_cancelacion_en'] : '';
                $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new \DateTimeZone('America/La_Paz'));
                $errors=\DateTimeImmutable::getLastErrors();
                $clock=AvailabilityRepository::clock();
                if (!$parsed || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $parsed->format('Y-m-d\TH:i')!==$value
                    || $parsed>new \DateTimeImmutable($clock['ahora']) || $parsed<new \DateTimeImmutable($row['creado_en'])) {
                    throw new ValidationException(['aviso_cancelacion_en'=>'El aviso debe ser válido, posterior a la reserva y no futuro.']);
                }
                $notice=$parsed->format(DATE_ATOM);
            }
            AppointmentRepository::cancel($id,$userId,$reason,$origin,$justified,$notice);
        });
    }
}
