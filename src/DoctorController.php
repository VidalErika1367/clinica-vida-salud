<?php
declare(strict_types=1);
namespace App;
use App\Repositories\DoctorRepository;
use App\Repositories\SpecialtyRepository;

final class DoctorController
{
    public static function index(): void
    {
        Auth::requireAdmin('CU08');
        $query=is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']),0,100) : '';
        $state=is_string($_GET['estado'] ?? null) ? $_GET['estado'] : 'todos';
        if (!in_array($state,['todos','activos','inactivos'],true)) { $state='todos'; }
        View::render('doctors',['title'=>'Médicos','section'=>'medicos','query'=>$query,'state'=>$state,
            'items'=>DoctorRepository::search($query,$state)]);
    }
    private static function render(?array $item, array $old, array $errors=[], ?string $version=null): void
    {
        View::render('doctor_form',['title'=>$item ? 'Editar médico' : 'Nuevo médico','section'=>'medicos',
            'item'=>$item,'old'=>$old,'errors'=>$errors,'specialties'=>SpecialtyRepository::active(),
            'accounts'=>DoctorRepository::accounts($item ? (int) $item['id'] : null),
            'version'=>$version ?? ($item ? DoctorService::version($item) : '')],$errors ? 422 : 200);
    }
    public static function form(?int $id=null): void
    {
        Auth::requireAdmin('CU08');
        $item=$id === null ? null : DoctorService::find($id);
        self::render($item,$item ? DoctorService::formValues($item) : ['activo'=>true,'especialidades'=>[]]);
    }
    public static function save(?int $id=null): void
    {
        $user=Auth::requireAdmin('CU08');
        if ($id !== null) { DoctorService::find($id); }
        try {
            $saved=DoctorService::save($_POST,(int) $user['id'],$id);
            Session::flash('El médico se guardó. Revisa las citas futuras si cambiaste su estado o especialidades.');
            redirect('/medicos/' . $saved);
        } catch (ValidationException $e) {
            $item=$id === null ? null : DoctorService::find($id);
            $stale=isset($e->errors['version']);
            self::render($item,$stale ? DoctorService::formValues($item) : DoctorService::data($_POST),$e->errors,
                $stale ? null : input('version'));
        }
    }
    public static function detail(int $id): void
    {
        Auth::requireAdmin('CU08');
        View::render('doctor_detail',['title'=>'Ficha del médico','section'=>'medicos','item'=>DoctorService::find($id),
            'upcoming'=>DoctorRepository::upcoming(doctorId:$id)]);
    }
}
