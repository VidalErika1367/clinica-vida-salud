<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AuditRepository;
use App\Repositories\DoctorRepository;
use App\Repositories\RoleRepository;
use App\Repositories\SpecialtyRepository;
use App\Repositories\UserRepository;
use PDOException;

final class DoctorService
{
    public const FIELDS = ['registro_profesional','nombres','apellidos','telefono','correo'];
    public static function code(int $id): string { return 'ME-' . str_pad((string) $id,6,'0',STR_PAD_LEFT); }
    public static function find(int $id, bool $lock = false): array
    {
        $row = DoctorRepository::find($id,$lock);
        if (!$row) { throw new HttpException(404,'No se encontró el médico.'); }
        $row['especialidades'] = DoctorRepository::specialties($id);
        return $row;
    }
    public static function version(array $row): string
    {
        $data = ['id'=>$row['id'],'usuario_id'=>$row['usuario_id'],'activo'=>$row['activo']];
        foreach (self::FIELDS as $key) { $data[$key]=$row[$key]; }
        $data['especialidades'] = array_map(static fn($e)=>[$e['id'],$e['activo'],$e['asignada']],$row['especialidades']);
        return hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    public static function formValues(array $row): array
    {
        $data=$row;
        $data['especialidades']=array_map(static fn($e)=>(string) $e['id'],
            array_values(array_filter($row['especialidades'],static fn($e)=>$e['asignada'] && $e['activo'])));
        return $data;
    }
    public static function data(array $input): array
    {
        $data=[];
        foreach (self::FIELDS as $key) { $data[$key]=is_string($input[$key] ?? null) ? trim($input[$key]) : ''; }
        $data['registro_profesional']=mb_strtoupper(SpecialtyService::name($data['registro_profesional']));
        $data['correo']=mb_strtolower($data['correo']);
        $data['activo']=($input['activo'] ?? '') === '1';
        $data['usuario_id']=is_string($input['usuario_id'] ?? null) ? $input['usuario_id'] : '';
        $data['especialidades']=is_array($input['especialidades'] ?? null)
            ? array_values(array_filter($input['especialidades'],static fn($x)=>is_string($x) && preg_match('/^[1-9][0-9]{0,17}$/',$x))) : [];
        return $data;
    }
    public static function save(array $input, int $actor, ?int $id = null): int
    {
        $data=self::data($input); $errors=Validation::doctor($data);
        foreach (self::FIELDS as $key) {
            if (isset($input[$key]) && (!is_string($input[$key]) || !mb_check_encoding($input[$key],'UTF-8'))) { $errors[$key]='Revisa el dato ingresado.'; }
        }
        if (isset($input['activo']) && !in_array($input['activo'],['0','1'],true)) { $errors['general']='Revisa el estado del médico.'; }
        $selection=$input['especialidades'] ?? [];
        if (!is_array($selection) || count($selection)>500
            || array_filter($selection,static fn($x)=>!is_string($x) || !preg_match('/^[1-9][0-9]{0,17}$/',$x))) {
            $errors['especialidades']='Selecciona especialidades válidas.';
        }
        $account=$input['usuario_id'] ?? '';
        if (!is_string($account) || ($account !== '' && !preg_match('/^[1-9][0-9]{0,17}$/',$account))) {
            $errors['usuario_id']='Selecciona una cuenta válida.';
        }
        if (($id === null || $data['activo']) && !$data['especialidades']) {
            $errors['especialidades']='Selecciona al menos una especialidad activa.';
        }
        if ($errors) { throw new ValidationException($errors); }
        $data['usuario_id']=$account === '' ? null : (int) $account;
        $data['especialidades']=array_values(array_unique(array_map('intval',$selection)));
        sort($data['especialidades']);
        try {
            return Database::transaction(function () use ($input,$data,$actor,$id): int {
                // Mismo orden que Usuarios: evita vincular cuentas mientras cambia su rol.
                UserRepository::lockAccounts();
                $user=UserRepository::sessionAccount($actor);
                if (!$user || $user['rol'] !== 'administrador' || !in_array('CU08',RoleRepository::forUser($actor),true)) {
                    throw new HttpException(403,'Tu cuenta no tiene permiso para gestionar médicos.');
                }
                if ($id !== null) { DoctorRepository::lockForBooking($id); }
                $old=$id === null ? null : self::find($id,true);
                if ($old && (!is_string($input['version'] ?? null) || !hash_equals(self::version($old),$input['version']))) {
                    throw new ValidationException(['version'=>'El médico o sus especialidades cambiaron. Revisa los datos actuales antes de guardar.']);
                }
                if (DoctorRepository::equivalentRegistration($data['registro_profesional'],$id)) {
                    throw new ValidationException(['registro_profesional'=>'El registro profesional ya está asignado a otro médico.']);
                }
                foreach ($data['especialidades'] as $specialtyId) {
                    $specialty=SpecialtyRepository::find($specialtyId,true);
                    if (!$specialty || !$specialty['activo']) { throw new ValidationException(['especialidades'=>'Una especialidad seleccionada no está activa o no existe.']); }
                }
                // Una especialidad globalmente inactiva no se ofrece en el selector.
                // Editar el contacto no debe retirar por ello una asociación que seguía vigente.
                if ($old) {
                    foreach ($old['especialidades'] as $specialty) {
                        if ($specialty['asignada'] && !$specialty['activo']) { $data['especialidades'][]=(int) $specialty['id']; }
                    }
                    sort($data['especialidades']);
                }
                if ($data['usuario_id'] !== null) {
                    $account=DoctorRepository::account($data['usuario_id']);
                    $unchanged=$old && (int) $old['usuario_id'] === $data['usuario_id'];
                    if (!$account || (!$unchanged && ($account['codigo'] !== 'medico' || !$account['habilitado']))
                        || ($account['medico_id'] && (int) $account['medico_id'] !== $id)) {
                        throw new ValidationException(['usuario_id'=>'La cuenta debe ser de Médico, estar habilitada y no pertenecer a otro profesional.']);
                    }
                }
                $oldSelection=$old ? array_map(static fn($e)=>(int) $e['id'],
                    array_values(array_filter($old['especialidades'],static fn($e)=>$e['asignada']))) : [];
                sort($oldSelection);
                $changed=!$old || (int) ($old['usuario_id'] ?? 0) !== (int) ($data['usuario_id'] ?? 0)
                    || $old['activo'] !== $data['activo'] || $oldSelection !== $data['especialidades'];
                foreach (self::FIELDS as $key) { if ($old && (string) $old[$key] !== $data[$key]) { $changed=true; } }
                if (!$changed) { return $id; }
                $saved=DoctorRepository::save($data,$id);
                DoctorRepository::assign($saved,$data['especialidades']);
                AuditRepository::record($actor,$id === null ? 'crear_medico' : 'actualizar_medico',
                    'Médico ' . self::code($saved) . '; estado ' . ($data['activo'] ? 'activo' : 'inactivo')
                    . '; especialidades asignadas: ' . count($data['especialidades']));
                return $saved;
            });
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') { throw new ValidationException(['general'=>'El registro profesional o la cuenta ya pertenece a otro médico.']); }
            throw $e;
        }
    }
}
