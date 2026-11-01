<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AbsenceRepository;
use App\Repositories\DoctorRepository;

final class AbsenceController
{
    private static function actor(): array { return AppointmentService::actor((int) Auth::requireUser()['id'],'CU26'); }
    private static function page(): int
    {
        try { return AppointmentReadService::page($_GET['pagina'] ?? null); }
        catch (ValidationException) { throw new HttpException(422,'Selecciona una página válida.'); }
    }
    public static function index(): void
    {
        $user=self::actor(); $page=self::page();
        View::render('absences',['title'=>'Ausencias médicas','section'=>'ausencias','user'=>$user,
            'listing'=>AbsenceRepository::listing($page),'paginationPath'=>'/ausencias','paginationQuery'=>[]]);
    }
    public static function form(?array $posted=null, array $errors=[]): void
    {
        $user=self::actor(); $old=array_filter($posted ?? [],'is_string');
        if (!preg_match('/^[a-f0-9]{32}$/D',$old['clave_envio'] ?? '')) { $old['clave_envio']=bin2hex(random_bytes(16)); }
        View::render('absence_form',['title'=>'Registrar ausencia','section'=>'ausencias','user'=>$user,'old'=>$old,'errors'=>$errors,
            'doctors'=>DoctorRepository::search('','todos')],$errors ? 422 : 200);
    }
    public static function create(): void
    {
        $user=self::actor();
        try { $id=AbsenceService::create($_POST,(int) $user['id']); Session::flash('Ausencia registrada. Coordina cada cita afectada con su paciente.'); redirect('/ausencias/'.$id); }
        catch (ValidationException $e) { self::form($_POST,$e->errors); }
    }
    public static function detail(int $id, array $errors=[]): void
    {
        $user=self::actor(); $row=AbsenceService::find($id); $page=self::page();
        View::render('absence_detail',['title'=>'Ausencia médica','section'=>'ausencias','user'=>$user,'absence'=>$row,'errors'=>$errors,
            'listing'=>AbsenceRepository::affected($row,$page),'history'=>AbsenceRepository::history($id),
            'paginationPath'=>'/ausencias/'.$id,'paginationQuery'=>[]],$errors ? 422 : 200);
    }
    public static function manageForm(int $id, int $appointment, ?array $posted=null, array $errors=[]): void
    {
        $user=self::actor(); $absence=AbsenceService::find($id); $row=AppointmentService::find($appointment,$user);
        if (!AbsenceService::pending($absence,$row)) { self::detail($id,['general'=>'Esta cita ya no está pendiente por esa ausencia.']); return; }
        $old=array_filter(isset($errors['version']) ? [] : ($posted ?? $_GET),'is_string');
        $old['especialidad_id']=(string) $row['especialidad_id'];
        $availability=AvailabilityService::form($old,$appointment);
        View::render('absence_manage',['title'=>'Gestionar cita afectada','section'=>'ausencias','user'=>$user,
            'absence'=>$absence,'appointment'=>$row,'old'=>$old,'errors'=>$errors+$availability['errors'],
            'availability'=>$availability,'version'=>$posted===null || isset($errors['version']) ? AppointmentService::version($row) : input('version')],$errors ? 422 : 200);
    }
    public static function manage(int $id, int $appointment): void
    {
        $user=self::actor();
        try { AbsenceService::manage($id,$appointment,$_POST,(int) $user['id']); Session::flash('Cambio registrado con origen Clínica, sin sanción al paciente.'); redirect('/ausencias/'.$id); }
        catch (ValidationException $e) { self::manageForm($id,$appointment,$_POST,$e->errors); }
    }
}
