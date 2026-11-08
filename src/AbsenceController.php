<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AbsenceRepository;
use App\Repositories\DoctorRepository;

/**
 * Ausencias médicas (CU26): registro de la ausencia de un médico y gestión de las citas afectadas.
 * La reprogramación o cancelación de una cita por ausencia tiene origen "clínica" y no sanciona al paciente.
 */
final class AbsenceController
{
    /**
     * Actor autorizado para CU26 (recepción y administración según la matriz).
     *
     * @return array actor autorizado
     * @throws HttpException 403 sin permiso CU26
     */
    private static function actor(): array { return AppointmentService::actor((int) Auth::requireUser()['id'],'CU26'); }
    /**
     * Lee la página solicitada del listado.
     *
     * @return int número de página válido
     * @throws HttpException 422 si la página no es válida
     */
    private static function page(): int
    {
        try { return AppointmentReadService::page($_GET['pagina'] ?? null); }
        catch (ValidationException) { throw new HttpException(422,'Selecciona una página válida.'); }
    }
    /**
     * Listado de ausencias médicas (CU26).
     *
     * @return void
     */
    public static function index(): void
    {
        $user=self::actor(); $page=self::page();
        View::render('absences',['title'=>'Ausencias médicas','section'=>'ausencias','user'=>$user,
            'listing'=>AbsenceRepository::listing($page),'paginationPath'=>'/ausencias','paginationQuery'=>[]]);
    }
    /**
     * Formulario para registrar una ausencia (CU26). La clave de envío evita registrarla dos veces.
     *
     * @param array|null $posted datos enviados
     * @param array $errors errores de validación
     * @return void
     */
    public static function form(?array $posted=null, array $errors=[]): void
    {
        $user=self::actor(); $old=array_filter($posted ?? [],'is_string');
        if (!preg_match('/^[a-f0-9]{32}$/D',$old['clave_envio'] ?? '')) { $old['clave_envio']=bin2hex(random_bytes(16)); }
        View::render('absence_form',['title'=>'Registrar ausencia','section'=>'ausencias','user'=>$user,'old'=>$old,'errors'=>$errors,
            'doctors'=>DoctorRepository::search('','todos')],$errors ? 422 : 200);
    }
    /**
     * Registra la ausencia (CU26). Las citas afectadas quedan pendientes de gestión.
     *
     * @return void
     */
    public static function create(): void
    {
        $user=self::actor();
        try { $id=AbsenceService::create($_POST,(int) $user['id']); Session::flash('Ausencia registrada. Coordina cada cita afectada con su paciente.'); redirect('/ausencias/'.$id); }
        catch (ValidationException $e) { self::form($_POST,$e->errors); }
    }
    /**
     * Detalle de una ausencia con el listado paginado de citas afectadas (CU26).
     *
     * @param int $id ausencia
     * @param array $errors errores a mostrar
     * @return void
     * @throws HttpException 404 si la ausencia no existe
     */
    public static function detail(int $id, array $errors=[]): void
    {
        $user=self::actor(); $row=AbsenceService::find($id); $page=self::page();
        View::render('absence_detail',['title'=>'Ausencia médica','section'=>'ausencias','user'=>$user,'absence'=>$row,'errors'=>$errors,
            'listing'=>AbsenceRepository::affected($row,$page),'history'=>AbsenceRepository::history($id),
            'paginationPath'=>'/ausencias/'.$id,'paginationQuery'=>[]],$errors ? 422 : 200);
    }
    /**
     * Formulario para decidir el cambio de una cita afectada por la ausencia (CU26).
     * Si la cita ya no está pendiente por esa ausencia, vuelve al detalle con un aviso.
     *
     * @param int $id ausencia
     * @param int $appointment cita afectada
     * @param array|null $posted datos enviados
     * @param array $errors errores de validación
     * @return void
     * @throws HttpException 404 si la ausencia o la cita no existen
     */
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
    /**
     * Aplica el cambio de una cita afectada (CU26): reprogramar o cancelar con origen clínica.
     *
     * @param int $id ausencia
     * @param int $appointment cita afectada
     * @return void
     * @throws HttpException 404 si no existen
     */
    public static function manage(int $id, int $appointment): void
    {
        $user=self::actor();
        try { AbsenceService::manage($id,$appointment,$_POST,(int) $user['id']); Session::flash('Cambio registrado con origen Clínica, sin sanción al paciente.'); redirect('/ausencias/'.$id); }
        catch (ValidationException $e) { self::manageForm($id,$appointment,$_POST,$e->errors); }
    }
}
