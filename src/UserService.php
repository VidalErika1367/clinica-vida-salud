<?php
declare(strict_types=1);
namespace App;
use PDOException;
use App\Repositories\AuditRepository;
use App\Repositories\PatientRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;

/**
 * Cuentas de usuario: alta y edición por administración (CU04) y registro público de pacientes (CU01).
 * Cada cambio se registra en auditoría con el responsable.
 */
final class UserService
{
    /**
     * Roles disponibles para asignar a una cuenta.
     *
     * @return array roles
     */
    public static function roles(): array { return RoleRepository::all(); }
    /**
     * Busca usuarios por nombre de usuario o correo y, opcionalmente, por rol (CU04).
     *
     * @param string $query texto de búsqueda
     * @param string|null $roleCode código de rol ya validado con roleFilter(); null para todos los roles
     * @return array usuarios
     */
    public static function search(string $query, ?string $roleCode = null): array { return UserRepository::search($query, $roleCode); }
    /**
     * Valida el filtro de rol del listado: solo se acepta el código de un rol existente; cualquier otro valor
     * (vacío, ajeno, con comillas, un arreglo) se ignora y se muestran todos los roles.
     *
     * @param mixed $value valor recibido en el parámetro GET "rol"
     * @param array $roles roles de la tabla rol (cada uno con la clave "codigo")
     * @return string|null código del rol elegido, o null para "Todos"
     */
    public static function roleFilter(mixed $value, array $roles): ?string
    {
        if (!is_string($value) || $value === '') { return null; }
        foreach ($roles as $role) { if (hash_equals((string) $role['codigo'], $value)) { return (string) $role['codigo']; } }
        return null;
    }
    /**
     * Totales de usuarios para el panel del administrador.
     *
     * @return array conteos
     */
    public static function statistics(): array { return UserRepository::counts(); }
    /**
     * Comprueba que el registro público esté habilitado en la matriz de permisos (CU01).
     *
     * @return void
     * @throws HttpException 403 si el permiso CU01 no está asignado al rol paciente
     */
    public static function requireRegistrationAllowed(): void
    {
        if (!RoleRepository::allows('paciente', 'CU01')) {
            throw new HttpException(403, 'El registro de nuevas cuentas está deshabilitado. Consulta en recepción.');
        }
    }
    /**
     * Busca un usuario por id.
     *
     * @param int $id usuario
     * @return array datos de la cuenta
     * @throws HttpException 404 si no existe
     */
    public static function find(int $id): array
    {
        $row = UserRepository::find($id);
        if (!$row) { throw new HttpException(404, 'No se encontró el usuario.'); }
        return $row;
    }
    /**
     * Crea o modifica una cuenta. Para el registro público, el rol siempre es paciente.
     * Porqué: el último administrador habilitado no puede deshabilitarse ni perder su rol; la comprobación
     * se hace con un bloqueo global de cuentas para evitar que dos cambios simultáneos dejen el sistema sin administrador.
     *
     * @param array $data datos de la cuenta
     * @param int|null $id usuario a modificar; null para alta
     * @param bool $public true si la cuenta la crea el propio paciente
     * @return int identificador de la cuenta
     * @throws ValidationException si los datos no son válidos o el usuario o correo ya existe
     * @throws HttpException 403 si el registro público está deshabilitado
     */
    public static function save(array $data, ?int $id = null, bool $public = false): int
    {
        $editing = $id !== null;
        $data = Validation::normalize($data);
        if ($public) {
            self::requireRegistrationAllowed();
            $role = RoleRepository::byCode('paciente');
            $data['habilitado'] = true;
        } else {
            $role = RoleRepository::find((int) ($data['rol_id'] ?? 0));
        }
        $errors = Validation::account($data, $id === null);
        if (!$role) { $errors['rol_id'] = 'Selecciona un rol válido.'; }
        if ($role && $role['codigo'] === 'paciente') { $errors += Validation::patient($data); }
        if ($errors) { throw new ValidationException($errors); }
        $passwordHash = ($data['password'] ?? '') !== '' ? password_hash($data['password'], PASSWORD_DEFAULT) : null;
        try {
            return Database::transaction(function () use ($data, $id, $role, $passwordHash, $editing): int {
                // Serializa las altas y cambios administrativos para proteger al último administrador.
                UserRepository::lockAccounts();
                if ($id !== null) {
                    $old = self::find($id);
                    $oldRole = RoleRepository::find((int) $old['rol_id'])['codigo'];
                    if ($oldRole === 'administrador' && $old['habilitado'] && (!$data['habilitado'] || $role['codigo'] !== 'administrador')) {
                        $count = UserRepository::enabledAdministratorCount();
                        if ($count <= 1) { throw new ValidationException(['general' => 'Debe conservarse al menos un administrador habilitado.']); }
                    }
                    UserRepository::update($id, $data, (int) $role['id']);
                    if ($passwordHash !== null) { UserRepository::updatePassword($id, $passwordHash); }
                } else {
                    $id = UserRepository::create($data, (int) $role['id'], $passwordHash);
                }
                if ($role['codigo'] === 'paciente') {
                    PatientRepository::saveAccountIdentity($id, $data);
                }
                AuditRepository::record((int) ($_SESSION['uid'] ?? $id),
                    $editing ? 'actualizar_usuario' : 'crear_usuario', 'Cuenta de usuario ' . $id);
                return $id;
            });
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new ValidationException(['general' => 'El usuario, correo o CI ya está registrado. Si tienes datos previos en la clínica, verifica tu cuenta en recepción.']);
            }
            throw $e;
        }
    }

    /**
     * Política de restablecimiento: el administrador restablece cualquier cuenta; recepción, solo cuentas de pacientes.
     *
     * @param string $actorRole código del rol de quien restablece
     * @param string $targetRole código del rol de la cuenta
     * @return bool true si puede restablecerla
     */
    public static function canReset(string $actorRole, string $targetRole): bool
    {
        return $actorRole === 'administrador' || ($actorRole === 'recepcionista' && $targetRole === 'paciente');
    }
    /**
     * Valida los datos del restablecimiento: contraseña temporal que cumpla la política, su confirmación y,
     * cuando se pide, la casilla de verificación de identidad.
     *
     * @param array $data password, password_confirmation y verificado ("1" si se marcó la casilla)
     * @param bool $requireVerification true si la casilla de verificación de identidad es obligatoria
     * @return array<string,string> errores por campo
     */
    public static function resetErrors(array $data, bool $requireVerification): array
    {
        $errors = [];
        if ($requireVerification && ($data['verificado'] ?? '') !== '1') {
            $errors['verificado'] = 'Confirma que verificaste la identidad del paciente (CI y datos de contacto).';
        }
        $password = (string) ($data['password'] ?? '');
        $problems = Validation::passwordProblems($password);
        if ($problems) { $errors['password'] = $problems[0]; }
        if ($password !== (string) ($data['password_confirmation'] ?? '')) { $errors['password_confirmation'] = 'Las contraseñas no coinciden.'; }
        return $errors;
    }
    /**
     * Restablece la contraseña de una cuenta con una contraseña temporal (amplía CU04 y CU06).
     * Guarda solo el hash, marca que debe cambiarla, invalida las sesiones abiertas de esa cuenta (cambia el hash)
     * y registra en auditoría al responsable, sin la contraseña.
     *
     * @param int $targetId cuenta a restablecer
     * @param array $data password, password_confirmation y verificado
     * @param array $actor usuario autenticado que restablece (id y rol)
     * @param bool $requireVerification true si debe marcarse la verificación de identidad
     * @return void
     * @throws HttpException 403 si el rol del responsable no puede restablecer esa cuenta; 404 si no existe
     * @throws ValidationException si la temporal no cumple la política, no coincide o falta la verificación
     */
    public static function restablecer(int $targetId, array $data, array $actor, bool $requireVerification): void
    {
        $target = self::find($targetId);
        $targetRole = (string) RoleRepository::find((int) $target['rol_id'])['codigo'];
        // Se comprueba antes que los datos: recepción nunca restablece cuentas de personal.
        if (!self::canReset((string) $actor['rol'], $targetRole)) {
            throw new HttpException(403, 'Tu cuenta no puede restablecer la contraseña de esta cuenta.');
        }
        $errors = self::resetErrors($data, $requireVerification);
        if ($errors) { throw new ValidationException($errors); }
        $hash = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        Database::transaction(function () use ($targetId, $hash, $actor, $target, $targetRole): void {
            UserRepository::lockAccounts();
            UserRepository::resetPassword($targetId, $hash);
            $patientId = (int) Database::run('SELECT id FROM paciente WHERE usuario_id=?', [$targetId])->fetchColumn();
            AuditRepository::record((int) $actor['id'], 'restablecer_password',
                'Restablece la contraseña de la cuenta ' . $target['nombre_usuario'] . ' (' . $targetRole . '); debe cambiarla al ingresar.',
                $patientId ?: null);
        });
    }
    /**
     * Valida el cambio de la contraseña temporal: debe cumplir la política, coincidir con su confirmación
     * y no ser igual a la temporal vigente.
     *
     * @param string $new contraseña nueva
     * @param string $confirmation repetición de la contraseña nueva
     * @param string $currentHash hash de la contraseña temporal vigente
     * @return array<string,string> errores por campo
     */
    public static function changeErrors(string $new, string $confirmation, string $currentHash): array
    {
        $errors = [];
        $problems = Validation::passwordProblems($new);
        if ($problems) { $errors['password'] = $problems[0]; }
        elseif ($currentHash !== '' && password_verify($new, $currentHash)) {
            $errors['password'] = 'La contraseña nueva no puede ser igual a la temporal. Elige otra distinta.';
        }
        if ($new !== $confirmation) { $errors['password_confirmation'] = 'Las contraseñas no coinciden.'; }
        return $errors;
    }
    /**
     * Cambia la contraseña temporal por la que elige la persona y quita la obligación de cambiarla.
     *
     * @param array $user usuario autenticado
     * @param array $data password y password_confirmation
     * @return string hash de la contraseña nueva (para renovar la sesión)
     * @throws ValidationException si no cumple la política, no coincide o repite la temporal
     */
    public static function changeTemporaryPassword(array $user, array $data): string
    {
        $new = (string) ($data['password'] ?? '');
        $errors = self::changeErrors($new, (string) ($data['password_confirmation'] ?? ''), (string) UserRepository::passwordHash((int) $user['id']));
        if ($errors) { throw new ValidationException($errors); }
        $hash = password_hash($new, PASSWORD_DEFAULT);
        Database::transaction(function () use ($user, $hash): void {
            UserRepository::completePasswordChange((int) $user['id'], $hash);
            AuditRepository::record((int) $user['id'], 'cambiar_password_temporal', 'Cambia su contraseña temporal por una elegida por la persona.');
        });
        return $hash;
    }
}
