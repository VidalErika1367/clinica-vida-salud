<?php
declare(strict_types=1);
namespace App;
use App\Repositories\UserRepository;

/**
 * Tareas periódicas del proceso bin/procesar_tareas.php: inactividad diaria de pacientes,
 * recordatorios de cita (24 h y 1 h), sondeo de Telegram y envío de avisos pendientes.
 */
final class TaskService
{
    /**
     * Ejecuta la inactividad una vez por día y genera los recordatorios pendientes.
     * Porqué: un candado de transacción evita que dos procesos desactiven pacientes o dupliquen avisos a la vez.
     *
     * @return void
     */
    public static function maintain(): void
    {
        Database::transaction(function (): void {
            UserRepository::lockAccounts();
            Database::run('SELECT pg_advisory_xact_lock(736428101)');
            $day=(int) Database::run("SELECT to_char(now() AT TIME ZONE 'America/La_Paz','YYYYMMDD')::bigint")->fetchColumn();
            $last=Database::run("SELECT valor FROM integracion_estado WHERE clave='inactividad_dia'")->fetchColumn();
            if ((int) $last!==$day) {
                Database::run('CALL sp_desactivar_inactivos()');
                Database::run("INSERT INTO integracion_estado(clave,valor) VALUES('inactividad_dia',?) ON CONFLICT(clave) DO UPDATE SET valor=EXCLUDED.valor",[$day]);
            }
            Database::run("INSERT INTO aviso(paciente_id,cita_id,clave_evento,tipo,texto)
                SELECT c.paciente_id,c.id,'recordatorio:' || c.id || ':' || extract(epoch FROM c.inicio)::bigint || ':' || c.medico_id || ':' || r.clase,
                  'recordatorio','Tiene una cita próxima. Consulte el horario vigente y los detalles en la web.'
                FROM cita c CROSS JOIN (VALUES('24h'),('1h')) r(clase)
                WHERE c.estado IN ('programada','confirmada') AND c.inicio>now() AND c.inicio<=now()+interval '24 hours'
                  AND ((r.clase='24h' AND c.inicio>now()+interval '1 hour') OR (r.clase='1h' AND c.inicio<=now()+interval '1 hour'))
                ON CONFLICT(clave_evento) DO NOTHING");
            // Un recordatorio viejo no se envía tras cancelar, finalizar o cambiar el turno.
            Database::run("UPDATE aviso a SET estado='omitido',ultimo_error='El horario o estado de la cita cambió.'
                FROM cita c WHERE c.id=a.cita_id AND a.tipo='recordatorio' AND a.estado IN ('pendiente','error')
                AND (c.estado NOT IN ('programada','confirmada') OR c.inicio<=now()
                  OR a.clave_evento NOT LIKE 'recordatorio:' || c.id || ':' || extract(epoch FROM c.inicio)::bigint || ':' || c.medico_id || ':%')");
        });
    }
    /**
     * Recibe las actualizaciones pendientes del bot (modo polling) y avanza el desplazamiento guardado.
     * Porqué: el desplazamiento se guarda con máximo, así una actualización repetida nunca retrocede el puntero.
     *
     * @param TelegramClient $client cliente del bot
     * @param int $timeout segundos de espera de Telegram
     * @return void
     * @throws \RuntimeException si la respuesta de Telegram no tiene el formato esperado
     */
    public static function poll(TelegramClient $client, int $timeout=20): void
    {
        $key='telegram_offset:'.substr(hash('sha256',Config::get('TELEGRAM_BOT_TOKEN')),0,32);
        $offset=(int) Database::run('SELECT valor FROM integracion_estado WHERE clave=?',[$key])->fetchColumn();
        $updates=$client->call('getUpdates',['offset'=>$offset,'timeout'=>$timeout,'limit'=>20,'allowed_updates'=>['message']]);
        if (!is_array($updates)) { throw new \RuntimeException('Telegram devolvió una lista inválida.'); }
        foreach ($updates as $update) {
            if (!is_array($update) || !is_int($update['update_id'] ?? null)) { throw new \RuntimeException('Actualización inválida de Telegram.'); }
            TelegramService::update($update);
            Database::run('INSERT INTO integracion_estado(clave,valor) VALUES(?,?) ON CONFLICT(clave) DO UPDATE SET valor=greatest(integracion_estado.valor,EXCLUDED.valor)',[$key,$update['update_id']+1]);
        }
    }
    /**
     * Envía hasta $limit avisos pendientes, uno por transacción (CU28).
     * Porqué: cada aviso se marca en su propia transacción con bloqueo SKIP LOCKED; si Telegram no confirma, queda en error
     * y recepción decide el reintento. No se reenvía automáticamente para evitar mensajes duplicados.
     *
     * @param TelegramClient $client cliente del bot
     * @param int $limit número máximo de avisos a intentar (tope 50)
     * @return array{enviados:int,errores:int} totales de la ejecución
     */
    public static function deliver(TelegramClient $client, int $limit=20): array
    {
        $totals=['enviados'=>0,'errores'=>0];
        for ($i=0;$i<min(50,max(0,$limit));$i++) {
            $state=Database::transaction(function () use ($client): ?string {
                $row=Database::run("SELECT a.*,v.chat_id FROM aviso a JOIN vinculo_telegram v ON v.paciente_id=a.paciente_id AND v.activo
                    JOIN paciente p ON p.id=a.paciente_id JOIN usuario u ON u.id=p.usuario_id AND u.habilitado
                    JOIN rol r ON r.id=u.rol_id AND r.codigo='paciente'
                    WHERE a.estado='pendiente' AND a.intentos<10 ORDER BY a.id LIMIT 1 FOR UPDATE OF a,v SKIP LOCKED")->fetch();
                if (!$row) { return null; }
                $subject=NoticeService::SUBJECTS[$row['tipo']] ?? 'Aviso del sistema';
                $text="Clínica Vida Salud · ".$subject."\nAviso registrado: ".clinic_datetime($row['creado_en']);
                if ($row['cita_id']) { $text.="\nCita ".AppointmentService::code((int) $row['cita_id']); }
                $url=rtrim(Config::get('APP_URL'),'/');
                $text.="\nConsulta los detalles vigentes en la web.";
                if (preg_match('#^https://[A-Za-z0-9.-]+(?::[0-9]+)?$#D',$url)) { $text.="\n".$url.'/avisos'; }
                $state='enviado'; $error=null;
                try {
                    $result=$client->call('sendMessage',['chat_id'=>(int) $row['chat_id'],'text'=>$text]);
                    if (!is_array($result) || !is_int($result['message_id'] ?? null)) { throw new \RuntimeException('Confirmación inválida.'); }
                } catch (\RuntimeException) {
                    $state='error'; $error='Telegram no confirmó la entrega. Recepción puede revisar y autorizar un reintento.';
                }
                Database::run("UPDATE aviso SET estado=?,intentos=intentos+1,ultimo_error=?,ultimo_intento_en=now(),
                    enviado_en=CASE WHEN ?='enviado' THEN now() ELSE NULL END WHERE id=?",[$state,$error,$state,(int) $row['id']]);
                return $state;
            });
            if ($state===null) { break; }
            $totals[$state==='enviado' ? 'enviados' : 'errores']++;
        }
        return $totals;
    }
}
