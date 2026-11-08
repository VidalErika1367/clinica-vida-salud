<?php
declare(strict_types=1);
namespace App;

use App\Repositories\AuditRepository;
use App\Repositories\PatientRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use PDOException;

/**
 * Datos administrativos de pacientes (CU06), desactivación con motivo y reactivación (CU25).
 * La desactivación conserva citas, multas y cuenta; no borra registros.
 */
final class PatientService
{
    /** Campos administrativos del paciente que forman parte de su huella de versión. */
    public const FIELDS = ['ci','nombres','apellidos','fecha_nacimiento','sexo','telefono','correo','direccion'];

    /**
     * Código visible de un paciente, por ejemplo "PA-000004".
     *
     * @param int $id identificador interno
     * @return string código con seis dígitos
     */
    public static function code(int $id): string { return 'PA-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT); }

    /**
     * Busca un paciente por id.
     *
     * @param int $id paciente
     * @param bool $lock si es true, bloquea la fila para escribir
     * @return array datos del paciente
     * @throws HttpException 404 si no existe
     */
    public static function find(int $id, bool $lock = false): array
    {
        $patient = PatientRepository::find($id, $lock);
        if (!$patient) { throw new HttpException(404, 'No se encontró el paciente.'); }
        return $patient;
    }

    /**
     * Busca pacientes por texto y estado.
     *
     * @param string $query texto de búsqueda
     * @param string $state "todos", "activos" o "inactivos"
     * @return array pacientes
     */
    public static function search(string $query, string $state): array
    {
        return PatientRepository::search($query, $state);
    }

    /**
     * Resumen administrativo del paciente para su ficha.
     *
     * @param int $id paciente
     * @return array resumen
     */
    public static function summary(int $id): array { return PatientRepository::summary($id); }

    /**
     * Huella de los datos del paciente para control de versión.
     * Porqué: si otra persona cambió la ficha mientras el formulario estaba abierto, el guardado se rechaza.
     *
     * @param array $patient paciente
     * @return string SHA-256 en hexadecimal
     */
    public static function version(array $patient): string
    {
        $values = [];
        foreach ([...self::FIELDS, 'usuario_id','activo','reactivado_en'] as $key) {
            $values[$key] = $patient[$key] ?? null;
        }
        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Exige que el actor sea administrador o recepcionista con permiso CU06, leído de la base.
     *
     * @param int $actorId usuario que opera
     * @return void
     * @throws HttpException 403 si no cumple
     */
    private static function requireStaff(int $actorId): void
    {
        $user = UserRepository::sessionAccount($actorId);
        if (!$user || !in_array($user['rol'], ['administrador','recepcionista'], true)
            || !in_array('CU06', RoleRepository::forUser($actorId), true)) {
            throw new HttpException(403, 'Tu cuenta no tiene permiso para gestionar pacientes.');
        }
    }

    /**
     * Compara la huella del paciente con la enviada por el formulario.
     *
     * @param array $patient paciente actual
     * @param mixed $version huella recibida
     * @return void
     * @throws ValidationException si no coincide
     */
    private static function checkVersion(array $patient, mixed $version): void
    {
        if (!is_string($version) || !hash_equals(self::version($patient), $version)) {
            throw new ValidationException(['version' =>
                'El paciente cambió desde que abriste el formulario. Revisa los datos actuales antes de guardar.']);
        }
    }

    /**
     * Crea o actualiza un paciente (CU06). Solo audita los campos que realmente cambiaron.
     *
     * @param array $input datos del formulario y versión
     * @param int $actorId usuario que guarda
     * @param int|null $id paciente a modificar; null para alta
     * @return int identificador del paciente
     * @throws ValidationException si los datos no son válidos, el CI está repetido o la ficha cambió
     * @throws HttpException 403 sin permiso CU06
     */
    public static function save(array $input, int $actorId, ?int $id = null): int
    {
        $data = [];
        foreach (self::FIELDS as $field) {
            $data[$field] = is_string($input[$field] ?? null) ? trim($input[$field]) : '';
        }
        $data['ci'] = mb_strtoupper(preg_replace('/\s+/u', ' ', $data['ci']));
        $data['correo'] = mb_strtolower($data['correo']);
        $errors = Validation::patientDetails($data);
        if ($errors) { throw new ValidationException($errors); }
        try {
            return Database::transaction(function () use ($data,$input,$actorId,$id): int {
                self::requireStaff($actorId);
                if ($id === null) {
                    $newId = PatientRepository::create($data);
                    AuditRepository::record($actorId, 'crear_paciente', 'Registro de ' . self::code($newId), $newId);
                    return $newId;
                }
                $patient = self::find($id, true);
                self::checkVersion($patient, $input['version'] ?? null);
                $changed = [];
                foreach (self::FIELDS as $field) {
                    if ((string) ($patient[$field] ?? '') !== $data[$field]) { $changed[] = $field; }
                }
                if ($changed) {
                    PatientRepository::update($id, $data);
                    AuditRepository::record($actorId, 'actualizar_paciente',
                        'Campos actualizados: ' . implode(', ', $changed), $id);
                }
                return $id;
            });
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new ValidationException(['ci' => 'El CI ya está registrado. Busca al paciente y edita su ficha.']);
            }
            throw $e;
        }
    }

    /**
     * Desactiva un paciente con motivo obligatorio (CU06). Si ya estaba inactivo, no hace nada.
     * Porqué: no se borran citas, multas ni la cuenta; el motivo queda en auditoría.
     *
     * @param int $id paciente
     * @param int $actorId usuario que desactiva
     * @param mixed $reason motivo escrito
     * @param mixed $version huella de la ficha
     * @return bool true si hubo cambio; false si ya estaba inactivo
     * @throws ValidationException si el motivo no es válido o la ficha cambió
     */
    public static function deactivate(int $id, int $actorId, mixed $reason, mixed $version): bool
    {
        $reason = is_string($reason) ? trim($reason) : '';
        if ($reason === '' || mb_strlen($reason) > 250 || preg_match('/[\x00-\x1F<>]/u', $reason)) {
            throw new ValidationException(['motivo' => 'Escribe el motivo de desactivación, de hasta 250 caracteres.']);
        }
        return Database::transaction(function () use ($id,$actorId,$reason,$version): bool {
            self::requireStaff($actorId);
            $patient = self::find($id, true);
            if (!$patient['activo']) { return false; }
            self::checkVersion($patient, $version);
            PatientRepository::deactivate($id);
            AuditRepository::record($actorId, 'desactivar_paciente', $reason, $id);
            return true;
        });
    }
    /**
     * Reactiva un paciente inactivo (CU25) mediante el procedimiento de la base, con motivo y verificación de datos.
     * Porqué: la reactivación no borra multas pendientes; esas siguen vigentes.
     *
     * @param int $id paciente
     * @param array $input motivo, confirmación de datos verificados y versión
     * @param int $actorId usuario que reactiva
     * @return void
     * @throws ValidationException si falta confirmación, la ficha cambió o el paciente ya está activo
     * @throws HttpException 403 sin permiso CU25
     */
    public static function reactivate(int $id, array $input, int $actorId): void
    {
        $reason=CareSupport::text($input,'motivo');
        CareSupport::confirm($input,'datos_verificados','Verifica la identidad y los datos de contacto antes de reactivar al paciente.');
        CareSupport::transaction(function () use ($id,$input,$actorId,$reason): void {
            UserRepository::lockAccounts(); AppointmentService::actor($actorId,'CU25');
            $patient=self::find($id,true);
            self::checkVersion($patient,$input['version'] ?? null);
            if ($patient['activo']) { throw new ValidationException(['general'=>'El paciente ya está activo. No requiere otra reactivación.']); }
            Database::run('CALL sp_reactivar_paciente(?,?,?)',[$id,$actorId,$reason]);
        });
    }
}
