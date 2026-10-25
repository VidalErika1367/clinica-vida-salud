<?php
declare(strict_types=1);
namespace App;
use App\Repositories\ScheduleRepository;

final class ScheduleController
{
    private static function selectedId(mixed $value): int
    {
        return is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/',$value) ? (int) $value : 0;
    }
    public static function index(): void
    {
        Auth::requireAdmin('CU10');
        $doctor=self::selectedId($_GET['medico_id'] ?? null);
        $day=self::selectedId($_GET['dia_semana'] ?? null);
        if (!isset(ScheduleService::DAYS[$day])) { $day=0; }
        $state=is_string($_GET['estado'] ?? null) ? $_GET['estado'] : 'todos';
        if (!in_array($state,['todos','activos','inactivos'],true)) { $state='todos'; }
        View::render('schedules',['title'=>'Horarios de atención','section'=>'horarios','doctorId'=>$doctor,
            'day'=>$day,'state'=>$state,'doctors'=>ScheduleRepository::doctors(),
            'items'=>ScheduleRepository::search($doctor,$day,$state)]);
    }
    private static function render(?array $item, array $old, array $errors=[], ?string $version=null, array $conflicts=[]): void
    {
        View::render('schedule_form',['title'=>$item ? 'Editar horario' : 'Nuevo horario','section'=>'horarios',
            'item'=>$item,'old'=>$old,'errors'=>$errors,'conflicts'=>$conflicts,
            'doctors'=>ScheduleRepository::doctors(true,$item ? (int) $item['medico_id'] : null),
            'version'=>$version ?? ($item ? ScheduleService::version($item) : '')],$errors ? 422 : 200);
    }
    public static function form(?int $id=null): void
    {
        Auth::requireAdmin('CU10');
        $item=$id===null ? null : ScheduleService::find($id);
        self::render($item,$item ? ScheduleService::formValues($item) : [
            'medico_id'=>(string) self::selectedId($_GET['medico_id'] ?? null),'dia_semana'=>'1','activo'=>true]);
    }
    public static function save(?int $id=null): void
    {
        $user=Auth::requireAdmin('CU10');
        if ($id!==null) { ScheduleService::find($id); }
        try {
            $saved=ScheduleService::save($_POST,(int) $user['id'],$id);
            Session::flash('El horario se guardó. Las citas y su historial se conservan.');
            redirect('/horarios/' . $saved . '/editar');
        } catch (ScheduleConflictException $e) {
            $item=$id===null ? null : ScheduleService::find($id);
            self::render($item,ScheduleService::data($_POST),['general'=>$e->getMessage()],input('version'),$e->appointments);
        } catch (ValidationException $e) {
            $item=$id===null ? null : ScheduleService::find($id);
            $stale=isset($e->errors['version']);
            self::render($item,$stale ? ScheduleService::formValues($item) : ScheduleService::data($_POST),
                $e->errors,$stale ? null : input('version'));
        }
    }
}
