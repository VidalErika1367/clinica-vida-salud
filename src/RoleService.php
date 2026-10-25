<?php
declare(strict_types=1);
namespace App;

use App\Repositories\AuditRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;

final class RoleService
{
    public static function policy(): array { return require BASE_PATH . '/config/permisos.php'; }

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

    public static function viewData(): array
    {
        $matrix = RoleRepository::matrix();
        return [
            'title' => 'Roles y permisos', 'roles' => RoleRepository::all(),
            'matrix' => $matrix, 'cases' => require BASE_PATH . '/config/casos.php',
            'policy' => self::policy(), 'version' => self::version($matrix),
        ];
    }

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
