<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AppointmentRepository;
use App\Repositories\AvailabilityRepository;
use App\Repositories\PatientRepository;
use App\Repositories\SpecialtyRepository;

/**
 * Citas médicas: listado, detalle, programación (CU12), confirmación (CU13), reprogramación (CU14),
 * cancelación (CU15), registro de atención (CU16) e inasistencia (CU17).
 * Cada acción valida el permiso del CU y, para pacientes y médicos, que la cita les pertenezca.
 */
final class AppointmentController
{
    /**
     * Exige sesión y, si se indica, el permiso del CU; devuelve el actor con su alcance sobre las citas.
     *
     * @param string|null $permission código CU requerido, o null si basta con tener sesión
     * @return array actor autorizado
     * @throws HttpException 403 si el actor no puede realizar la acción
     */
    private static function authorize(?string $permission=null): array
    {
        $user=Auth::requireUser();
        return AppointmentService::actor((int) $user['id'],$permission);
    }
    /**
     * Lista de citas filtrada por rol: el paciente ve las suyas y el médico, su agenda.
     *
     * @return void
     */
    public static function index(): void
    {
        $user=self::authorize();
        $query=is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']),0,100) : '';
        $state=is_string($_GET['estado'] ?? null) ? $_GET['estado'] : 'vigentes';
        if (!in_array($state,['vigentes','todos','programada','confirmada','cancelada','atendida','inasistencia'],true)) { $state='vigentes'; }
        View::render('appointments',['title'=>$user['rol']==='paciente' ? 'Mis citas' : 'Citas','section'=>'citas',
            'items'=>AppointmentRepository::search($user['rol']==='paciente' ? (int) $user['id'] : null,$query,$state,
                $user['rol']==='medico' ? (int) $user['id'] : null),
            'query'=>$query,'state'=>$state]);
    }
    /**
     * Detalle de una cita con su historial paginado, incidencias y contador (CU29 si el rol lo permite).
     *
     * @param int $id cita
     * @param array $errors errores a mostrar en la ficha
     * @return void
     * @throws HttpException 403 o 404 según el alcance del actor
     */
    public static function detail(int $id, array $errors=[]): void
    {
        $user=self::authorize(); $row=AppointmentService::find($id,$user);
        try { $page=AppointmentReadService::page($_GET['historial_pagina'] ?? null); }
        catch (ValidationException $e) { $page=1; $errors+=$e->errors; }
        $history=AppointmentRepository::history($id,$page); $more=count($history)>20;
        View::render('appointment_detail',['title'=>'Cita '.AppointmentService::code($id),'section'=>'citas','appointment'=>$row,
            'version'=>AppointmentService::version($row),'errors'=>$errors,'history'=>array_slice($history,0,20),
            'historyPage'=>$page,'historyMore'=>$more,
            'counter'=>AppointmentService::allowed($user,'CU29') ? CounterService::forAppointment($id,(int) $user['id']) : null,
            'incident'=>AppointmentRepository::incident($id)],$errors ? 422 : 200);
    }
    /**
     * Formulario para programar una cita (CU12). Si faltan datos, redirige a la consulta de disponibilidad.
     *
     * @param array|null $posted datos enviados, para conservarlos al mostrar errores
     * @param array $errors errores de validación
     * @return void
     * @throws HttpException 403 sin permiso CU12
     */
    public static function bookingForm(?array $posted=null, array $errors=[]): void
    {
        $user=self::authorize('CU12');
        $data=$posted ?? $_GET;
        $specialty=AvailabilityService::id($data['especialidad_id'] ?? null);
        $doctor=AvailabilityService::id($data['medico_id'] ?? null);
        $start=is_string($data['inicio'] ?? null) ? $data['inicio'] : '';
        if ($posted===null && !$specialty && !$doctor && $start==='') { redirect('/disponibilidad'); }
        $free=true;
        try { AvailabilityService::assertFree($specialty,$doctor,$start); }
        catch (ValidationException $e) { $errors+=$e->errors; $free=false; }
        $specialtyRow=SpecialtyRepository::find($specialty);
        $doctors=AvailabilityRepository::doctors($specialty);
        $doctorRow=null;
        foreach ($doctors as $candidate) { if ((int) $candidate['id']===$doctor) { $doctorRow=$candidate; break; } }
        $patientQuery=is_string($data['paciente_q'] ?? null) ? mb_substr(trim($data['paciente_q']),0,100) : '';
        $patients=$user['rol']==='paciente' ? [] : PatientRepository::search($patientQuery,'activos');
        View::render('appointment_book',['title'=>'Programar cita','section'=>'citas','old'=>$data,'errors'=>$errors,
            'specialtyId'=>$specialty,'doctorId'=>$doctor,'start'=>$start,'free'=>$free,'specialty'=>$specialtyRow,
            'doctor'=>$doctorRow,'patients'=>$patients,'patientQuery'=>$patientQuery,
            'patient'=>$user['rol']==='paciente' && $user['paciente_id'] ? PatientRepository::find((int) $user['paciente_id']) : null],
            $errors ? 422 : 200);
    }
    /**
     * Registra la cita (CU12). La validación de turno libre y el bloqueo se hacen en AppointmentService.
     *
     * @return void
     * @throws HttpException 403 sin permiso CU12
     */
    public static function book(): void
    {
        $user=self::authorize('CU12');
        try {
            $id=AppointmentService::book($_POST,(int) $user['id']);
            Session::flash('La cita '.AppointmentService::code($id).' quedó Programada.');
            redirect('/citas/'.$id);
        } catch (ValidationException $e) { self::bookingForm($_POST,$e->errors); }
    }
    /**
     * Confirma la asistencia a la cita (CU13).
     *
     * @param int $id cita
     * @return void
     * @throws HttpException 403 sin permiso CU13
     */
    public static function confirm(int $id): void
    {
        $user=self::authorize('CU13');
        try {
            AppointmentService::confirm($id,$_POST,(int) $user['id']);
            Session::flash('Tu asistencia quedó confirmada. El turno se conserva.');
            redirect('/citas/'.$id);
        } catch (ValidationException $e) { self::detail($id,$e->errors); }
    }
    /**
     * Formulario para reprogramar una cita (CU14). El paciente no puede cambiar de médico desde aquí.
     *
     * @param int $id cita
     * @param array|null $posted datos enviados
     * @param array $errors errores de validación
     * @return void
     * @throws HttpException 403 sin permiso CU14 o si un paciente intenta cambiar de médico
     */
    public static function rescheduleForm(int $id, ?array $posted=null, array $errors=[]): void
    {
        $user=self::authorize('CU14'); $row=AppointmentService::find($id,$user);
        $data=isset($errors['version']) ? ['medico_id'=>(string) $row['medico_id'],'fecha'=>clinic_input($row['inicio'],'Y-m-d')] : ($posted ?? $_GET);
        $doctor=isset($data['medico_id']) ? AvailabilityService::id($data['medico_id']) : (int) $row['medico_id'];
        if ($user['rol']==='paciente' && $doctor!==(int) $row['medico_id']) {
            throw new HttpException(403,'Para cambiar de médico, comunícate con recepción.');
        }
        $date=is_string($data['fecha'] ?? null) ? $data['fecha'] : clinic_input($row['inicio'],'Y-m-d');
        $slots=[];
        try { $slots=AvailabilityService::slots((int) $row['especialidad_id'],$doctor,$date,$id); }
        catch (ValidationException $e) { $errors+=$e->errors; }
        $version=isset($errors['version']) || $posted===null ? AppointmentService::version($row) : input('version');
        $clock=AvailabilityRepository::clock(); $policy=AppointmentRepository::policy();
        $late=(new \DateTimeImmutable($row['inicio']))->getTimestamp()-(new \DateTimeImmutable($clock['ahora']))->getTimestamp()
            < (int) $policy['plazo_cancelacion_horas']*3600;
        View::render('appointment_reschedule',['title'=>'Reprogramar cita','section'=>'citas','appointment'=>$row,
            'old'=>isset($errors['version']) ? [] : $data,'errors'=>$errors,'version'=>$version,
            'doctors'=>AvailabilityRepository::doctors((int) $row['especialidad_id']),'doctorId'=>$doctor,
            'date'=>$date,'today'=>$clock['hoy'],'slots'=>$slots,'late'=>$late,'policy'=>$policy],
            $errors ? 422 : 200);
    }
    /**
     * Reprograma la cita (CU14). Aplica el plazo de cancelación definido en la política.
     *
     * @param int $id cita
     * @return void
     * @throws HttpException 403 sin permiso CU14
     */
    public static function reschedule(int $id): void
    {
        $user=self::authorize('CU14');
        try {
            AppointmentService::reschedule($id,$_POST,(int) $user['id']);
            Session::flash('La cita se reprogramó y quedó Programada para confirmar el nuevo turno.');
            redirect('/citas/'.$id);
        } catch (ValidationException $e) { self::rescheduleForm($id,$_POST,$e->errors); }
    }
    /**
     * Formulario de cancelación (CU15), con el plazo y la hora de referencia de la clínica.
     *
     * @param int $id cita
     * @param array|null $posted datos enviados
     * @param array $errors errores de validación
     * @return void
     * @throws HttpException 403 sin permiso CU15
     */
    public static function cancelForm(int $id, ?array $posted=null, array $errors=[]): void
    {
        $user=self::authorize('CU15'); $row=AppointmentService::find($id,$user);
        View::render('appointment_cancel',['title'=>'Cancelar cita','section'=>'citas','appointment'=>$row,
            'old'=>isset($errors['version']) ? [] : ($posted ?? []),'errors'=>$errors,'version'=>isset($errors['version']) || $posted===null
                ? AppointmentService::version($row) : input('version'),'policy'=>AppointmentRepository::policy(),
            'clock'=>AvailabilityRepository::clock()],$errors ? 422 : 200);
    }
    /**
     * Cancela la cita (CU15). Conserva el registro; la cancelación tardía queda marcada según la política.
     *
     * @param int $id cita
     * @return void
     * @throws HttpException 403 sin permiso CU15
     */
    public static function cancel(int $id): void
    {
        $user=self::authorize('CU15');
        try {
            AppointmentService::cancel($id,$_POST,(int) $user['id']);
            Session::flash('La cita quedó Cancelada. Se liberó su horario y se conservó el registro.');
            redirect('/citas/'.$id);
        } catch (ValidationException $e) { self::cancelForm($id,$_POST,$e->errors); }
    }
    /**
     * Formulario para registrar la atención (CU16) o la inasistencia (CU17).
     * La atención solo se habilita en citas programadas o confirmadas ya iniciadas.
     *
     * @param int $id cita
     * @param bool $noShow true para inasistencia (CU17), false para atención (CU16)
     * @param array|null $posted datos enviados
     * @param array $errors errores de validación
     * @return void
     * @throws HttpException 403 sin el permiso del CU correspondiente
     */
    public static function finishForm(int $id, bool $noShow=false, ?array $posted=null, array $errors=[]): void
    {
        $user=self::authorize($noShow ? 'CU17' : 'CU16'); $row=AppointmentService::find($id,$user);
        $canFinish=in_array($row['estado'],['programada','confirmada'],true) && !($noShow ? $row['vigente'] : $row['futura']);
        View::render('appointment_finish',['title'=>$noShow ? 'Registrar inasistencia' : 'Registrar atención realizada',
            'section'=>'citas','appointment'=>$row,'noShow'=>$noShow,'canFinish'=>$canFinish,'errors'=>$errors,
            'old'=>isset($errors['version']) ? [] : array_filter($posted ?? [],'is_string'),
            'version'=>isset($errors['version']) || $posted===null ? AppointmentService::version($row) : input('version'),
            'doctorAbsent'=>AppointmentRepository::doctorAbsent($row),'policy'=>AppointmentRepository::policy()],$errors ? 422 : 200);
    }
    /**
     * Registra la atención (CU16) o la inasistencia (CU17). La inasistencia puede generar advertencia o multa.
     *
     * @param int $id cita
     * @param bool $noShow true para inasistencia
     * @return void
     * @throws HttpException 403 sin el permiso del CU correspondiente
     */
    public static function finish(int $id, bool $noShow=false): void
    {
        $user=self::authorize($noShow ? 'CU17' : 'CU16');
        try {
            if ($noShow) { AppointmentService::noShow($id,$_POST,(int) $user['id']); }
            else { AppointmentService::attend($id,$_POST,(int) $user['id']); }
            Session::flash($noShow ? 'Inasistencia registrada. Revisa en el detalle la advertencia, multa o revisión que corresponda.'
                : 'La cita quedó Atendida y se conservó en el historial.');
            redirect('/citas/'.$id);
        } catch (ValidationException $e) { self::finishForm($id,$noShow,$_POST,$e->errors); }
    }
}
