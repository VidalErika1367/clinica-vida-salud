<?php
declare(strict_types=1);
namespace App;

use App\Repositories\AuditRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;

/**
 * Perfil de salud y contacto de emergencia del paciente (CU07). Los datos de identidad no se editan aquí.
 */
final class ProfileService
{
    /** Campos que el paciente puede editar en su perfil. */
    public const FIELDS = ['telefono','correo','direccion','tipo_sangre','alergias',
        'enfermedades_cronicas','medicamentos','antecedentes',
        'emergencia_nombre','emergencia_parentesco','emergencia_telefono'];

    /**
     * Normaliza los datos del perfil: recorta espacios, unifica saltos de línea y pasa correo y tipo de sangre a formato estándar.
     *
     * @param array $input datos recibidos
     * @return array datos normalizados de los campos del perfil
     */
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

    /**
     * Devuelve el perfil del paciente de la sesión. Rechaza cualquier identificador enviado que no sea el propio.
     *
     * @param int $userId usuario de la sesión
     * @param bool $lock si es true, bloquea la ficha
     * @param array $selectors identificadores enviados por la petición, que se comparan con los reales
     * @return array perfil del paciente
     * @throws HttpException 403 si no es paciente con CU07 o si pide otro perfil; 404 si no tiene ficha vinculada
     */
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

    /**
     * Huella del perfil: incluye la ficha del paciente y los datos del perfil.
     *
     * @param array $profile perfil
     * @return string SHA-256 en hexadecimal
     */
    public static function version(array $profile): string
    {
        return hash('sha256', json_encode(['id'=>$profile['id'],
            'paciente'=>PatientService::version($profile),'perfil'=>self::values($profile)],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Guarda el perfil del paciente (CU07). Los datos de identidad (CI, nombres, fecha de nacimiento, sexo)
     * se rechazan si cambian: solo recepción los corrige.
     * Porqué: la auditoría registra los nombres de los campos modificados, nunca su contenido médico.
     *
     * @param array $input datos del formulario y versión
     * @param int $userId usuario paciente
     * @return void
     * @throws ValidationException si la versión cambió, intenta editar identidad o los datos no son válidos
     * @throws HttpException 403 o 404 según el acceso al perfil
     */
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
