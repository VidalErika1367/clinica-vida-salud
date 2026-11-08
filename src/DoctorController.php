<?php
declare(strict_types=1);
namespace App;
use App\Repositories\DoctorRepository;
use App\Repositories\SpecialtyRepository;

/**
 * Catálogo de médicos (CU08): listado con búsqueda, filtro por especialidad y estado, alta, edición y ficha.
 * Solo administradores. La cuenta de acceso del médico es opcional y se vincula sin crear usuarios nuevos.
 */
final class DoctorController
{
    /**
     * Lista médicos. El filtro de especialidad solo acepta especialidades activas.
     *
     * @return void
     * @throws HttpException 403 sin permiso CU08
     */
    public static function index(): void
    {
        Auth::requireAdmin('CU08');
        $query=is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']),0,100) : '';
        $state=is_string($_GET['estado'] ?? null) ? $_GET['estado'] : 'todos';
        if (!in_array($state,['todos','activos','inactivos'],true)) { $state='todos'; }
        $specialties=SpecialtyRepository::active();
        $specialtyId=DoctorService::specialtyFilter($_GET['especialidad'] ?? null, array_column($specialties,'id'));
        View::render('doctors',['title'=>'Médicos','section'=>'medicos','query'=>$query,'state'=>$state,
            'specialties'=>$specialties,'specialtyId'=>$specialtyId,
            'items'=>DoctorRepository::search($query,$state,$specialtyId)]);
    }
    /**
     * Renderiza el formulario de médico con especialidades, cuentas disponibles y la huella de versión.
     *
     * @param array|null $item médico actual, o null para alta
     * @param array $old valores a mostrar
     * @param array $errors errores de validación
     * @param string|null $version huella que devuelve el formulario; null para tomar la del registro
     * @return void
     */
    private static function render(?array $item, array $old, array $errors=[], ?string $version=null): void
    {
        View::render('doctor_form',['title'=>$item ? 'Editar médico' : 'Nuevo médico','section'=>'medicos',
            'item'=>$item,'old'=>$old,'errors'=>$errors,'specialties'=>SpecialtyRepository::active(),
            'accounts'=>DoctorRepository::accounts($item ? (int) $item['id'] : null),
            'version'=>$version ?? ($item ? DoctorService::version($item) : '')],$errors ? 422 : 200);
    }
    /**
     * Formulario de alta o edición de médico (CU08).
     *
     * @param int|null $id médico; null para alta
     * @return void
     * @throws HttpException 403 sin permiso CU08; 404 si no existe
     */
    public static function form(?int $id=null): void
    {
        Auth::requireAdmin('CU08');
        $item=$id === null ? null : DoctorService::find($id);
        self::render($item,$item ? DoctorService::formValues($item) : ['activo'=>true,'especialidades'=>[]]);
    }
    /**
     * Guarda el médico y sus especialidades (CU08). Avisa si cambió la disponibilidad de citas futuras.
     *
     * @param int|null $id médico; null para alta
     * @return void
     * @throws HttpException 403 sin permiso CU08; 404 si el médico a editar no existe
     */
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
    /**
     * Ficha del médico con sus próximas citas (CU08).
     *
     * @param int $id médico
     * @return void
     * @throws HttpException 403 sin permiso CU08; 404 si no existe
     */
    public static function detail(int $id): void
    {
        Auth::requireAdmin('CU08');
        View::render('doctor_detail',['title'=>'Ficha del médico','section'=>'medicos','item'=>DoctorService::find($id),
            'upcoming'=>DoctorRepository::upcoming(doctorId:$id)]);
    }
}
