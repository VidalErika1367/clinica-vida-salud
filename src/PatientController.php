<?php
declare(strict_types=1);
namespace App;

final class PatientController
{
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

    public static function index(): void
    {
        self::authorize();
        $query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
        $state = is_string($_GET['estado'] ?? null) ? $_GET['estado'] : 'todos';
        if (!in_array($state, ['todos','activos','inactivos'], true)) { $state = 'todos'; }
        View::render('patients', ['title'=>'Pacientes','section'=>'pacientes',
            'patients'=>PatientService::search($query,$state),'query'=>$query,'state'=>$state]);
    }

    public static function form(?int $id = null): void
    {
        self::authorize();
        $patient = $id === null ? null : PatientService::find($id);
        View::render('patient_form', ['title'=>$id ? 'Editar paciente' : 'Nuevo paciente',
            'section'=>'pacientes','patient'=>$patient,'old'=>$patient ?? [],'editing'=>$id,
            'version'=>$patient ? PatientService::version($patient) : '']);
    }

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

    public static function detail(int $id, array $errors = [], string $reason = ''): void
    {
        self::authorize();
        $patient = PatientService::find($id);
        View::render('patient_detail', ['title'=>'Ficha del paciente','section'=>'pacientes',
            'patient'=>$patient,'summary'=>PatientService::summary($id),
            'version'=>PatientService::version($patient),'errors'=>$errors,'reason'=>$reason], $errors ? 422 : 200);
    }

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
}
