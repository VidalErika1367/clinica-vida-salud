<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Database;

/**
 * Escritura en la bitácora administrativa (evento_administrativo). Las operaciones de gestión
 * registran aquí su responsable y la acción realizada.
 */
final class AuditRepository
{
    /**
     * Registra un evento administrativo.
     *
     * @param int $actorId usuario responsable
     * @param string $action código de la acción (por ejemplo "actualizar_permiso")
     * @param string $reason descripción breve, sin datos clínicos
     * @param int|null $patientId paciente relacionado, si aplica
     * @return void
     */
    public static function record(int $actorId, string $action, string $reason, ?int $patientId = null): void
    {
        Database::run('INSERT INTO evento_administrativo(actor_id,accion,motivo,paciente_id) VALUES(?,?,?,?)',
            [$actorId, $action, $reason, $patientId]);
    }
}
