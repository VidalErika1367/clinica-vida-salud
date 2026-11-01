<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AvailabilityRepository;

final class CounterService
{
    public static function calculate(array $row, string $now): array
    {
        $server=(new \DateTimeImmutable($now))->getTimestamp()*1000;
        $start=(new \DateTimeImmutable($row['inicio']))->getTimestamp()*1000;
        $end=(new \DateTimeImmutable($row['fin']))->getTimestamp()*1000;
        $active=in_array($row['estado'],['programada','confirmada'],true);
        return ['inicio'=>$start,'fin'=>$end,'servidor'=>$server,'estado'=>$row['estado'],'activo'=>$active,
            'fase'=>!$active ? 'oculto' : ($server<$start ? 'espera' : ($server<$end ? 'turno' : 'finalizado'))];
    }
    public static function forAppointment(int $id, int $user): array
    {
        $actor=AppointmentService::actor($user,'CU29');
        return self::calculate(AppointmentService::find($id,$actor),AvailabilityRepository::clock()['ahora']);
    }
}
