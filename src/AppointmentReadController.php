<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AppointmentReadRepository;
use App\Repositories\AvailabilityRepository;
use App\Repositories\PatientRepository;
use App\Repositories\SpecialtyRepository;

/**
 * Pantallas de consulta de citas: agenda médica (CU18), historial (CU19) y reportes administrativos (CU20).
 * Cada sección tiene su propio permiso; el alcance (paciente, médico o todo) lo decide el servicio.
 */
final class AppointmentReadController
{
    /**
     * Muestra una sección de consulta. Los errores de filtro se muestran sin perder los datos escritos.
     *
     * @param string $section "agenda", "historial" o "reportes"
     * @return void
     * @throws HttpException 403 si el usuario no tiene el permiso de la sección
     */
    public static function show(string $section): void
    {
        $permission=['agenda'=>'CU18','historial'=>'CU19','reportes'=>'CU20'][$section];
        $current=Auth::requireUser(); $user=AppointmentService::actor((int) $current['id'],$permission);
        $today=AvailabilityRepository::clock()['hoy'];
        $old=array_filter($_GET,'is_string');
        $data=['title'=>['agenda'=>'Agenda médica','historial'=>'Historial de citas','reportes'=>'Reportes administrativos'][$section],
            'section'=>$section,'user'=>$user,'old'=>$old,'errors'=>[],'patient'=>null,'patients'=>[],
            'query'=>mb_substr(trim($old['q'] ?? ''),0,100),'absenceItems'=>[],'summary'=>null,'type'=>$old['tipo'] ?? 'citas',
            'generatedAt'=>AvailabilityRepository::clock()['ahora'],'states'=>AppointmentReadService::STATES,
            'filters'=>['desde'=>$today,'hasta'=>$today,'estado'=>'todos','estado_paciente'=>'todos','medico_id'=>null,'especialidad_id'=>null,'paciente_id'=>null],
            'doctors'=>[],'specialties'=>[], 'listing'=>['items'=>[],'total'=>0,'page'=>1,'pages'=>1]];
        try {
            $result=match ($section) {
                'agenda'=>AppointmentReadService::agenda($_GET,(int) $user['id']),
                'historial'=>AppointmentReadService::history($_GET,(int) $user['id']),
                'reportes'=>AppointmentReadService::report($_GET,(int) $user['id']),
            };
            $data=array_replace($data,$result);
        } catch (ValidationException $e) {
            $data['errors']=$e->errors;
            if ($section==='agenda') { $data['doctors']=AppointmentReadRepository::doctors($user['rol']==='medico' ? (int) $user['id'] : null); }
            if ($section==='historial' && $user['rol']!=='paciente') { $data['patients']=PatientRepository::search($data['query'],'todos'); }
            if ($section==='reportes') {
                $data['doctors']=AppointmentReadRepository::doctors();
                $data['specialties']=SpecialtyRepository::search('','todos');
            }
        }
        $data['paginationQuery']=$old; $data['paginationPath']='/'.$section;
        View::render('appointment_'.$section,$data,$data['errors'] ? 422 : 200);
    }
}
