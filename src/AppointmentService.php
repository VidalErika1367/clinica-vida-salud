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

/**
 * Reglas de negocio de las citas: programación (CU12), confirmación (CU13), reprogramación (CU14),
 * cancelación (CU15), atención (CU16) e inasistencia (CU17). Todas las operaciones de escritura
 * se ejecutan en transacción y con bloqueos sobre la cuenta, el médico y el paciente.
 */
final class AppointmentService
{
    /** Roles que pueden ver el módulo de citas. */
    public const ROLES=['administrador','recepcionista','paciente'];
    /** Permisos que dan acceso al módulo de citas. */
    public const MANAGE=['CU12','CU13','CU14','CU15','CU16','CU17','CU18','CU19','CU26','CU28','CU29'];
    /** Restricción por rol de cada CU, además de la matriz de permisos configurable (CU05). */
    private const EXTRA_ROLES=[
        'CU16'=>['administrador','recepcionista','medico'],
        'CU17'=>['administrador','recepcionista'],
        'CU18'=>['administrador','recepcionista','medico'],
        'CU19'=>['administrador','recepcionista','paciente'],
        'CU20'=>['administrador','recepcionista'],
        'CU21'=>['paciente'],
        'CU22'=>['administrador','recepcionista'],
        'CU23'=>['administrador','recepcionista'],
        'CU24'=>['administrador','recepcionista','paciente'],
        'CU25'=>['administrador','recepcionista'],
        'CU26'=>['administrador','recepcionista'],
        'CU27'=>['paciente'],
        'CU28'=>['administrador','recepcionista','paciente'],
        'CU29'=>['administrador','recepcionista','paciente'],
        'CU30'=>['paciente'],
    ];
    /**
     * Código visible de una cita, por ejemplo "CM-000012".
     *
     * @param int $id identificador interno
     * @return string código con seis dígitos
     */
    public static function code(int $id): string { return 'CM-' . str_pad((string) $id,6,'0',STR_PAD_LEFT); }
    /**
     * Indica si el usuario puede ejecutar un CU: rol permitido y permiso asignado.
     *
     * @param array $user usuario con su lista de permisos
     * @param string $permission código CU
     * @return bool true si cumple ambas condiciones
     */
    public static function allowed(array $user, string $permission): bool
    {
        return in_array($user['rol'],self::EXTRA_ROLES[$permission] ?? self::ROLES,true)
            && in_array($permission,$user['permisos'],true);
    }
    /**
     * Indica si el usuario puede abrir el módulo de citas (tiene al menos un permiso del módulo).
     *
     * @param array $user usuario con permisos
     * @return bool
     */
    public static function canView(array $user): bool
    {
        foreach (self::MANAGE as $permission) { if (self::allowed($user,$permission)) { return true; } }
        return false;
    }
    /**
     * Carga el actor desde la base (no desde la sesión) y verifica el permiso requerido.
     * Porqué: los permisos se releen en cada operación, así un cambio en la matriz surte efecto de inmediato.
     *
     * @param int $id usuario
     * @param string|null $permission CU requerido, o null para exigir solo acceso al módulo
     * @return array actor con sus permisos
     * @throws HttpException 403 si la cuenta no existe o no tiene el permiso
     */
    public static function actor(int $id, ?string $permission=null): array
    {
        $user=UserRepository::sessionAccount($id);
        if (!$user) { throw new HttpException(403,'La cuenta ya no tiene acceso. Vuelve a ingresar.'); }
        unset($user['password_hash']);
        $user['permisos']=RoleRepository::forUser($id);
        if (!($permission ? self::allowed($user,$permission) : self::canView($user))) {
            throw new HttpException(403,'Tu cuenta no tiene permiso para realizar esta operación.');
        }
        return $user;
    }
    /**
     * Busca una cita y verifica que el actor pueda verla: el paciente solo las suyas y el médico solo las propias.
     *
     * @param int $id cita
     * @param array $actor usuario autenticado
     * @param bool $lock si es true, bloquea la fila para escribir (FOR UPDATE)
     * @return array datos de la cita
     * @throws HttpException 404 si no existe; 403 si el actor no es su propietario
     */
    public static function find(int $id, array $actor, bool $lock=false): array
    {
        $row=AppointmentRepository::find($id,$lock);
        if (!$row) { throw new HttpException(404,'No se encontró la cita.'); }
        if ($actor['rol']==='paciente' && (int) ($row['propietario_id'] ?? 0)!==(int) $actor['id']) {
            throw new HttpException(403,'Solo puedes consultar y gestionar tus propias citas.');
        }
        if ($actor['rol']==='medico' && (int) ($row['medico_usuario_id'] ?? 0)!==(int) $actor['id']) {
            throw new HttpException(403,'Solo puedes consultar y registrar la atención de tus propias citas.');
        }
        return $row;
    }
    /**
     * Huella de la cita para control de versión: cambia si cambia cualquier dato relevante.
     *
     * @param array $row cita
     * @return string SHA-256 en hexadecimal
     */
    public static function version(array $row): string
    {
        $data=[];
        foreach (['id','paciente_id','medico_id','especialidad_id','inicio','fin','estado','tipo',
            'motivo','motivo_cambio','origen_cambio','justificada','aviso_cancelacion_en'] as $key) { $data[$key]=$row[$key]; }
        return hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    /**
     * Impide guardar sobre una cita que cambió después de abrir el formulario.
     *
     * @param array $row cita actual
     * @param array $input datos recibidos (debe traer "version")
     * @return void
     * @throws ValidationException si la huella no coincide
     */
    private static function checkVersion(array $row, array $input): void
    {
        if (!is_string($input['version'] ?? null) || !hash_equals(self::version($row),$input['version'])) {
            throw new ValidationException(['version'=>'La cita cambió. Revisa sus datos actuales antes de continuar.']);
        }
    }
    /**
     * Valida el motivo escrito de un cambio: texto plano de hasta 250 caracteres.
     *
     * @param array $input datos de la petición
     * @param string $key campo del motivo
     * @param bool $required si es true, no puede quedar vacío
     * @return string motivo sin espacios sobrantes
     * @throws ValidationException si no cumple las reglas
     */
    public static function reason(array $input, string $key, bool $required=true): string
    {
        $reason=is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        if (($required && $reason==='') || mb_strlen($reason)>250 || preg_match('/[\x00-\x1F<>]/u',$reason)
            || (isset($input[$key]) && !is_string($input[$key]))) {
            throw new ValidationException([$key=>'Escribe un motivo de hasta 250 caracteres, sin etiquetas.']);
        }
        return $reason;
    }
    /**
     * Resuelve el paciente de una reserva y aplica sus restricciones. Un paciente solo puede reservar para sí mismo.
     * Porqué: con deuda o paciente inactivo no se permiten reservas ordinarias; la excepción urgente se gestiona aparte.
     *
     * @param array $actor usuario que reserva
     * @param mixed $requested id del paciente solicitado
     * @param bool $ordinary true para reservas ordinarias (aplica la restricción por multas)
     * @return array paciente bloqueado para la operación
     * @throws HttpException 403 si un paciente pide reservar para otra persona
     * @throws ValidationException si el paciente no existe, está inactivo o tiene multas pendientes
     */
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
    /**
     * Determina el origen del cambio (paciente o clínica) y si está justificado.
     * Porqué: solo recepción puede declarar causas de la clínica, justificaciones o avisos previos;
     * así un paciente no puede evitar una sanción declarando una causa propia como de la clínica.
     *
     * @param array $input datos del cambio
     * @param array $actor usuario que lo registra
     * @return array{0:string,1:bool} origen ("paciente" o "clinica") y si está justificado
     * @throws HttpException 403 si un paciente intenta un cambio reservado a recepción
     * @throws ValidationException si el origen o la justificación no son válidos
     */
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
    /**
     * Ejecuta el cambio en transacción y traduce los conflictos de concurrencia a un mensaje para el usuario.
     *
     * @param callable $callback operación transaccional
     * @return mixed resultado de la operación
     * @throws ValidationException si otra operación cambió el turno o la cita
     * @throws PDOException otros errores de base de datos
     */
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
    /**
     * Bloquea la cita, su paciente y los médicos implicados antes de cambiarla.
     * Porqué: los médicos se bloquean en orden ascendente de id; así dos operaciones simultáneas no pueden interbloquearse.
     *
     * @param int $id cita
     * @param array $actor usuario que opera
     * @param int|null $newDoctor médico nuevo si la operación lo cambia
     * @return array cita bloqueada
     * @throws HttpException 403 o 404 según el alcance del actor
     */
    private static function lock(int $id, array $actor, ?int $newDoctor=null): array
    {
        $row=self::find($id,$actor);
        PatientRepository::find((int) $row['paciente_id'],true);
        $doctors=[(int) $row['medico_id']];
        if ($newDoctor) { $doctors[]=$newDoctor; }
        // Orden fijo de bloqueo para evitar interbloqueos entre operaciones concurrentes.
        $doctors=array_values(array_unique($doctors)); sort($doctors);
        foreach ($doctors as $doctor) { DoctorRepository::lockForBooking($doctor); }
        return self::find($id,$actor,true);
    }
    /**
     * Programa una cita ordinaria (CU12). Revalida el turno libre dentro de la transacción.
     * Porqué: la disponibilidad que vio el usuario puede haber cambiado; por eso se comprueba con el médico bloqueado.
     *
     * @param array $input datos del formulario
     * @param int $userId usuario que reserva
     * @return int identificador de la cita creada
     * @throws HttpException 403 si el actor no tiene permiso CU12 o intenta una cita urgente
     * @throws ValidationException si el turno no está libre o el paciente tiene restricciones
     */
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
    /**
     * Confirma la asistencia a una cita programada cuyo turno aún no comenzó (CU13).
     *
     * @param int $id cita
     * @param array $input datos con la versión esperada
     * @param int $userId usuario que confirma
     * @return void
     * @throws ValidationException si la cita no está programada, ya comenzó o cambió
     */
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
    /**
     * Reprograma una cita vigente (CU14). Cambiar de médico exige aceptación del paciente y deja auditoría.
     * Si un paciente reprograma dentro del plazo de cancelación de la política, el cambio queda marcado para revisión.
     *
     * @param int $id cita
     * @param array $input nuevo médico, inicio, motivo, versión y aceptación
     * @param int $userId usuario que reprograma
     * @return void
     * @throws HttpException 403 si un paciente intenta cambiar de médico o de turno iniciado
     * @throws ValidationException si la cita no puede reprogramarse o el turno no está libre
     */
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
    /**
     * Cancela una cita vigente (CU15). Conserva el registro; el aviso previo, si se indica, debe ser posterior a la reserva y no futuro.
     *
     * @param int $id cita
     * @param array $input motivo, versión, origen, justificación y aviso opcional
     * @param int $userId usuario que cancela
     * @return void
     * @throws ValidationException si la cita no es cancelable o el aviso no es válido
     */
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
    /**
     * Registra la atención realizada (CU16). Solo después de iniciado el turno y con confirmación explícita.
     *
     * @param int $id cita
     * @param array $input datos con versión y confirmación de atención
     * @param int $userId usuario que registra
     * @return void
     * @throws ValidationException si el turno no comenzó o no se confirmó la atención
     */
    public static function attend(int $id, array $input, int $userId): void
    {
        self::transaction(function () use ($id,$input,$userId): void {
            UserRepository::lockAccounts(); $actor=self::actor($userId,'CU16');
            $row=self::lock($id,$actor); self::checkVersion($row,$input);
            if (!in_array($row['estado'],['programada','confirmada'],true) || $row['futura']) {
                throw new ValidationException(['general'=>'Solo puede registrarse la atención de una cita programada o confirmada cuyo turno ya comenzó.']);
            }
            if (($input['atencion_realizada'] ?? null)!=='1') {
                throw new ValidationException(['atencion_realizada'=>'Confirma que verificaste al paciente y que la consulta efectivamente se realizó.']);
            }
            AppointmentRepository::actor($userId);
            AppointmentRepository::attend($id);
        });
    }
    /**
     * Registra la inasistencia del paciente (CU17). Puede generar advertencia o multa según el origen y la justificación.
     * Porqué: no se sanciona si hubo ausencia del médico en ese turno ni si el cambio tiene causa de la clínica.
     *
     * @param int $id cita
     * @param array $input datos, verificaciones obligatorias y versión
     * @param int $userId usuario que registra
     * @return void
     * @throws ValidationException si el turno no finalizó, faltan verificaciones o hubo ausencia del médico
     */
    public static function noShow(int $id, array $input, int $userId): void
    {
        $reason=self::reason($input,'motivo_cambio');
        self::transaction(function () use ($id,$input,$userId,$reason): void {
            UserRepository::lockAccounts(); $actor=self::actor($userId,'CU17');
            $row=self::lock($id,$actor); self::checkVersion($row,$input);
            if (!in_array($row['estado'],['programada','confirmada'],true) || $row['vigente']) {
                throw new ValidationException(['general'=>'Solo puede registrarse una inasistencia cuando el turno haya finalizado y la cita siga programada o confirmada.']);
            }
            if (($input['paciente_ausente'] ?? null)!=='1' || ($input['medico_disponible'] ?? null)!=='1') {
                throw new ValidationException(['verificacion'=>'Verifica que el paciente no asistió, que el médico estaba disponible y que revisaste los avisos de cancelación.']);
            }
            if (AppointmentRepository::doctorAbsent($row)) {
                throw new ValidationException(['general'=>'Existe una ausencia del médico en ese turno. No corresponde registrar una inasistencia del paciente.']);
            }
            [$origin,$justified]=self::changeContext($input,$actor);
            AppointmentRepository::actor($userId);
            AppointmentRepository::noShow($id,$reason,$origin,$justified);
        });
    }
}
