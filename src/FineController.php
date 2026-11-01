<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AvailabilityRepository;
use App\Repositories\PatientRepository;

final class FineController
{
    private static function authorize(string $permission): array
    {
        $user=Auth::requireUser(); return AppointmentService::actor((int) $user['id'],$permission);
    }
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
    public static function form(int $id, ?array $posted=null, array $errors=[]): void
    {
        $user=self::authorize('CU23'); $fine=FineService::find($id);
        View::render('fine_payment',['title'=>'Pago de '.FineService::code($id),'section'=>'pagos','user'=>$user,'fine'=>$fine,
            'old'=>isset($errors['version']) ? [] : array_filter($posted ?? [],'is_string'),'errors'=>$errors,
            'version'=>$posted===null || isset($errors['version']) ? FineService::version($fine) : input('version'),
            'clock'=>AvailabilityRepository::clock()],$errors ? 422 : 200);
    }
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
