<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AuditRepository;
use App\Repositories\DoctorRepository;
use App\Repositories\RoleRepository;
use App\Repositories\SpecialtyRepository;
use App\Repositories\UserRepository;
use PDOException;

/**
 * Catálogo de especialidades (CU09): nombre normalizado, unicidad y control de versión.
 */
final class SpecialtyService
{
    /**
     * Normaliza un nombre: recorta espacios y colapsa los espacios internos.
     *
     * @param string $name nombre escrito
     * @return string nombre normalizado
     */
    public static function name(string $name): string { return preg_replace('/\s+/u', ' ', trim($name)); }
    /**
     * Huella de la especialidad para control de versión.
     *
     * @param array $row especialidad
     * @return string SHA-256 en hexadecimal
     */
    public static function version(array $row): string
    {
        return hash('sha256', json_encode([$row['id'],$row['nombre'],$row['descripcion'],$row['activo']], JSON_THROW_ON_ERROR));
    }
    /**
     * Busca una especialidad por id.
     *
     * @param int $id especialidad
     * @param bool $lock si es true, bloquea la fila
     * @return array datos de la especialidad
     * @throws HttpException 404 si no existe
     */
    public static function find(int $id, bool $lock = false): array
    {
        $row = SpecialtyRepository::find($id,$lock);
        if (!$row) { throw new HttpException(404, 'No se encontró la especialidad.'); }
        return $row;
    }
    /**
     * Toma los campos de la especialidad del formulario.
     *
     * @param array $input datos recibidos
     * @return array nombre, descripción y estado
     */
    public static function data(array $input): array
    {
        return ['nombre'=>self::name(is_string($input['nombre'] ?? null) ? $input['nombre'] : ''),
            'descripcion'=>is_string($input['descripcion'] ?? null) ? trim($input['descripcion']) : '',
            'activo'=>($input['activo'] ?? '') === '1'];
    }
    /**
     * Crea o modifica una especialidad (CU09). Si nada cambió, no escribe ni audita.
     * Porqué: al editar, los médicos de la especialidad se bloquean en orden para no chocar con reservas en curso.
     *
     * @param array $input datos del formulario y versión
     * @param int $actor usuario administrador
     * @param int|null $id especialidad; null para alta
     * @return int identificador de la especialidad
     * @throws ValidationException si los datos no son válidos, el nombre está repetido o la versión cambió
     * @throws HttpException 403 sin permiso CU09
     */
    public static function save(array $input, int $actor, ?int $id = null): int
    {
        $data = self::data($input);
        $errors = Validation::specialty($data);
        if (isset($input['activo']) && !in_array($input['activo'], ['0','1'], true)) { $errors['general']='Revisa el estado de la especialidad.'; }
        foreach (['nombre','descripcion'] as $key) {
            if (isset($input[$key]) && (!is_string($input[$key]) || !mb_check_encoding($input[$key],'UTF-8'))) { $errors[$key]='Revisa el dato ingresado.'; }
        }
        if ($errors) { throw new ValidationException($errors); }
        try {
            return Database::transaction(function () use ($input,$data,$actor,$id): int {
                UserRepository::lockAccounts();
                $user = UserRepository::sessionAccount($actor);
                if (!$user || $user['rol'] !== 'administrador' || !in_array('CU09',RoleRepository::forUser($actor),true)) {
                    throw new HttpException(403,'Tu cuenta no tiene permiso para gestionar especialidades.');
                }
                if ($id !== null) {
                    $doctorIds=array_map('intval',array_column(SpecialtyRepository::doctors($id),'id'));
                    sort($doctorIds);
                    foreach ($doctorIds as $doctorId) { DoctorRepository::lockForBooking($doctorId); }
                }
                $old = $id === null ? null : self::find($id,true);
                if ($old && (!is_string($input['version'] ?? null) || !hash_equals(self::version($old),$input['version']))) {
                    throw new ValidationException(['version'=>'La especialidad cambió. Revisa los datos actuales antes de guardar.']);
                }
                $changed = !$old || $old['nombre'] !== $data['nombre']
                    || (string) $old['descripcion'] !== $data['descripcion'] || $old['activo'] !== $data['activo'];
                if (!$changed) { return $id; }
                $saved = SpecialtyRepository::save($data,$id);
                AuditRepository::record($actor, $id === null ? 'crear_especialidad' : 'actualizar_especialidad',
                    'Especialidad ' . $saved . '; estado ' . ($data['activo'] ? 'activo' : 'inactivo'));
                return $saved;
            });
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') { throw new ValidationException(['nombre'=>'Ya existe una especialidad con un nombre equivalente.']); }
            throw $e;
        }
    }
}
