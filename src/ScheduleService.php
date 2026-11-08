<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AuditRepository;
use App\Repositories\DoctorRepository;
use App\Repositories\RoleRepository;
use App\Repositories\ScheduleRepository;
use App\Repositories\UserRepository;
use PDOException;

/**
 * Horarios semanales de atención (CU10). Un cambio no puede dejar citas vigentes sin horario:
 * en ese caso se rechaza y se devuelven las citas afectadas.
 */
final class ScheduleService
{
    /** Nombre de cada día de la semana (1 = lunes). */
    public const DAYS=[1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'];
    /**
     * Busca un horario por id.
     *
     * @param int $id horario
     * @param bool $lock si es true, bloquea la fila
     * @return array datos del horario
     * @throws HttpException 404 si no existe
     */
    public static function find(int $id, bool $lock=false): array
    {
        $row=ScheduleRepository::find($id,$lock);
        if (!$row) { throw new HttpException(404,'No se encontró el horario.'); }
        return $row;
    }
    /**
     * Toma los campos del horario del formulario.
     *
     * @param array $input datos recibidos
     * @return array datos del horario
     */
    public static function data(array $input): array
    {
        $data=[];
        foreach (['medico_id','dia_semana','hora_inicio','hora_fin'] as $key) {
            $data[$key]=is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        }
        $data['activo']=($input['activo'] ?? '')==='1';
        return $data;
    }
    /**
     * Convierte un horario de la base en valores de formulario (horas en HH:MM).
     *
     * @param array $row horario
     * @return array valores para el formulario
     */
    public static function formValues(array $row): array
    {
        return ['medico_id'=>(string) $row['medico_id'],'dia_semana'=>(string) $row['dia_semana'],
            'hora_inicio'=>substr($row['hora_inicio'],0,5),'hora_fin'=>substr($row['hora_fin'],0,5),'activo'=>$row['activo']];
    }
    /**
     * Huella del horario y del estado del médico para control de versión.
     *
     * @param array $row horario con el estado del médico
     * @return string SHA-256 en hexadecimal
     */
    public static function version(array $row): string
    {
        return hash('sha256',json_encode([$row['id'],self::formValues($row),$row['medico_activo']],JSON_THROW_ON_ERROR));
    }
    /**
     * Guarda el horario (CU10). Bloquea a los médicos implicados y compara las citas sin cobertura antes y después.
     * Porqué: solo se rechazan las citas que quedan fuera por este cambio; las que ya estaban sin cobertura no bloquean el guardado.
     *
     * @param array $input datos del formulario y versión
     * @param int $actor usuario administrador
     * @param int|null $id horario; null para alta
     * @return int identificador del horario
     * @throws ValidationException si los datos no son válidos, el médico no está activo o la versión cambió
     * @throws ScheduleConflictException si el cambio deja citas vigentes fuera del horario
     * @throws HttpException 403 sin permiso CU10; 404 si el horario no existe
     */
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
