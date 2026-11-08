<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AvailabilityRepository;

/**
 * Contador de la cita (CU29): fase según la hora del servidor (espera, turno o finalizado).
 * El cálculo usa la hora de la base de datos, no la del navegador.
 */
final class CounterService
{
    /**
     * Calcula la fase del contador. Solo las citas programadas o confirmadas tienen fase activa.
     *
     * @param array $row cita con inicio, fin y estado
     * @param string $now momento actual del servidor
     * @return array tiempos en milisegundos, estado, si está activa y la fase ("espera", "turno", "finalizado" u "oculto")
     */
    public static function calculate(array $row, string $now): array
    {
        $server=(new \DateTimeImmutable($now))->getTimestamp()*1000;
        $start=(new \DateTimeImmutable($row['inicio']))->getTimestamp()*1000;
        $end=(new \DateTimeImmutable($row['fin']))->getTimestamp()*1000;
        $active=in_array($row['estado'],['programada','confirmada'],true);
        return ['inicio'=>$start,'fin'=>$end,'servidor'=>$server,'estado'=>$row['estado'],'activo'=>$active,
            'fase'=>!$active ? 'oculto' : ($server<$start ? 'espera' : ($server<$end ? 'turno' : 'finalizado'))];
    }
    /**
     * Contador de una cita visible para el actor (CU29).
     *
     * @param int $id cita
     * @param int $user usuario que consulta
     * @return array resultado de calculate()
     * @throws HttpException 403 sin permiso CU29 o si la cita no es visible
     */
    public static function forAppointment(int $id, int $user): array
    {
        $actor=AppointmentService::actor($user,'CU29');
        return self::calculate(AppointmentService::find($id,$actor),AvailabilityRepository::clock()['ahora']);
    }
}
