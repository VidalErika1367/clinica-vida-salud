<?php
declare(strict_types=1);
namespace App;

final class ScheduleConflictException extends \RuntimeException
{
    public function __construct(public readonly array $appointments)
    {
        parent::__construct('El cambio deja citas vigentes fuera del horario. Gestiona las citas señaladas antes de volver a guardar.');
    }
}
