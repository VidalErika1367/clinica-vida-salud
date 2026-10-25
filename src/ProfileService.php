<?php
declare(strict_types=1);
namespace App;

use App\Repositories\AuditRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;

final class ProfileService
{
    public const FIELDS = ['telefono','correo','direccion','tipo_sangre','alergias',
        'enfermedades_cronicas','medicamentos','antecedentes',
        'emergencia_nombre','emergencia_parentesco','emergencia_telefono'];

    public static function values(array $input): array
    {
        $data = [];
        foreach (self::FIELDS as $key) {
            $data[$key] = is_string($input[$key] ?? null)
                ? trim(str_replace(["\r\n", "\r"], "\n", $input[$key])) : '';
        }
        $data['correo'] = mb_strtolower($data['correo']);
        $data['tipo_sangre'] = mb_strtoupper($data['tipo_sangre']);
        return $data;
    }

    public static function findForUser(int $userId, bool $lock = false, array $selectors = []): array
    {
        $user = UserRepository::sessionAccount($userId);
        if (!$user || $user['rol'] !== 'paciente' || !in_array('CU07', RoleRepository::forUser($userId), true)) {
            throw new HttpException(403, 'Tu cuenta no tiene permiso para actualizar este perfil.');
        }
        // La identidad del propietario se obtiene de la sesión, nunca de un id enviado por el navegador.
        $profile = ProfileRepository::forUser($userId, $lock);
        if (!$profile) { throw new HttpException(404, 'Tu cuenta no tiene una ficha de paciente vinculada. Consulta en recepción.'); }
        foreach (['id'=>$profile['id'],'paciente_id'=>$profile['id'],'usuario_id'=>$userId] as $key=>$expected) {
            if (array_key_exists($key, $selectors)
                && (!is_string($selectors[$key]) || $selectors[$key] !== (string) $expected)) {
                throw new HttpException(403, 'Solo puedes consultar y actualizar tu propio perfil.');
            }
        }
        return $profile;
    }

    public static function version(array $profile): string
    {
        return hash('sha256', json_encode(['id'=>$profile['id'],
            'paciente'=>PatientService::version($profile),'perfil'=>self::values($profile)],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function save(array $input, int $userId): void
    {
        Database::transaction(function () use ($input, $userId): void {
            $profile = self::findForUser($userId, true, $input);
            if (!is_string($input['version'] ?? null) || !hash_equals(self::version($profile), $input['version'])) {
                throw new ValidationException(['version'=>
                    'Tu perfil cambió desde que abriste el formulario. Revisa los datos actuales antes de guardar.']);
            }
            foreach (['ci','nombres','apellidos','fecha_nacimiento','sexo'] as $key) {
                if (array_key_exists($key, $input)
                    && (!is_string($input[$key]) || trim($input[$key]) !== (string) ($profile[$key] ?? ''))) {
                    throw new ValidationException(['general'=>'Los datos de identidad se actualizan en recepción.']);
                }
            }
            $data = self::values($input);
            $errors = Validation::patientProfile($data);
            foreach (self::FIELDS as $key) {
                if (array_key_exists($key, $input) && (!is_string($input[$key]) || !mb_check_encoding($input[$key], 'UTF-8'))) {
                    $errors[$key] = 'Revisa el dato ingresado.';
                }
            }
            if ($errors) { throw new ValidationException($errors); }
            $before = self::values($profile);
            $changed = array_keys(array_filter($data, static fn($value, $key) => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));
            if ($changed) {
                ProfileRepository::save((int) $profile['id'], $data);
                // La auditoría registra campos modificados, sin copiar información médica ni contactos.
                AuditRepository::record($userId, 'actualizar_perfil', 'Campos actualizados: ' . implode(', ', $changed), (int) $profile['id']);
            }
        });
    }
}
