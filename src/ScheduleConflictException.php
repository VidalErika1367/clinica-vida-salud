<?php
declare(strict_types=1);
namespace App;

/**
 * Se lanza cuando un cambio de horario dejaría citas vigentes fuera de la nueva disponibilidad (CU10).
 * La ficha de horarios muestra las citas afectadas para que recepción las gestione antes de guardar.
 */
final class ScheduleConflictException extends \RuntimeException
{
    /**
     * @param array $appointments citas que quedarían fuera del horario
     */
    public function __construct(public readonly array $appointments)
    {
        parent::__construct('El cambio deja citas vigentes fuera del horario. Gestiona las citas señaladas antes de volver a guardar.');
    }
}
