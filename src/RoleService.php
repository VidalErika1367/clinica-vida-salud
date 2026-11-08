<?php
declare(strict_types=1);
namespace App;

use App\Repositories\AuditRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;

/**
 * Matriz de roles y permisos (CU05). Los permisos por rol se guardan en base de datos; la política
 * (qué puede asignarse y qué es obligatorio) está en config/permisos.php.
 */
final class RoleService
{
    /**
     * Política de permisos: disponibles y obligatorios por rol.
     *
     * @return array política por rol
     */
    public static function policy(): array { return require BASE_PATH . '/config/permisos.php'; }

    /**
     * Huella de la matriz: independiente del orden de las filas. Sirve para detectar cambios concurrentes.
     *
     * @param array $matrix permisos por CU y rol
     * @return string SHA-256 en hexadecimal
     */
    public static function version(array $matrix): string
    {
        $entries = [];
        foreach ($matrix as $permission => $roles) {
            foreach ($roles as $role => $enabled) {
                if ($enabled) { $entries[] = $role . ':' . $permission; }
            }
        }
        sort($entries, SORT_STRING);
        return hash('sha256', implode("\n", $entries));
    }

    /**
     * Datos de la pantalla de roles y permisos (CU05).
     *
     * @return array datos para la vista
     */
    public static function viewData(): array
    {
        $matrix = RoleRepository::matrix();
        return [
            'title' => 'Roles y permisos', 'roles' => RoleRepository::all(),
            'matrix' => $matrix, 'cases' => require BASE_PATH . '/config/casos.php',
            'policy' => self::policy(), 'version' => self::version($matrix),
        ];
    }

    /**
     * Valida la selección de permisos contra la política: solo CU disponibles por rol y sin quitar los obligatorios.
     * Porqué: impide que la matriz guardada permita a un paciente operaciones administrativas.
     *
     * @param mixed $selection permisos elegidos por rol
     * @param mixed $present roles presentes en el formulario
     * @return array selección normalizada y ordenada por rol
     * @throws ValidationException si la selección no cumple la política
     */
    public static function validateSelection(mixed $selection, mixed $present): array
    {
        $policy = self::policy();
        $fail = static function (string $message): never {
            throw new ValidationException(['general' => $message]);
        };
        if (!is_array($selection) || !is_array($present)
            || array_diff(array_keys($selection), array_keys($policy))
            || array_diff(array_keys($present), array_keys($policy))
            || array_diff(array_keys($policy), array_keys($present))) {
            $fail('La matriz de permisos está incompleta o contiene un rol desconocido. Revisa el formulario.');
        }
        foreach ($present as $value) {
            if ($value !== '1') { $fail('El formulario de roles no es válido.'); }
        }
        $normalized = [];
        foreach ($policy as $role => $rules) {
            $codes = $selection[$role] ?? [];
            if (!is_array($codes) || count($codes) > 30) { $fail('Los permisos enviados no son válidos.'); }
            foreach ($codes as $code) {
                if (!is_string($code) || !in_array($code, $rules['disponibles'], true)) {
                    $fail('Una operación seleccionada no corresponde a las responsabilidades de ese rol.');
                }
            }
            $codes = array_values(array_unique($codes));
            if (array_diff($rules['obligatorios'], $codes)) {
                $fail('Debes conservar el inicio y cierre de sesión y los accesos básicos de administración.');
            }
            sort($codes, SORT_STRING);
            $normalized[$role] = $codes;
        }
        return $normalized;
    }

    /**
     * Guarda la matriz de permisos (CU05) y audita cada permiso que cambió.
     * Porqué: la huella previa evita sobrescribir cambios hechos en otra ventana; el bloqueo serializa los cambios de permisos.
     *
     * @param array $input permisos, roles presentes y huella de la matriz
     * @param int $actorId administrador que guarda
     * @return int número de permisos modificados
     * @throws ValidationException si la matriz no es válida, cambió en otra ventana o no quedaría un administrador
     * @throws HttpException 403 si el actor no tiene CU05
     */
    public static function saveMatrix(array $input, int $actorId): int
    {
        $selection = self::validateSelection($input['permisos'] ?? null, $input['roles_presentes'] ?? null);
        $version = $input['version'] ?? null;
        if (!is_string($version) || !preg_match('/^[a-f0-9]{64}$/D', $version)) {
            throw new ValidationException(['general' => 'Vuelve a abrir Roles y permisos antes de guardar.']);
        }
        return Database::transaction(function () use ($selection, $version, $actorId): int {
            RoleRepository::lockPermissions();
            $actor = UserRepository::sessionAccount($actorId);
            if (!$actor || $actor['rol'] !== 'administrador'
                || !in_array('CU05', RoleRepository::forUser($actorId), true)) {
                throw new HttpException(403, 'Tu cuenta no tiene permiso para modificar los roles.');
            }
            if (UserRepository::enabledAdministratorCount() < 1) {
                throw new ValidationException(['general' => 'Debe conservarse al menos un administrador habilitado.']);
            }
            $before = RoleRepository::matrix();
            if (!hash_equals(self::version($before), $version)) {
                throw new ValidationException(['general' =>
                    'Los permisos cambiaron en otra ventana. Se muestra la matriz actual; revísala antes de guardar.']);
            }
            RoleRepository::replace($selection);
            $changes = 0;
            foreach ($selection as $role => $codes) {
                foreach (array_keys(require BASE_PATH . '/config/casos.php') as $code) {
                    $wasEnabled = isset($before[$code][$role]);
                    $isEnabled = in_array($code, $codes, true);
                    if ($wasEnabled !== $isEnabled) {
                        AuditRepository::record($actorId, 'actualizar_permiso',
                            'Rol ' . $role . ': ' . ($isEnabled ? 'habilitar ' : 'retirar ') . $code);
                        $changes++;
                    }
                }
            }
            return $changes;
        });
    }
}
