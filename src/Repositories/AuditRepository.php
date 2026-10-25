<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Database;

final class AuditRepository
{
    public static function record(int $actorId, string $action, string $reason, ?int $patientId = null): void
    {
        Database::run('INSERT INTO evento_administrativo(actor_id,accion,motivo,paciente_id) VALUES(?,?,?,?)',
            [$actorId, $action, $reason, $patientId]);
    }
}
