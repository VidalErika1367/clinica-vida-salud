<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AppointmentRepository;
use App\Repositories\PatientRepository;
use App\Repositories\UrgentRepository;

final class UrgentController
{
    private static function authorize(?string $permission=null): array
    {
        $user=Auth::requireUser();
        return $permission ? AppointmentService::actor((int) $user['id'],$permission) : UrgentService::actor((int) $user['id']);
    }
    public static function index(): void
    {
        $user=self::authorize();
        $state=is_string($_GET['estado'] ?? null) ? $_GET['estado'] : ($user['rol']==='paciente' ? 'todas' : 'pendiente');
        if (!in_array($state,['todas','pendiente','gestionada','cerrada'],true)) { throw new HttpException(422,'Selecciona un estado válido.'); }
        try { $page=AppointmentReadService::page($_GET['pagina'] ?? null); }
        catch (ValidationException) { throw new HttpException(422,'Selecciona una página válida.'); }
        View::render('urgent_index',['title'=>'Citas urgentes','section'=>'urgencias','user'=>$user,'state'=>$state,
            'listing'=>UrgentRepository::listing($user['rol']==='paciente' ? (int) $user['id'] : null,$state,$page),
            'paginationPath'=>'/urgencias','paginationQuery'=>['estado'=>$state]]);
    }
    public static function form(?array $posted=null, array $errors=[]): void
    {
        $user=self::authorize('CU21');
        if (!$user['paciente_id']) { throw new HttpException(403,'Tu cuenta no tiene una ficha de paciente vinculada.'); }
        $patient=PatientRepository::find((int) $user['paciente_id']);
        $old=array_filter($posted ?? ['contacto'=>$patient['telefono']],'is_string');
        if (!preg_match('/^[a-f0-9]{32}$/D',$old['clave_envio'] ?? '')) { $old['clave_envio']=bin2hex(random_bytes(16)); }
        View::render('urgent_form',['title'=>'Solicitar cita urgente','section'=>'urgencias','user'=>$user,
            'patient'=>$patient,'old'=>$old,'errors'=>$errors],$errors ? 422 : 200);
    }
    public static function create(): void
    {
        $user=self::authorize('CU21');
        try {
            $id=UrgentService::create($_POST,(int) $user['id']);
            Session::flash('La solicitud '.UrgentService::code($id).' quedó pendiente de revisión por recepción.');
            redirect('/urgencias/'.$id);
        } catch (ValidationException $e) { self::form($_POST,$e->errors); }
    }
    public static function detail(int $id, ?array $posted=null, array $errors=[]): void
    {
        $user=self::authorize(); $row=UrgentService::find($id,$user);
        $old=isset($errors['version']) ? [] : array_filter($posted ?? $_GET,'is_string');
        $availability=$user['rol']!=='paciente' && $row['estado']==='pendiente' ? AvailabilityService::form($old) : null;
        if ($availability) { $errors+=$availability['errors']; }
        View::render('urgent_detail',['title'=>'Solicitud '.UrgentService::code($id),'section'=>'urgencias','user'=>$user,
            'request'=>$row,'old'=>$old,'errors'=>$errors,'availability'=>$availability,
            'version'=>$posted===null || isset($errors['version']) ? UrgentService::version($row) : input('version'),
            'restricted'=>!$row['paciente_activo'] || AppointmentRepository::debt((int) $row['paciente_id']),
            'history'=>UrgentRepository::history($id)],$errors ? 422 : 200);
    }
    public static function manage(int $id): void
    {
        $user=self::authorize('CU22');
        try {
            UrgentService::manage($id,$_POST,(int) $user['id']);
            Session::flash('La gestión de la solicitud quedó registrada.');
            redirect('/urgencias/'.$id);
        } catch (ValidationException $e) { self::detail($id,$_POST,$e->errors); }
    }
}
