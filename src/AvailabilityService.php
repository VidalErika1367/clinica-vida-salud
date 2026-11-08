<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AvailabilityRepository;
use App\Repositories\SpecialtyRepository;

/**
 * Cálculo de turnos libres por médico y fecha (CU11) y validación de turnos antes de reservar (CU12, CU14, CU22, CU26).
 * Los turnos se generan a partir de los horarios activos menos las citas vigentes.
 */
final class AvailabilityService
{
    /**
     * Convierte un identificador recibido en texto a entero positivo.
     *
     * @param mixed $value valor del formulario o de la URL
     * @return int identificador, o 0 si no es válido
     */
    public static function id(mixed $value): int
    {
        return is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/',$value) ? (int) $value : 0;
    }
    /**
     * Comprueba que la fecha exista realmente (rechaza, por ejemplo, 30 de febrero) y tenga año positivo.
     *
     * @param string $date fecha en formato Y-m-d
     * @return bool
     */
    public static function dateValid(string $date): bool
    {
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        $errors=\DateTimeImmutable::getLastErrors();
        return $parsed && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))
            && $parsed->format('Y-m-d')===$date && (int) substr($date,0,4)>0;
    }
    /**
     * Interpreta el inicio de un turno en la zona horaria de la clínica. Solo acepta horas en punto o a la media hora.
     *
     * @param string $value fecha y hora en formato Y-m-d\TH:i
     * @return \DateTimeImmutable instante del turno
     * @throws ValidationException si el formato, la fecha o la alineación no son válidos
     */
    public static function start(string $value): \DateTimeImmutable
    {
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T(?:[01][0-9]|2[0-3]):(?:00|30)$/',$value)
            || !self::dateValid(substr($value,0,10))) {
            throw new ValidationException(['inicio'=>'Selecciona una fecha y una hora válidas, alineadas a media hora.']);
        }
        return \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new \DateTimeZone('America/La_Paz'));
    }
    /**
     * Turnos libres de un médico para una fecha. No se permiten fechas pasadas.
     *
     * @param int $specialty especialidad
     * @param int $doctor médico
     * @param string $date fecha Y-m-d
     * @param int|null $ignore cita que se excluye del cálculo (al reprogramar, su propio turno cuenta como libre)
     * @return array turnos disponibles
     * @throws ValidationException si la fecha no es válida o el médico no atiende esa especialidad
     */
    public static function slots(int $specialty, int $doctor, string $date, ?int $ignore=null): array
    {
        $errors=[];
        $clock=AvailabilityRepository::clock();
        if (!self::dateValid($date) || $date<$clock['hoy']) { $errors['fecha']='Selecciona una fecha válida que no sea pasada.'; }
        if (!AvailabilityRepository::eligible($specialty,$doctor)) { $errors['medico_id']='Selecciona un médico activo de esa especialidad.'; }
        if ($errors) { throw new ValidationException($errors); }
        return AvailabilityRepository::slots($specialty,$doctor,$date,$ignore);
    }
    /**
     * Verifica que el turno solicitado siga libre justo antes de guardarlo.
     * Porqué: el formulario pudo mostrar un turno que otra operación ya tomó; esta comprobación se repite en la transacción.
     *
     * @param int $specialty especialidad
     * @param int $doctor médico
     * @param string $start inicio solicitado (Y-m-d\TH:i)
     * @param int|null $ignore cita que se excluye (reprogramación)
     * @return \DateTimeImmutable turno confirmado
     * @throws ValidationException si el turno ya no está libre
     */
    public static function assertFree(int $specialty, int $doctor, string $start, ?int $ignore=null): \DateTimeImmutable
    {
        $parsed=self::start($start);
        foreach (self::slots($specialty,$doctor,$parsed->format('Y-m-d'),$ignore) as $slot) {
            if ((new \DateTimeImmutable($slot['inicio']))->getTimestamp()===$parsed->getTimestamp()) { return $parsed; }
        }
        throw new ValidationException(['inicio'=>'El turno ya no está disponible. Vuelve a consultar los horarios libres.']);
    }
    /**
     * Prepara el formulario de disponibilidad: especialidades activas, médicos y turnos según los filtros.
     * Los errores se devuelven en el arreglo en lugar de lanzar excepción, para mostrarlos junto al formulario.
     *
     * @param array $input filtros: especialidad, médico y fecha
     * @param int|null $ignore cita excluida del cálculo
     * @return array datos para la vista, incluidos los errores
     */
    public static function form(array $input, ?int $ignore=null): array
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
        try { $data['slots']=self::slots($specialty,$doctor,$date,$ignore); }
        catch (ValidationException $e) { $data['errors']=$e->errors; }
        return $data;
    }
}
