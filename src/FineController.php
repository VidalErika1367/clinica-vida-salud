<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AvailabilityRepository;
use App\Repositories\PatientRepository;

/**
 * Advertencias y multas (CU24) y registro de pagos de multa (CU23).
 * Los pagos son irreversibles desde la pantalla: se registran como movimientos nuevos.
 */
final class FineController
{
    /**
     * Exige sesión y el permiso indicado; devuelve el actor con su alcance.
     *
     * @param string $permission código CU requerido (CU23 o CU24)
     * @return array actor autorizado
     * @throws HttpException 403 sin permiso
     */
    private static function authorize(string $permission): array
    {
        $user=Auth::requireUser(); return AppointmentService::actor((int) $user['id'],$permission);
    }
    /**
     * Listado de multas (CU24) o de la pantalla de pagos (CU23), con filtros y paginación.
     *
     * @param bool $payments true para la pantalla de pagos
     * @return void
     * @throws HttpException 403 sin el permiso del CU correspondiente
     */
    public static function index(bool $payments=false): void
    {
        $user=self::authorize($payments ? 'CU23' : 'CU24');
        $old=array_filter($_GET,'is_string');
        $data=['title'=>$payments ? 'Registrar pago de multa' : 'Advertencias y multas','section'=>$payments ? 'pagos' : 'multas',
            'user'=>$user,'payments'=>$payments,'old'=>$old,'errors'=>[],'patient'=>null,'patients'=>[],
            'query'=>$old['q'] ?? '','summary'=>null,'state'=>$old['estado'] ?? 'todas','states'=>FineService::STATES,
            'listing'=>['items'=>[],'total'=>0,'page'=>1,'pages'=>1],
            'paginationPath'=>$payments ? '/pagos' : '/multas','paginationQuery'=>$old];
        try { $data=array_replace($data,FineService::overview($_GET,(int) $user['id'],$payments)); }
        catch (ValidationException $e) {
            $data['errors']=$e->errors;
            if ($user['rol']!=='paciente') { $data['patients']=PatientRepository::search(mb_substr($data['query'],0,100),'todos'); }
        }
        View::render('fines',$data,$data['errors'] ? 422 : 200);
    }
    /**
     * Formulario de pago de una multa (CU23). Muestra el saldo vigente en el momento de cargar.
     *
     * @param int $id multa
     * @param array|null $posted datos enviados
     * @param array $errors errores de validación
     * @return void
     * @throws HttpException 404 si la multa no existe
     */
    public static function form(int $id, ?array $posted=null, array $errors=[]): void
    {
        $user=self::authorize('CU23'); $fine=FineService::find($id);
        View::render('fine_payment',['title'=>'Pago de '.FineService::code($id),'section'=>'pagos','user'=>$user,'fine'=>$fine,
            'old'=>isset($errors['version']) ? [] : array_filter($posted ?? [],'is_string'),'errors'=>$errors,
            'version'=>$posted===null || isset($errors['version']) ? FineService::version($fine) : input('version'),
            'clock'=>AvailabilityRepository::clock()],$errors ? 422 : 200);
    }
    /**
     * Registra un pago de multa (CU23). El saldo y el estado del paciente se recalculan en la transacción.
     *
     * @param int $id multa
     * @return void
     * @throws HttpException 403 sin permiso CU23
     */
    public static function pay(int $id): void
    {
        $user=self::authorize('CU23');
        try {
            FineService::pay($id,$_POST,(int) $user['id']);
            $fine=FineService::find($id);
            Session::flash('Pago registrado. Revisa el saldo pendiente y el estado del paciente.');
            redirect('/pagos?paciente_id='.(int) $fine['paciente_id']);
        } catch (ValidationException $e) { self::form($id,$_POST,$e->errors); }
    }
}
