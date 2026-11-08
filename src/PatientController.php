<?php
declare(strict_types=1);
namespace App;

/**
 * Gestión administrativa de pacientes (CU06), desactivación, reactivación administrativa (CU25)
 * y listado de reactivaciones. Accesible para administración y recepción según la matriz de permisos.
 */
final class PatientController
{
    /**
     * Exige rol administrador o recepcionista con el permiso CU06.
     *
     * @return array usuario autenticado
     * @throws HttpException 403 si no cumple
     */
    private static function authorize(): array
    {
        return Auth::requireRolePermission('CU06', ['administrador','recepcionista']);
    }

    private static function data(): array
    {
        $data = [];
        foreach ([...PatientService::FIELDS, 'version'] as $field) { $data[$field] = input($field); }
        return $data;
    }

    /**
     * Lista pacientes con búsqueda y filtro de estado (CU06).
     *
     * @return void
     */
    public static function index(): void
    {
        self::authorize();
        $query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
        $state = is_string($_GET['estado'] ?? null) ? $_GET['estado'] : 'todos';
        if (!in_array($state, ['todos','activos','inactivos'], true)) { $state = 'todos'; }
        View::render('patients', ['title'=>'Pacientes','section'=>'pacientes',
            'patients'=>PatientService::search($query,$state),'query'=>$query,'state'=>$state]);
    }

    /**
     * Formulario de alta o edición de paciente (CU06).
     *
     * @param int|null $id paciente a editar; null para alta
     * @return void
     */
    public static function form(?int $id = null): void
    {
        self::authorize();
        $patient = $id === null ? null : PatientService::find($id);
        View::render('patient_form', ['title'=>$id ? 'Editar paciente' : 'Nuevo paciente',
            'section'=>'pacientes','patient'=>$patient,'old'=>$patient ?? [],'editing'=>$id,
            'version'=>$patient ? PatientService::version($patient) : '']);
    }

    /**
     * Guarda un paciente (CU06). Si la huella de versión no coincide, vuelve a mostrar los datos actuales.
     *
     * @param int|null $id paciente a modificar; null para alta
     * @return void
     */
    public static function save(?int $id = null): void
    {
        $user = self::authorize();
        if ($id !== null) { PatientService::find($id); }
        $data = self::data();
        try {
            $savedId = PatientService::save($data, (int) $user['id'], $id);
            Session::flash($id ? 'Los datos del paciente se guardaron.' : 'El paciente fue registrado.');
            redirect('/pacientes/' . $savedId);
        } catch (ValidationException $e) {
            $patient = $id === null ? null : PatientService::find($id);
            $stale = isset($e->errors['version']);
            View::render('patient_form', ['title'=>$id ? 'Editar paciente' : 'Nuevo paciente',
                'section'=>'pacientes','editing'=>$id,'patient'=>$patient,'errors'=>$e->errors,
                'old'=>$stale ? $patient : $data,
                'version'=>$stale ? PatientService::version($patient) : $data['version']], 422);
        }
    }

    /**
     * Ficha del paciente con su resumen administrativo (CU06).
     *
     * @param int $id paciente
     * @param array $errors errores a mostrar (p. ej. al desactivar sin motivo)
     * @param string $reason motivo escrito, para no perderlo al mostrar errores
     * @return void
     */
    public static function detail(int $id, array $errors = [], string $reason = ''): void
    {
        self::authorize();
        $patient = PatientService::find($id);
        View::render('patient_detail', ['title'=>'Ficha del paciente','section'=>'pacientes',
            'patient'=>$patient,'summary'=>PatientService::summary($id),
            'version'=>PatientService::version($patient),'errors'=>$errors,'reason'=>$reason], $errors ? 422 : 200);
    }

    /**
     * Desactiva un paciente con motivo (CU06). No elimina citas, multas ni la cuenta de acceso.
     *
     * @param int $id paciente
     * @return void
     */
    public static function deactivate(int $id): void
    {
        $user = self::authorize();
        try {
            $changed = PatientService::deactivate($id, (int) $user['id'], input('motivo'), input('version'));
            Session::flash($changed ? 'El paciente quedó inactivo. Sus citas, multas y cuenta de acceso se conservan.'
                : 'El paciente ya estaba inactivo; no se realizaron cambios.');
            redirect('/pacientes/' . $id);
        } catch (ValidationException $e) {
            self::detail($id, $e->errors, input('motivo'));
        }
    }
    /**
     * Lista pacientes inactivos candidatos a reactivación (CU25).
     *
     * @return void
     */
    public static function reactivationIndex(): void
    {
        $current=Auth::requireUser(); $user=AppointmentService::actor((int) $current['id'],'CU25');
        $query=is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']),0,100) : '';
        View::render('patient_reactivations',['title'=>'Reactivar pacientes','section'=>'reactivaciones','user'=>$user,
            'query'=>$query,'patients'=>PatientService::search($query,'inactivos')]);
    }
    /**
     * Formulario de reactivación con el historial administrativo del paciente (CU25).
     *
     * @param int $id paciente
     * @param array|null $posted datos enviados, para conservarlos al mostrar errores
     * @param array $errors errores de validación
     * @return void
     */
    public static function reactivateForm(int $id, ?array $posted=null, array $errors=[]): void
    {
        $current=Auth::requireUser(); $user=AppointmentService::actor((int) $current['id'],'CU25');
        $patient=PatientService::find($id);
        $events=Database::run("SELECT accion,motivo,creado_en FROM evento_administrativo WHERE paciente_id=?
            AND accion IN ('desactivar_paciente','inactividad','reactivar') ORDER BY id DESC LIMIT 20",[$id])->fetchAll();
        View::render('patient_reactivate',['title'=>'Reactivar paciente','section'=>'reactivaciones','user'=>$user,
            'patient'=>$patient,'summary'=>PatientService::summary($id),'events'=>$events,'errors'=>$errors,
            'old'=>isset($errors['version']) ? [] : array_filter($posted ?? [],'is_string'),
            'version'=>$posted===null || isset($errors['version']) ? PatientService::version($patient) : input('version')],$errors ? 422 : 200);
    }
    /**
     * Reactiva un paciente tras verificar la identidad y las deudas (CU25).
     * La reactivación no borra multas: las pendientes siguen vigentes.
     *
     * @param int $id paciente
     * @return void
     */
    public static function reactivate(int $id): void
    {
        $current=Auth::requireUser(); $user=AppointmentService::actor((int) $current['id'],'CU25');
        try {
            PatientService::reactivate($id,$_POST,(int) $user['id']);
            Session::flash('El paciente quedó Activo. Su cuenta, citas e incidencias se conservaron; las multas pendientes siguen vigentes.');
            redirect('/pacientes/'.$id.'/reactivar');
        } catch (ValidationException $e) { self::reactivateForm($id,$_POST,$e->errors); }
    }
}
