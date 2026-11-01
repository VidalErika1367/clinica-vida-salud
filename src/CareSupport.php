<?php
declare(strict_types=1);
namespace App;
use App\Repositories\PatientRepository;
use PDOException;

final class CareSupport
{
    public static function text(array $input, string $key, int $max=250, bool $required=true): string
    {
        $value=$input[$key] ?? '';
        if (!is_string($value) || ($required && trim($value)==='') || mb_strlen($value)>$max
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F<>]/u',$value)) {
            throw new ValidationException([$key=>'Completa este dato con texto válido de hasta '.$max.' caracteres.']);
        }
        return trim($value);
    }
    public static function optionalId(array $input, string $key): ?int
    {
        $value=$input[$key] ?? '';
        if ($value==='') { return null; }
        $id=AvailabilityService::id($value);
        if (!$id) { throw new ValidationException([$key=>'Selecciona un registro válido.']); }
        return $id;
    }
    public static function checkVersion(string $version, mixed $posted): void
    {
        if (!is_string($posted) || !hash_equals($version,$posted)) {
            throw new ValidationException(['version'=>'El registro cambió. Revisa los datos actuales antes de guardar.']);
        }
    }
    public static function version(array $row, array $fields): string
    {
        return hash('sha256',json_encode(array_intersect_key($row,array_flip($fields)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
    public static function confirm(array $input, string $field, string $message): void
    {
        if (($input[$field] ?? null)!=='1') { throw new ValidationException([$field=>$message]); }
    }
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
