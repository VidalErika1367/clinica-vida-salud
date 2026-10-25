<?php
declare(strict_types=1);
namespace App;

use App\Repositories\AuditRepository;
use App\Repositories\PatientRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use PDOException;

final class PatientService
{
    public const FIELDS = ['ci','nombres','apellidos','fecha_nacimiento','sexo','telefono','correo','direccion'];

    public static function code(int $id): string { return 'PA-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT); }

    public static function find(int $id, bool $lock = false): array
    {
        $patient = PatientRepository::find($id, $lock);
        if (!$patient) { throw new HttpException(404, 'No se encontró el paciente.'); }
        return $patient;
    }

    public static function search(string $query, string $state): array
    {
        return PatientRepository::search($query, $state);
    }

    public static function summary(int $id): array { return PatientRepository::summary($id); }

    public static function version(array $patient): string
    {
        $values = [];
        foreach ([...self::FIELDS, 'usuario_id','activo','reactivado_en'] as $key) {
            $values[$key] = $patient[$key] ?? null;
        }
        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function requireStaff(int $actorId): void
    {
        $user = UserRepository::sessionAccount($actorId);
        if (!$user || !in_array($user['rol'], ['administrador','recepcionista'], true)
            || !in_array('CU06', RoleRepository::forUser($actorId), true)) {
            throw new HttpException(403, 'Tu cuenta no tiene permiso para gestionar pacientes.');
        }
    }

    private static function checkVersion(array $patient, mixed $version): void
    {
        if (!is_string($version) || !hash_equals(self::version($patient), $version)) {
            throw new ValidationException(['version' =>
                'El paciente cambió desde que abriste el formulario. Revisa los datos actuales antes de guardar.']);
        }
    }

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
}
