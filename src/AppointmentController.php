<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AppointmentRepository;
use App\Repositories\AvailabilityRepository;
use App\Repositories\PatientRepository;
use App\Repositories\SpecialtyRepository;

final class AppointmentController
{
    private static function authorize(?string $permission=null): array
    {
        $user=Auth::requireUser();
        return AppointmentService::actor((int) $user['id'],$permission);
    }
    public static function index(): void
    {
        $user=self::authorize();
        $query=is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']),0,100) : '';
        $state=is_string($_GET['estado'] ?? null) ? $_GET['estado'] : 'vigentes';
        if (!in_array($state,['vigentes','todos','programada','confirmada','cancelada','atendida','inasistencia'],true)) { $state='vigentes'; }
        View::render('appointments',['title'=>$user['rol']==='paciente' ? 'Mis citas' : 'Citas','section'=>'citas',
            'items'=>AppointmentRepository::search($user['rol']==='paciente' ? (int) $user['id'] : null,$query,$state),
            'query'=>$query,'state'=>$state]);
    }
    public static function detail(int $id, array $errors=[]): void
    {
        $user=self::authorize(); $row=AppointmentService::find($id,$user);
        View::render('appointment_detail',['title'=>'Cita '.AppointmentService::code($id),'section'=>'citas','appointment'=>$row,
            'version'=>AppointmentService::version($row),'errors'=>$errors,'history'=>AppointmentRepository::history($id),
            'incident'=>AppointmentRepository::incident($id)],$errors ? 422 : 200);
    }
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
    public static function book(): void
    {
        $user=self::authorize('CU12');
        try {
            $id=AppointmentService::book($_POST,(int) $user['id']);
            Session::flash('La cita '.AppointmentService::code($id).' quedó Programada.');
            redirect('/citas/'.$id);
        } catch (ValidationException $e) { self::bookingForm($_POST,$e->errors); }
    }
    public static function confirm(int $id): void
    {
        $user=self::authorize('CU13');
        try {
            AppointmentService::confirm($id,$_POST,(int) $user['id']);
            Session::flash('Tu asistencia quedó confirmada. El turno se conserva.');
            redirect('/citas/'.$id);
        } catch (ValidationException $e) { self::detail($id,$e->errors); }
    }
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
    public static function reschedule(int $id): void
    {
        $user=self::authorize('CU14');
        try {
            AppointmentService::reschedule($id,$_POST,(int) $user['id']);
            Session::flash('La cita se reprogramó y quedó Programada para confirmar el nuevo turno.');
            redirect('/citas/'.$id);
        } catch (ValidationException $e) { self::rescheduleForm($id,$_POST,$e->errors); }
    }
    public static function cancelForm(int $id, ?array $posted=null, array $errors=[]): void
    {
        $user=self::authorize('CU15'); $row=AppointmentService::find($id,$user);
        View::render('appointment_cancel',['title'=>'Cancelar cita','section'=>'citas','appointment'=>$row,
            'old'=>isset($errors['version']) ? [] : ($posted ?? []),'errors'=>$errors,'version'=>isset($errors['version']) || $posted===null
                ? AppointmentService::version($row) : input('version'),'policy'=>AppointmentRepository::policy(),
            'clock'=>AvailabilityRepository::clock()],$errors ? 422 : 200);
    }
    public static function cancel(int $id): void
    {
        $user=self::authorize('CU15');
        try {
            AppointmentService::cancel($id,$_POST,(int) $user['id']);
            Session::flash('La cita quedó Cancelada. Se liberó su horario y se conservó el registro.');
            redirect('/citas/'.$id);
        } catch (ValidationException $e) { self::cancelForm($id,$_POST,$e->errors); }
    }
}
