<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AuditRepository;
use App\Repositories\FineRepository;
use App\Repositories\PatientRepository;
use App\Repositories\UrgentRepository;
use App\Repositories\UserRepository;

final class FineService
{
    public const STATES=['todas'=>'Todas','advertencias'=>'Advertencias','pendientes'=>'Multas pendientes','pagadas'=>'Multas pagadas'];
    public static function code(int $id): string { return 'MU-'.str_pad((string) $id,6,'0',STR_PAD_LEFT); }
    public static function version(array $row): string
    {
        return CareSupport::version($row,['id','paciente_id','monto','estado','incumplimiento_id']);
    }
    public static function amount(array $input): string
    {
        $value=CareSupport::text($input,'monto',12);
        if (!preg_match('/^([0-9]{1,8})(?:[.,]([0-9]{1,2}))?$/D',$value,$m)) {
            throw new ValidationException(['monto'=>'Escribe el importe exacto, con hasta dos decimales y sin separador de miles.']);
        }
        $normalized=(ltrim($m[1],'0') ?: '0').'.'.str_pad($m[2] ?? '',2,'0');
        if ($normalized==='0.00') { throw new ValidationException(['monto'=>'El importe debe ser mayor a cero.']); }
        return $normalized;
    }
    public static function overview(array $input, int $userId, bool $payments=false): array
    {
        $actor=AppointmentService::actor($userId,$payments ? 'CU23' : 'CU24');
        return Database::transaction(function () use ($input,$actor): array {
            Database::connection()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $data=CareSupport::patientContext($input,$actor);
            $state=CareSupport::text($input,'estado',20,false) ?: 'todas';
            if (!isset(self::STATES[$state])) { throw new ValidationException(['estado'=>'Selecciona un estado válido.']); }
            $page=AppointmentReadService::page($input['pagina'] ?? null);
            return $data+['state'=>$state,'summary'=>$data['patient'] ? FineRepository::summary((int) $data['patient']['id']) : null,
                'listing'=>$data['patient'] ? FineRepository::listing((int) $data['patient']['id'],$state,$page)
                    : ['items'=>[],'total'=>0,'page'=>1,'pages'=>1]];
        });
    }
    public static function find(int $id): array
    {
        $row=FineRepository::find($id);
        if (!$row) { throw new HttpException(404,'No se encontró la multa.'); }
        return $row;
    }
    public static function pay(int $id, array $input, int $userId): void
    {
        $amount=self::amount($input); $receipt=CareSupport::text($input,'comprobante',80);
        CareSupport::confirm($input,'pago_recibido','Confirma que el pago presencial ya fue recibido y verificado.');
        CareSupport::transaction(function () use ($id,$input,$userId,$amount,$receipt): void {
            UserRepository::lockAccounts(); AppointmentService::actor($userId,'CU23');
            $row=self::find($id);
            PatientRepository::find((int) $row['paciente_id'],true);
            $row=FineRepository::find($id,true);
            CareSupport::checkVersion(self::version($row),$input['version'] ?? null);
            if ($row['estado']!=='pendiente') { throw new ValidationException(['general'=>'La multa ya fue pagada. Consulta el pago registrado.']); }
            if ($amount!==$row['monto']) { throw new ValidationException(['monto'=>'El importe debe coincidir exactamente con Bs '.$row['monto'].'.']); }
            FineRepository::pay($id,$userId,$amount,$receipt);
            AuditRepository::record($userId,'pagar_multa',self::code($id).': pago presencial registrado.',(int) $row['paciente_id']);
            UrgentRepository::notify((int) $row['paciente_id'],(int) $row['cita_id'],'multa:'.$id.':pagada','pago_multa',
                'El pago de su multa '.self::code($id).' quedó registrado. Revise las restricciones pendientes en la web.');
        });
    }
}
