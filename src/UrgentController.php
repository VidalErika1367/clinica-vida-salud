<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AppointmentRepository;
use App\Repositories\PatientRepository;
use App\Repositories\UrgentRepository;

/**
 * Solicitudes de cita urgente: solicitud por el paciente (CU21), gestión por recepción (CU22)
 * y consulta del listado y detalle. Las citas se confirman en el flujo de UrgentService.
 */
final class UrgentController
{
    /**
     * Exige sesión y, si se indica, el permiso del CU; devuelve el actor de urgencias.
     *
     * @param string|null $permission código CU requerido
     * @return array actor autorizado
     * @throws HttpException 403 sin permiso
     */
    private static function authorize(?string $permission=null): array
    {
        $user=Auth::requireUser();
        return $permission ? AppointmentService::actor((int) $user['id'],$permission) : UrgentService::actor((int) $user['id']);
    }
    /**
     * Listado de solicitudes urgentes filtrado por estado y por paciente si el actor es paciente.
     *
     * @return void
     * @throws HttpException 422 si el estado o la página no son válidos
     */
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
    /**
     * Formulario de solicitud urgente del paciente (CU21).
     * La clave de envío evita duplicar la solicitud si el formulario se reenvía.
     *
     * @param array|null $posted datos enviados
     * @param array $errors errores de validación
     * @return void
     * @throws HttpException 403 si la cuenta no tiene ficha de paciente vinculada
     */
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
    /**
     * Crea la solicitud urgente (CU21) en estado pendiente de revisión.
     *
     * @return void
     * @throws HttpException 403 sin permiso CU21
     */
    public static function create(): void
    {
        $user=self::authorize('CU21');
        try {
            $id=UrgentService::create($_POST,(int) $user['id']);
            Session::flash('La solicitud '.UrgentService::code($id).' quedó pendiente de revisión por recepción.');
            redirect('/urgencias/'.$id);
        } catch (ValidationException $e) { self::form($_POST,$e->errors); }
    }
    /**
     * Detalle de una solicitud urgente. Para recepción incluye disponibilidad de turnos y restricciones del paciente.
     *
     * @param int $id solicitud
     * @param array|null $posted datos enviados al gestionar
     * @param array $errors errores de validación
     * @return void
     * @throws HttpException 404 si la solicitud no es visible para el actor
     */
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
    /**
     * Gestiona la solicitud urgente (CU22): asigna un turno o la cierra, según la acción de recepción.
     *
     * @param int $id solicitud
     * @return void
     * @throws HttpException 403 sin permiso CU22
     */
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
