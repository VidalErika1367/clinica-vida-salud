<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AuditRepository;
use App\Repositories\FineRepository;
use App\Repositories\PatientRepository;
use App\Repositories\UrgentRepository;
use App\Repositories\UserRepository;

/**
 * Advertencias y multas: consulta del estado de cuenta (CU24) y registro de pagos presenciales (CU23).
 * El importe debe coincidir exactamente con la multa; no se admiten pagos parciales.
 */
final class FineService
{
    /** Filtros de estado disponibles en el listado. */
    public const STATES=['todas'=>'Todas','advertencias'=>'Advertencias','pendientes'=>'Multas pendientes','pagadas'=>'Multas pagadas'];
    /**
     * Código visible de una multa, por ejemplo "MU-000003".
     *
     * @param int $id identificador interno
     * @return string código con seis dígitos
     */
    public static function code(int $id): string { return 'MU-'.str_pad((string) $id,6,'0',STR_PAD_LEFT); }
    /**
     * Huella de la multa para control de versión.
     *
     * @param array $row multa
     * @return string SHA-256 en hexadecimal
     */
    public static function version(array $row): string
    {
        return CareSupport::version($row,['id','paciente_id','monto','estado','incumplimiento_id']);
    }
    /**
     * Valida el importe escrito: hasta 8 enteros y 2 decimales, sin separador de miles. Devuelve formato "0.00".
     * Porqué: se compara como texto normalizado para evitar errores de redondeo de punto flotante.
     *
     * @param array $input datos del formulario (campo "monto")
     * @return string importe normalizado con dos decimales
     * @throws ValidationException si el formato es inválido o el importe es cero
     */
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
    /**
     * Arma el resumen y el listado de multas del paciente seleccionado (CU24 o CU23).
     * Porqué: la lectura va en una transacción de solo lectura con isolation REPEATABLE READ,
     * así el resumen y el listado muestran el mismo estado aunque haya pagos simultáneos.
     *
     * @param array $input filtros: paciente, estado y página
     * @param int $userId usuario que consulta
     * @param bool $payments true para la pantalla de pagos (CU23), false para multas (CU24)
     * @return array datos para la vista
     * @throws HttpException 403 sin permiso
     * @throws ValidationException si el estado o la página no son válidos
     */
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
    /**
     * Busca una multa por id.
     *
     * @param int $id multa
     * @return array datos de la multa
     * @throws HttpException 404 si no existe
     */
    public static function find(int $id): array
    {
        $row=FineRepository::find($id);
        if (!$row) { throw new HttpException(404,'No se encontró la multa.'); }
        return $row;
    }
    /**
     * Registra el pago presencial de una multa pendiente (CU23) y notifica al paciente.
     * Porqué: la multa, el paciente y la auditoría se actualizan en una sola transacción con la multa bloqueada,
     * así no puede pagarse dos veces ni quedar un pago sin registro de auditoría.
     *
     * @param int $id multa
     * @param array $input importe, comprobante, confirmación de recepción y versión
     * @param int $userId usuario que registra el pago
     * @return void
     * @throws ValidationException si el importe no coincide, la multa ya fue pagada o la versión cambió
     * @throws HttpException 403 sin permiso CU23; 404 si la multa no existe
     */
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
