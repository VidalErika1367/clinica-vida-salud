<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AppointmentReadRepository;
use App\Repositories\AvailabilityRepository;
use App\Repositories\PatientRepository;
use App\Repositories\SpecialtyRepository;

/**
 * Filtros, paginación y consultas de lectura de citas: agenda (CU18), historial (CU19) y reportes (CU20).
 * Todas las consultas de lectura se hacen en una transacción de solo lectura y aislamiento REPEATABLE READ.
 */
final class AppointmentReadService
{
    /** Estados de cita disponibles como filtro. */
    public const STATES=['todos'=>'Todos','programada'=>'Programada','confirmada'=>'Confirmada',
        'atendida'=>'Atendida','cancelada'=>'Cancelada','inasistencia'=>'Inasistencia'];

    /**
     * Valida el número de página del listado.
     *
     * @param mixed $value página recibida (vacía significa la primera)
     * @return int página válida, mínimo 1
     * @throws ValidationException si no es un entero positivo razonable
     */
    public static function page(mixed $value): int
    {
        if ($value===null || $value==='') { return 1; }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,5}$/D',$value)) {
            throw new ValidationException(['pagina'=>'Selecciona una página válida.']);
        }
        return (int) $value;
    }
    /**
     * Lee un filtro de texto; rechaza valores que no sean cadenas.
     *
     * @param array $input datos de la petición
     * @param string $key filtro
     * @param string $default valor si el campo no viene
     * @return string texto recortado
     * @throws ValidationException si el valor no es texto
     */
    private static function text(array $input, string $key, string $default=''): string
    {
        if (!array_key_exists($key,$input)) { return $default; }
        if (!is_string($input[$key])) { throw new ValidationException([$key=>'Revisa el filtro seleccionado.']); }
        return trim($input[$key]);
    }
    /**
     * Valida una fecha de filtro en formato Y-m-d.
     *
     * @param string $value fecha
     * @param string $field campo para el mensaje de error
     * @return string la misma fecha
     * @throws ValidationException si la fecha no existe o es anterior a 0001-01-01
     */
    public static function date(string $value, string $field): string
    {
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('America/La_Paz'));
        if (!$date || $date->format('Y-m-d')!==$value || $value<'0001-01-01') {
            throw new ValidationException([$field=>'Escribe una fecha válida.']);
        }
        return $value;
    }
    /**
     * Lee un identificador opcional de un filtro.
     *
     * @param array $input datos de la petición
     * @param string $key filtro
     * @return int|null id, o null si el filtro está vacío
     * @throws ValidationException si el valor no es un id válido
     */
    private static function id(array $input, string $key): ?int
    {
        $value=self::text($input,$key);
        if ($value==='') { return null; }
        if (!preg_match('/^[1-9][0-9]{0,14}$/D',$value)) {
            throw new ValidationException([$key=>'Selecciona un registro válido.']);
        }
        return (int) $value;
    }
    /**
     * Arma el conjunto de filtros comunes: rango de fechas (por defecto hoy), estado, médico y especialidad.
     *
     * @param array $input datos de la petición
     * @param bool $oneDay true para consultar un solo día (agenda)
     * @return array filtros normalizados
     * @throws ValidationException si una fecha, el rango o un filtro no son válidos
     */
    private static function filters(array $input, bool $oneDay=false): array
    {
        $today=AvailabilityRepository::clock()['hoy'];
        $from=self::date(self::text($input,$oneDay ? 'fecha' : 'desde',$today),$oneDay ? 'fecha' : 'desde');
        $to=$oneDay ? $from : self::date(self::text($input,'hasta',$today),'hasta');
        if ($to<$from) { throw new ValidationException(['hasta'=>'La fecha final no puede ser anterior a la inicial.']); }
        $state=self::text($input,'estado','todos');
        if (!isset(self::STATES[$state])) { throw new ValidationException(['estado'=>'Selecciona un estado válido.']); }
        return ['desde'=>$from,'hasta'=>$to,'estado'=>$state,'medico_id'=>self::id($input,'medico_id'),
            'especialidad_id'=>self::id($input,'especialidad_id')];
    }
    /**
     * Ejecuta una lectura en transacción de solo lectura con REPEATABLE READ.
     * Porqué: resumen, listado y totales reflejan el mismo instante aunque haya cambios concurrentes.
     *
     * @param callable $operation consulta a ejecutar
     * @return array resultado
     */
    private static function snapshot(callable $operation): array
    {
        return Database::transaction(function () use ($operation): array {
            Database::connection()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            return $operation();
        });
    }
    /**
     * Agenda de un día (CU18). El médico solo ve su propia agenda, incluso si su cuenta aún no está vinculada.
     *
     * @param array $input filtros de fecha, médico y página
     * @param int $userId usuario que consulta
     * @return array datos para la vista
     * @throws HttpException 403 si un médico pide la agenda de otro
     * @throws ValidationException si los filtros no son válidos
     */
    public static function agenda(array $input, int $userId): array
    {
        $actor=AppointmentService::actor($userId,'CU18');
        return self::snapshot(function () use ($input,$actor): array {
            $filters=self::filters($input,true);
            $doctors=AppointmentReadRepository::doctors($actor['rol']==='medico' ? (int) $actor['id'] : null);
            if ($actor['rol']==='medico') {
                $own=$doctors ? (int) $doctors[0]['id'] : null;
                if ($filters['medico_id']!==null && $filters['medico_id']!==$own) {
                    throw new HttpException(403,'Solo puedes consultar tu propia agenda médica.');
                }
                $filters['medico_id']=$own;
                // También restringe una cuenta todavía sin médico vinculado: nunca ofrece la agenda global.
                $filters['medico_usuario_id']=(int) $actor['id'];
            } elseif ($filters['medico_id']!==null && !in_array($filters['medico_id'],array_map('intval',array_column($doctors,'id')),true)) {
                throw new ValidationException(['medico_id'=>'No se encontró el médico seleccionado.']);
            }
            $filters['especialidad_id']=null;
            return ['filters'=>$filters,'doctors'=>$doctors,'absenceItems'=>AppointmentReadRepository::absences($filters),
                'listing'=>AppointmentReadRepository::appointments($filters,self::page($input['pagina'] ?? null))];
        });
    }
    /**
     * Historial de citas de un paciente (CU19). Un paciente solo consulta el suyo.
     *
     * @param array $input filtros de paciente, fechas, estado y texto
     * @param int $userId usuario que consulta
     * @return array datos para la vista
     * @throws HttpException 403 si un paciente pide el historial de otro
     * @throws ValidationException si el paciente o los filtros no son válidos
     */
    public static function history(array $input, int $userId): array
    {
        $actor=AppointmentService::actor($userId,'CU19');
        return self::snapshot(function () use ($input,$actor): array {
            $filters=self::filters($input);
            $requested=self::id($input,'paciente_id');
            if ($actor['rol']==='paciente') {
                if (!$actor['paciente_id'] || ($requested!==null && $requested!==(int) $actor['paciente_id'])) {
                    throw new HttpException(403,'Solo puedes consultar tu propio historial de citas.');
                }
                $filters['paciente_id']=(int) $actor['paciente_id'];
                $filters['propietario_id']=(int) $actor['id'];
            } else { $filters['paciente_id']=$requested; }
            $filters['medico_id']=null; $filters['especialidad_id']=null;
            $patient=$filters['paciente_id']===null ? null : PatientRepository::find($filters['paciente_id']);
            if ($patient===false) { throw new ValidationException(['paciente_id'=>'No se encontró el paciente seleccionado.']); }
            if ($patient && !array_key_exists('desde',$input)) {
                $filters['desde']=AppointmentReadRepository::firstDate((int) $patient['id']);
                if ($filters['desde']>$filters['hasta']) { $filters['desde']=$filters['hasta']; }
            }
            $query=mb_substr(self::text($input,'q'),0,100);
            $listing=$patient ? AppointmentReadRepository::appointments($filters,self::page($input['pagina'] ?? null))
                : ['items'=>[],'total'=>0,'page'=>1,'pages'=>1];
            return ['filters'=>$filters,'patient'=>$patient,'query'=>$query,'listing'=>$listing,
                'patients'=>$actor['rol']==='paciente' ? [] : PatientRepository::search($query,'todos')];
        });
    }
    /**
     * Reportes administrativos (CU20): citas, pagos o pacientes registrados, con filtros validados contra catálogos.
     * Porqué: médico y especialidad se comprueban contra los catálogos reales antes de consultar, para no filtrar por ids inexistentes.
     *
     * @param array $input tipo de reporte y filtros
     * @param int $userId usuario que consulta
     * @return array datos para la vista
     * @throws HttpException 403 sin permiso CU20
     * @throws ValidationException si el tipo, los filtros o el catálogo no son válidos
     */
    public static function report(array $input, int $userId): array
    {
        AppointmentService::actor($userId,'CU20');
        return self::snapshot(function () use ($input): array {
            $type=self::text($input,'tipo','citas');
            if (!in_array($type,['citas','pagos','pacientes'],true)) { throw new ValidationException(['tipo'=>'Selecciona un reporte válido.']); }
            $filterInput=$input;
            if ($type==='pacientes') {
                unset($filterInput['medico_id'],$filterInput['especialidad_id'],$filterInput['estado']);
            }
            $filters=self::filters($filterInput);
            $filters['estado_paciente']=$type==='pacientes' ? self::text($input,'estado_paciente','todos') : 'todos';
            if ($type==='pacientes' && !in_array($filters['estado_paciente'],['todos','activos','inactivos'],true)) {
                throw new ValidationException(['estado_paciente'=>'Selecciona un estado de paciente válido.']);
            }
            $doctors=AppointmentReadRepository::doctors();
            $specialties=SpecialtyRepository::search('','todos');
            foreach (['medico_id'=>$doctors,'especialidad_id'=>$specialties] as $key=>$catalog) {
                if ($filters[$key]!==null && !in_array($filters[$key],array_map('intval',array_column($catalog,'id')),true)) {
                    throw new ValidationException([$key=>'No se encontró el registro seleccionado.']);
                }
            }
            if ($type==='pagos') { $filters['estado']='todos'; }
            $page=self::page($input['pagina'] ?? null);
            return ['filters'=>$filters,'type'=>$type,'doctors'=>$doctors,'specialties'=>$specialties,
                'summary'=>$type==='citas' ? AppointmentReadRepository::activity($filters) : null,
                'listing'=>match ($type) {
                    'citas'=>AppointmentReadRepository::appointments($filters,$page),
                    'pagos'=>AppointmentReadRepository::payments($filters,$page),
                    'pacientes'=>AppointmentReadRepository::registeredPatients($filters,$page),
                },'generatedAt'=>AvailabilityRepository::clock()['ahora']];
        });
    }
}
