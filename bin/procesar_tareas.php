<?php
declare(strict_types=1);
/*
 * Proceso de tareas (CU24, CU27, CU28): inactividad diaria de pacientes, recordatorios de cita,
 * recepción de mensajes de Telegram (polling) y envío de avisos pendientes.
 *
 * Uso: php bin/procesar_tareas.php            ejecuta ciclos continuos (cada 10 segundos)
 *      php bin/procesar_tareas.php --una-vez  ejecuta un solo ciclo y termina (para un programador de tareas)
 * Solo debe existir un proceso activo en modo polling; un candado de PostgreSQL impide dos a la vez.
 * Solo se ejecuta desde la línea de comandos.
 */
require dirname(__DIR__).'/bootstrap.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$once=in_array('--una-vez',$argv,true);
$client=new App\TelegramClient();
do {
    $locked=false;
    try {
        $locked=(bool) App\Database::run('SELECT pg_try_advisory_lock(736428102)')->fetchColumn();
        if (!$locked && $once) { fwrite(STDERR,"Ya hay un proceso de tareas activo.\n"); exit(1); }
        if ($locked) {
            App\TaskService::maintain();
            if (App\TelegramClient::configured()) {
                if (App\Config::get('TELEGRAM_MODO','polling')==='polling') { App\TaskService::poll($client,$once ? 0 : 20); }
                $totals=App\TaskService::deliver($client);
                if ($once) { echo json_encode($totals,JSON_THROW_ON_ERROR)."\n"; }
            } elseif ($once) { echo "Mantenimiento aplicado. Telegram todavía no está configurado.\n"; }
        }
    } catch (Throwable $e) {
        // Nunca imprimir tokens, mensajes, consultas ni URL privadas.
        fwrite(STDERR,"No se completó el ciclo de tareas. Revisa la conexión y la configuración.\n");
        App\Database::reset(); $locked=false;
        if ($once) { exit(1); }
    } finally {
        if ($locked) {
            try { App\Database::run('SELECT pg_advisory_unlock(736428102)'); }
            catch (Throwable) { App\Database::reset(); }
        }
    }
    if (!$once) { sleep(10); }
} while (!$once);
