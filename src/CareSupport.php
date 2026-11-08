<?php
declare(strict_types=1);
namespace App;
use App\Repositories\PatientRepository;
use PDOException;

/**
 * Utilidades compartidas por los módulos de atención: validación de texto, ids, versiones y transacciones.
 * Lo usan ausencias (CU26), multas (CU23-CU24), avisos (CU28), pacientes (CU06, CU25), Telegram (CU27) y urgencias (CU21-CU22).
 */
final class CareSupport
{
    /**
     * Valida un campo de texto libre: sin etiquetas ni caracteres de control.
     *
     * @param array $input datos de la petición
     * @param string $key nombre del campo
     * @param int $max longitud máxima en caracteres
     * @param bool $required si es true, el texto vacío no se acepta
     * @return string texto sin espacios sobrantes
     * @throws ValidationException si el campo no cumple las reglas
     */
    public static function text(array $input, string $key, int $max=250, bool $required=true): string
    {
        $value=$input[$key] ?? '';
        if (!is_string($value) || ($required && trim($value)==='') || mb_strlen($value)>$max
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F<>]/u',$value)) {
            throw new ValidationException([$key=>'Completa este dato con texto válido de hasta '.$max.' caracteres.']);
        }
        return trim($value);
    }
    /**
     * Lee un identificador opcional de un campo de selección.
     *
     * @param array $input datos de la petición
     * @param string $key nombre del campo
     * @return int|null id válido, o null si el campo llegó vacío
     * @throws ValidationException si el valor no es un id válido
     */
    public static function optionalId(array $input, string $key): ?int
    {
        $value=$input[$key] ?? '';
        if ($value==='') { return null; }
        $id=AvailabilityService::id($value);
        if (!$id) { throw new ValidationException([$key=>'Selecciona un registro válido.']); }
        return $id;
    }
    /**
     * Control de concurrencia optimista: impide guardar sobre datos que otra persona cambió.
     *
     * @param string $version huella actual del registro
     * @param mixed $posted huella que envió el formulario
     * @return void
     * @throws ValidationException si las huellas no coinciden
     */
    public static function checkVersion(string $version, mixed $posted): void
    {
        if (!is_string($posted) || !hash_equals($version,$posted)) {
            throw new ValidationException(['version'=>'El registro cambió. Revisa los datos actuales antes de guardar.']);
        }
    }
    /**
     * Calcula la huella de un registro a partir de los campos indicados (control de versión).
     *
     * @param array $row registro completo
     * @param string[] $fields campos que forman parte de la huella
     * @return string hash SHA-256 en hexadecimal
     */
    public static function version(array $row, array $fields): string
    {
        return hash('sha256',json_encode(array_intersect_key($row,array_flip($fields)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
    /**
     * Exige que el usuario haya marcado una casilla de confirmación explícita.
     *
     * @param array $input datos de la petición
     * @param string $field nombre de la casilla
     * @param string $message mensaje si no está marcada
     * @return void
     * @throws ValidationException si la casilla no vale "1"
     */
    public static function confirm(array $input, string $field, string $message): void
    {
        if (($input[$field] ?? null)!=='1') { throw new ValidationException([$field=>$message]); }
    }
    /**
     * Ejecuta una transacción y traduce los conflictos de PostgreSQL a mensajes para el usuario.
     * Porqué: 23505 (duplicado), 23P01 (solape de horario), 40001/40P01 (concurrencia) y P0001 (regla de la base)
     * indican que otra operación cambió los datos; se pide al usuario revisar antes de reintentar.
     *
     * @param callable $operation trabajo transaccional
     * @return mixed resultado de la operación
     * @throws ValidationException si hay conflicto de datos o concurrencia
     * @throws PDOException otros errores de base de datos
     */
    public static function transaction(callable $operation): mixed
    {
        try { return Database::transaction($operation); }
        catch (PDOException $e) {
            if ($e->getCode()==='23505') { throw new ValidationException(['general'=>'El dato ya está registrado. Revisa el comprobante o el estado actual antes de repetir la operación.']); }
            if (in_array($e->getCode(),['23P01','40001','40P01','P0001'],true)) {
                throw new ValidationException(['general'=>'Las condiciones cambiaron. Consulta nuevamente el registro y la disponibilidad antes de continuar.']);
            }
            throw $e;
        }
    }
    /**
     * Resuelve el paciente sobre el que actúa la pantalla. Un paciente solo puede ver su propia ficha.
     * Porqué: la restricción se aplica en el servidor, no en el formulario, para que no se pueda saltar.
     *
     * @param array $input datos de la petición (puede traer "paciente_id" y "q")
     * @param array $actor usuario autenticado con su rol
     * @return array{patient:array|null,query:string,patients:array} ficha seleccionada, texto de búsqueda y lista de pacientes
     * @throws HttpException 403 si un paciente pide otra ficha; 404 si el paciente no existe
     */
    public static function patientContext(array $input, array $actor): array
    {
        $id=self::optionalId($input,'paciente_id');
        if ($actor['rol']==='paciente') {
            $own=(int) ($actor['paciente_id'] ?? 0);
            if (!$own || ($id!==null && $id!==$own)) { throw new HttpException(403,'Solo puedes consultar tu propio paciente.'); }
            $id=$own;
        }
        $patient=$id ? PatientRepository::find($id) : null;
        if ($patient===false) { throw new HttpException(404,'No se encontró el paciente.'); }
        $query=self::text($input,'q',100,false);
        return ['patient'=>$patient,'query'=>$query,
            'patients'=>$actor['rol']==='paciente' ? [] : PatientRepository::search($query,'todos')];
    }
}
