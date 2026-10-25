<?php
declare(strict_types=1);
namespace App;

final class AvailabilityController
{
    public static function index(): void
    {
        Auth::requireRolePermission('CU11',AppointmentService::ROLES);
        $data=AvailabilityService::form($_GET);
        View::render('availability',['title'=>'Consultar disponibilidad','section'=>'disponibilidad',...$data],$data['errors'] ? 422 : 200);
    }
}
