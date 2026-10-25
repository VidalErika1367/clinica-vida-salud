<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AvailabilityRepository;
use App\Repositories\SpecialtyRepository;

final class AvailabilityService
{
    public static function id(mixed $value): int
    {
        return is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/',$value) ? (int) $value : 0;
    }
    public static function dateValid(string $date): bool
    {
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        $errors=\DateTimeImmutable::getLastErrors();
        return $parsed && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))
            && $parsed->format('Y-m-d')===$date && (int) substr($date,0,4)>0;
    }
    public static function start(string $value): \DateTimeImmutable
    {
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T(?:[01][0-9]|2[0-3]):(?:00|30)$/',$value)
            || !self::dateValid(substr($value,0,10))) {
            throw new ValidationException(['inicio'=>'Selecciona una fecha y una hora válidas, alineadas a media hora.']);
        }
        return \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new \DateTimeZone('America/La_Paz'));
    }
    public static function slots(int $specialty, int $doctor, string $date, ?int $ignore=null): array
    {
        $errors=[];
        $clock=AvailabilityRepository::clock();
        if (!self::dateValid($date) || $date<$clock['hoy']) { $errors['fecha']='Selecciona una fecha válida que no sea pasada.'; }
        if (!AvailabilityRepository::eligible($specialty,$doctor)) { $errors['medico_id']='Selecciona un médico activo de esa especialidad.'; }
        if ($errors) { throw new ValidationException($errors); }
        return AvailabilityRepository::slots($specialty,$doctor,$date,$ignore);
    }
    public static function assertFree(int $specialty, int $doctor, string $start, ?int $ignore=null): \DateTimeImmutable
    {
        $parsed=self::start($start);
        foreach (self::slots($specialty,$doctor,$parsed->format('Y-m-d'),$ignore) as $slot) {
            if ((new \DateTimeImmutable($slot['inicio']))->getTimestamp()===$parsed->getTimestamp()) { return $parsed; }
        }
        throw new ValidationException(['inicio'=>'El turno ya no está disponible. Vuelve a consultar los horarios libres.']);
    }
    public static function form(array $input): array
    {
        $clock=AvailabilityRepository::clock();
        $specialty=self::id($input['especialidad_id'] ?? null);
        $doctor=self::id($input['medico_id'] ?? null);
        $date=is_string($input['fecha'] ?? null) ? trim($input['fecha']) : $clock['hoy'];
        $data=['specialties'=>SpecialtyRepository::active(),'doctors'=>[],'slots'=>[],
            'specialtyId'=>$specialty,'doctorId'=>$doctor,'date'=>$date,'today'=>$clock['hoy'],'errors'=>[]];
        if ($specialty===0) {
            if (isset($input['especialidad_id']) && $input['especialidad_id']!=='') { $data['errors']['especialidad_id']='Selecciona una especialidad válida.'; }
            return $data;
        }
        $found=SpecialtyRepository::find($specialty);
        if (!$found || !$found['activo']) { $data['errors']['especialidad_id']='Selecciona una especialidad activa.'; return $data; }
        $data['doctors']=AvailabilityRepository::doctors($specialty);
        if ($doctor===0) {
            if (isset($input['medico_id']) && $input['medico_id']!=='') { $data['errors']['medico_id']='Selecciona un médico válido.'; }
            return $data;
        }
        try { $data['slots']=self::slots($specialty,$doctor,$date); }
        catch (ValidationException $e) { $data['errors']=$e->errors; }
        return $data;
    }
}
