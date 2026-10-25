<?php
declare(strict_types=1);
namespace App;
use PDOException;
use App\Repositories\AuditRepository;
use App\Repositories\PatientRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;

final class UserService
{
    public static function roles(): array { return RoleRepository::all(); }
    public static function search(string $query): array { return UserRepository::search($query); }
    public static function statistics(): array { return UserRepository::counts(); }
    public static function requireRegistrationAllowed(): void
    {
        if (!RoleRepository::allows('paciente', 'CU01')) {
            throw new HttpException(403, 'El registro de nuevas cuentas está deshabilitado. Consulta en recepción.');
        }
    }
    public static function find(int $id): array
    {
        $row = UserRepository::find($id);
        if (!$row) { throw new HttpException(404, 'No se encontró el usuario.'); }
        return $row;
    }
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
}
