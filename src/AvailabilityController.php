<?php
declare(strict_types=1);
namespace App;

/**
 * Consulta de disponibilidad de turnos por especialidad, médico y fecha (CU11).
 * Accesible a los roles que pueden reservar citas.
 */
final class AvailabilityController
{
    /**
     * Muestra los turnos libres según los filtros recibidos (CU11).
     *
     * @return void
     * @throws HttpException 403 si el rol no tiene el permiso CU11
     */
    public static function index(): void
    {
        Auth::requireRolePermission('CU11',AppointmentService::ROLES);
        $data=AvailabilityService::form($_GET);
        View::render('availability',['title'=>'Consultar disponibilidad','section'=>'disponibilidad',...$data],$data['errors'] ? 422 : 200);
    }
}
