<?php
declare(strict_types=1);
namespace App;
use App\Repositories\DoctorRepository;
use App\Repositories\SpecialtyRepository;

final class SpecialtyController
{
    public static function index(): void
    {
        Auth::requireAdmin('CU09');
        $query=is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']),0,100) : '';
        $state=is_string($_GET['estado'] ?? null) ? $_GET['estado'] : 'todos';
        if (!in_array($state,['todos','activos','inactivos'],true)) { $state='todos'; }
        View::render('specialties',['title'=>'Especialidades','section'=>'especialidades',
            'items'=>SpecialtyRepository::search($query,$state),'query'=>$query,'state'=>$state]);
    }
    private static function render(?array $item, array $old, array $errors=[], ?string $version=null): void
    {
        View::render('specialty_form',['title'=>$item ? 'Editar especialidad' : 'Nueva especialidad',
            'section'=>'especialidades','item'=>$item,'old'=>$old,'errors'=>$errors,
            'version'=>$version ?? ($item ? SpecialtyService::version($item) : '')],$errors ? 422 : 200);
    }
    public static function form(?int $id=null): void
    {
        Auth::requireAdmin('CU09');
        $item=$id === null ? null : SpecialtyService::find($id);
        self::render($item,$item ?? ['activo'=>true]);
    }
    public static function save(?int $id=null): void
    {
        $user=Auth::requireAdmin('CU09');
        if ($id !== null) { SpecialtyService::find($id); }
        try {
            $saved=SpecialtyService::save($_POST,(int) $user['id'],$id);
            Session::flash('La especialidad se guardó. Las citas y asociaciones existentes se conservan.');
            redirect('/especialidades/' . $saved);
        } catch (ValidationException $e) {
            $item=$id === null ? null : SpecialtyService::find($id);
            $stale=isset($e->errors['version']);
            self::render($item,$stale ? $item : SpecialtyService::data($_POST),$e->errors,
                $stale ? null : input('version'));
        }
    }
    public static function detail(int $id): void
    {
        Auth::requireAdmin('CU09');
        View::render('specialty_detail',['title'=>'Detalle de especialidad','section'=>'especialidades',
            'item'=>SpecialtyService::find($id),'doctors'=>SpecialtyRepository::doctors($id),
            'upcoming'=>DoctorRepository::upcoming(specialtyId:$id)]);
    }
}
