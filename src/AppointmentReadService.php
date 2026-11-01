<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AppointmentReadRepository;
use App\Repositories\AvailabilityRepository;
use App\Repositories\PatientRepository;
use App\Repositories\SpecialtyRepository;

final class AppointmentReadService
{
    public const STATES=['todos'=>'Todos','programada'=>'Programada','confirmada'=>'Confirmada',
        'atendida'=>'Atendida','cancelada'=>'Cancelada','inasistencia'=>'Inasistencia'];

    public static function page(mixed $value): int
    {
        if ($value===null || $value==='') { return 1; }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,5}$/D',$value)) {
            throw new ValidationException(['pagina'=>'Selecciona una página válida.']);
        }
        return (int) $value;
    }
    private static function text(array $input, string $key, string $default=''): string
    {
        if (!array_key_exists($key,$input)) { return $default; }
        if (!is_string($input[$key])) { throw new ValidationException([$key=>'Revisa el filtro seleccionado.']); }
        return trim($input[$key]);
    }
    public static function date(string $value, string $field): string
    {
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('America/La_Paz'));
        if (!$date || $date->format('Y-m-d')!==$value || $value<'0001-01-01') {
            throw new ValidationException([$field=>'Escribe una fecha válida.']);
        }
        return $value;
    }
    private static function id(array $input, string $key): ?int
    {
        $value=self::text($input,$key);
        if ($value==='') { return null; }
        if (!preg_match('/^[1-9][0-9]{0,14}$/D',$value)) {
            throw new ValidationException([$key=>'Selecciona un registro válido.']);
        }
        return (int) $value;
    }
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
    private static function snapshot(callable $operation): array
    {
        return Database::transaction(function () use ($operation): array {
            Database::connection()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            return $operation();
        });
    }
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
    public static function report(array $input, int $userId): array
    {
        AppointmentService::actor($userId,'CU20');
        return self::snapshot(function () use ($input): array {
            $filters=self::filters($input);
            $type=self::text($input,'tipo','citas');
            if (!in_array($type,['citas','pagos'],true)) { throw new ValidationException(['tipo'=>'Selecciona un reporte válido.']); }
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
                'listing'=>$type==='citas' ? AppointmentReadRepository::appointments($filters,$page)
                    : AppointmentReadRepository::payments($filters,$page),'generatedAt'=>AvailabilityRepository::clock()['ahora']];
        });
    }
}
