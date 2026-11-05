<?php
declare(strict_types=1);
/**
 * Prueba CLI del mantenimiento, con fixtures exclusivos de la copia local.
 * Desde la raiz del proyecto: php ruta/comprobar_tareas.php
 * Invoca dos veces el proceso real; no necesita PHP web ni Telegram.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$taskBootstrap = getcwd().'/bootstrap.php';
if (!is_file($taskBootstrap)) {
    fwrite(STDERR, "Ejecuta desde la carpeta clinica-vida-salud del proyecto.\n"); exit(1);
}
require $taskBootstrap;

use App\Config;
use App\Database;
use App\AppointmentService;
use App\TelegramClient;
use App\Repositories\AppointmentRepository;

const TASK_TEST_DATABASE = 'clinica_vida_salud_manual_test';

function taskCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
    echo '[OK] '.$message."\n";
}

function taskCycle(): void
{
    $process = @proc_open(
        [PHP_BINARY, '-d', 'allow_url_fopen=0', BASE_PATH.'/bin/procesar_tareas.php', '--una-vez'],
        [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']],
        $pipes, BASE_PATH, null, ['bypass_shell'=>true]
    );
    if (!is_resource($process)) { throw new RuntimeException('No se pudo iniciar el proceso CLI de tareas.'); }
    fclose($pipes[0]);
    $deadline = microtime(true)+30;
    try {
        do {
            $status = proc_get_status($process);
            if (!$status['running']) { break; }
            if (microtime(true)>$deadline) {
                proc_terminate($process);
                throw new RuntimeException('El ciclo de tareas excedio el tiempo de la prueba.');
            }
            usleep(50000);
        } while (true);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        if ($status['exitcode'] !== 0 || $error !== ''
            || !str_contains($output, 'Mantenimiento aplicado.')
            || !str_contains($output, 'Telegram')) {
            throw new RuntimeException('El proceso de tareas no completo el ciclo sin Telegram.');
        }
    } finally {
        fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    }
}

function taskState(array $fixtures): array
{
    $patients = [];
    foreach ($fixtures['patients'] as $kind=>$id) {
        $patients[$kind] = (bool) Database::run('SELECT activo FROM paciente WHERE id=?', [$id])->fetchColumn();
    }
    return [
        'patients'=>$patients,
        'events'=>(int) Database::run("SELECT count(*) FROM evento_administrativo
            WHERE paciente_id=? AND accion='inactividad'", [$fixtures['patients']['inactivo']])->fetchColumn(),
        'notices'=>Database::run("SELECT cita_id,clave_evento,estado,intentos,enviado_en
            FROM aviso WHERE cita_id IN (?,?) AND tipo='recordatorio' ORDER BY cita_id,clave_evento",
            [$fixtures['appointments']['1h'], $fixtures['appointments']['24h']])->fetchAll(),
    ];
}

$taskLocked = false;
try {
    if (!in_array(Config::get('DB_HOST','127.0.0.1'), ['127.0.0.1','localhost','::1'], true)) {
        throw new RuntimeException('Esta prueba solo admite PostgreSQL en el equipo local.');
    }
    if (!function_exists('proc_open')) {
        throw new RuntimeException('PHP necesita proc_open habilitado para iniciar el proceso de tareas.');
    }
    // Los valores no vacios impiden que Config vuelva al token real del .env.
    // El proceso hijo hereda estos valores; la consola de la estudiante no cambia.
    putenv('DB_NAME='.TASK_TEST_DATABASE);
    putenv('TELEGRAM_BOT_TOKEN=PRUEBA_MANUAL_SIN_BOT');
    putenv('TELEGRAM_BOT_USERNAME=PruebaManualBot');
    putenv('PGOPTIONS=-c statement_timeout=10000 -c lock_timeout=5000');
    if (Database::run('SELECT current_database()')->fetchColumn() !== TASK_TEST_DATABASE) {
        throw new RuntimeException('La conexion no corresponde a la copia de pruebas.');
    }
    if (TelegramClient::configured()) {
        throw new RuntimeException('Telegram debe estar deshabilitado durante esta prueba.');
    }
    $taskLocked = (bool) Database::run('SELECT pg_try_advisory_lock(736428102)')->fetchColumn();
    if (!$taskLocked) { throw new RuntimeException('Deten el proceso de tareas de la copia antes de comprobarlo.'); }

    $fixtures = Database::transaction(static function (): array {
        $actor = Database::run("SELECT u.id FROM usuario u JOIN rol r ON r.id=u.rol_id
            WHERE u.nombre_usuario='recepcion' AND r.codigo='recepcionista' AND u.habilitado")->fetchColumn();
        $specialty = Database::run('SELECT id FROM especialidad WHERE activo ORDER BY id LIMIT 1')->fetchColumn();
        if (!$actor || !$specialty) {
            throw new RuntimeException('Faltan la cuenta de recepcion o una especialidad activa en la copia.');
        }
        AppointmentService::actor((int) $actor,'CU12');
        AppointmentRepository::actor((int) $actor);
        $tag = bin2hex(random_bytes(4));
        $patients = [];
        foreach (['inactivo','reciente','futuro'] as $kind) {
            $patients[$kind] = (int) Database::run("INSERT INTO paciente(ci,nombres,apellidos,telefono,creado_en)
                VALUES(?, 'Prueba Tareas', ?, '00000000', now()-interval '2 years') RETURNING id",
                ['TAREA-'.strtoupper($kind).'-'.$tag, ucfirst($kind).' '.$tag])->fetchColumn();
        }
        $doctor = (int) Database::run("INSERT INTO medico(registro_profesional,nombres,apellidos)
            VALUES(?, 'Medico', 'Prueba Tareas') RETURNING id", ['TAREA-MED-'.$tag])->fetchColumn();
        Database::run('INSERT INTO medico_especialidad(medico_id,especialidad_id) VALUES(?,?)',
            [$doctor,(int) $specialty]);
        $clock = Database::run("SELECT date_trunc('hour',now())
            + floor(extract(minute FROM now())/30)*interval '30 minutes' AS bloque")->fetchColumn();
        $starts = Database::run("SELECT ?::timestamptz-interval '2 days' AS reciente,
            ?::timestamptz+interval '60 minutes' AS proxima,
            ?::timestamptz+interval '180 minutes' AS posterior", [$clock,$clock,$clock])->fetch();
        // Solo datos de preparacion: permite los bloques ficticios historicos y
        // fuera de horario. El DDL tambien se revierte si falla la transaccion.
        // Permanecen activas las claves, CHECK, exclusiones y auditoria.
        Database::connection()->exec('ALTER TABLE cita DISABLE TRIGGER tr_validar_cita');
        $appointments = [];
        foreach (['reciente','1h','24h'] as $kind) {
            $past = $kind==='reciente';
            $start = $starts[$past ? 'reciente' : ($kind==='1h' ? 'proxima' : 'posterior')];
            $id = (int) Database::run("INSERT INTO cita(paciente_id,medico_id,especialidad_id,
                inicio,fin,estado,creado_por,creado_en,motivo)
                VALUES(?,?,?,?::timestamptz,?::timestamptz+interval '30 minutes',?,?,
                    ?::timestamptz-interval '2 days',?) RETURNING id",
                [$patients[$past ? 'reciente' : 'futuro'], $doctor, (int) $specialty,
                    $start, $start, $past ? 'atendida' : 'programada', (int) $actor, $start,
                    'PRUEBA TAREAS '.$tag.': '.$kind])->fetchColumn();
            if (!$past) { $appointments[$kind] = $id; }
        }
        Database::connection()->exec('ALTER TABLE cita ENABLE TRIGGER tr_validar_cita');
        // La comprobacion debe ejercitar hoy el procedimiento diario real.
        Database::run("DELETE FROM integracion_estado WHERE clave='inactividad_dia'");
        return ['patients'=>$patients,'appointments'=>$appointments];
    });
    Database::run('SELECT pg_advisory_unlock(736428102)'); $taskLocked=false;
    echo 'Prueba SOLO en '.TASK_TEST_DATABASE.". Telegram deshabilitado.\n";
    echo "Preparados tres pacientes ficticios y sus bloques de prueba.\n";
    $before = taskState($fixtures);
    taskCheck($before['patients']['inactivo'] && $before['events']===0 && $before['notices']===[],
        'Estado inicial: ficha antigua activa y sin recordatorios de estos turnos');

    taskCycle();
    $first = taskState($fixtures);
    taskCheck(!$first['patients']['inactivo'] && $first['events']===1,
        'Primer ciclo: un ano sin actividad genera inactivacion y un evento');
    taskCheck($first['patients']['reciente'] && $first['patients']['futuro'],
        'La atencion reciente y las citas futuras conservan activas las otras fichas');
    taskCheck(count($first['notices'])===2,
        'El proceso real genera los dos recordatorios sin una visita web');
    foreach ($fixtures['appointments'] as $kind=>$id) {
        $rows = array_values(array_filter($first['notices'], static fn(array $row): bool => (int) $row['cita_id']===$id));
        taskCheck(count($rows)===1 && str_ends_with($rows[0]['clave_evento'], ':'.$kind)
            && $rows[0]['estado']==='pendiente' && (int) $rows[0]['intentos']===0 && $rows[0]['enviado_en']===null,
            'Ventana '.$kind.': un recordatorio pendiente, sin intento de envio');
    }
    taskCycle();
    taskCheck(taskState($fixtures)===$first,
        'Segundo proceso: no duplica recordatorios ni el evento de inactividad');
    taskCheck(Database::run("SELECT tgenabled FROM pg_trigger WHERE tgrelid='cita'::regclass
        AND tgname='tr_validar_cita'")->fetchColumn()==='O',
        'La validacion de citas permanece habilitada');
    echo "Resultado: mantenimiento local comprobado con dos procesos CLI independientes.\n";
    echo "La continuidad de las tareas y Telegram en Render siguen pendientes.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "No se completo la comprobacion. ".
        ($error instanceof PDOException ? 'Revisa la conexion y el estado de la copia local.' : $error->getMessage())."\n");
    Database::reset(); exit(1);
} finally {
    if ($taskLocked) {
        try { Database::run('SELECT pg_advisory_unlock(736428102)'); }
        catch (Throwable) { Database::reset(); }
    }
}
