<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AuditRepository;
use App\Repositories\DoctorRepository;
use App\Repositories\RoleRepository;
use App\Repositories\ScheduleRepository;
use App\Repositories\UserRepository;
use PDOException;

final class ScheduleService
{
    public const DAYS=[1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'];
    public static function find(int $id, bool $lock=false): array
    {
        $row=ScheduleRepository::find($id,$lock);
        if (!$row) { throw new HttpException(404,'No se encontró el horario.'); }
        return $row;
    }
    public static function data(array $input): array
    {
        $data=[];
        foreach (['medico_id','dia_semana','hora_inicio','hora_fin'] as $key) {
            $data[$key]=is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        }
        $data['activo']=($input['activo'] ?? '')==='1';
        return $data;
    }
    public static function formValues(array $row): array
    {
        return ['medico_id'=>(string) $row['medico_id'],'dia_semana'=>(string) $row['dia_semana'],
            'hora_inicio'=>substr($row['hora_inicio'],0,5),'hora_fin'=>substr($row['hora_fin'],0,5),'activo'=>$row['activo']];
    }
    public static function version(array $row): string
    {
        return hash('sha256',json_encode([$row['id'],self::formValues($row),$row['medico_activo']],JSON_THROW_ON_ERROR));
    }
    public static function save(array $input, int $actor, ?int $id=null): int
    {
        $data=self::data($input); $errors=Validation::schedule($data);
        if (isset($input['activo']) && !in_array($input['activo'],['0','1'],true)) { $errors['general']='Revisa el estado del horario.'; }
        foreach (['medico_id','dia_semana','hora_inicio','hora_fin'] as $key) {
            if (isset($input[$key]) && !is_string($input[$key])) { $errors[$key]='Revisa el dato ingresado.'; }
        }
        if ($errors) { throw new ValidationException($errors); }
        try {
            return Database::transaction(function () use ($input,$data,$actor,$id): int {
                UserRepository::lockAccounts();
                $user=UserRepository::sessionAccount($actor);
                if (!$user || $user['rol']!=='administrador' || !in_array('CU10',RoleRepository::forUser($actor),true)) {
                    throw new HttpException(403,'Tu cuenta no tiene permiso para gestionar horarios.');
                }
                $old=$id===null ? null : self::find($id);
                $doctors=[(int) $data['medico_id']];
                if ($old) { $doctors[]=(int) $old['medico_id']; }
                $doctors=array_values(array_unique($doctors)); sort($doctors);
                foreach ($doctors as $doctorId) { DoctorRepository::lockForBooking($doctorId); }
                if ($id!==null) { $old=self::find($id,true); }
                if ($old && (!is_string($input['version'] ?? null) || !hash_equals(self::version($old),$input['version']))) {
                    throw new ValidationException(['version'=>'El horario o el estado del médico cambió. Revisa los datos actuales antes de guardar.']);
                }
                $doctor=DoctorRepository::find((int) $data['medico_id']);
                if (!$doctor || !$doctor['activo']) { throw new ValidationException(['medico_id'=>'Selecciona un médico activo.']); }
                if ($old && self::formValues($old)===$data) { return $id; }
                $before=array_column(ScheduleRepository::uncovered($doctors),'id');
                $saved=ScheduleRepository::save($data,$id);
                $affected=array_values(array_filter(ScheduleRepository::uncovered($doctors),
                    static fn($c)=>!in_array($c['id'],$before,true)));
                if ($affected) { throw new ScheduleConflictException($affected); }
                AuditRepository::record($actor,$id===null ? 'crear_horario' : 'actualizar_horario',
                    'Horario ' . $saved . '; médico ' . DoctorService::code((int) $data['medico_id'])
                    . '; ' . self::DAYS[(int) $data['dia_semana']] . ' ' . $data['hora_inicio'] . '-' . $data['hora_fin']
                    . '; ' . ($data['activo'] ? 'activo' : 'inactivo'));
                return $saved;
            });
        } catch (PDOException $e) {
            if ($e->getCode()==='23P01') {
                throw new ValidationException(['general'=>'El tramo se superpone con otro horario activo del mismo médico y día.']);
            }
            throw $e;
        }
    }
}
