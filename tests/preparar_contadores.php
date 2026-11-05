<?php
declare(strict_types=1);
/**
 * Fixtures del reloj real de PostgreSQL; no cambia la hora del equipo.
 * Solo escribe en clinica_vida_salud_manual_test, en PostgreSQL local.
 * Ejecutar desde la raíz del proyecto: php ruta/preparar_contadores.php
 */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$bootstrap=getcwd().'/bootstrap.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR,"Ejecuta desde la carpeta clinica-vida-salud del proyecto.\n"); exit(1);
}
require $bootstrap;

use App\Config;
use App\Database;
use App\AppointmentService;
use App\CounterService;
use App\Repositories\AppointmentRepository;

const COUNTER_DATABASE='clinica_vida_salud_manual_test';
try {
    if (!in_array(Config::get('DB_HOST','127.0.0.1'),['127.0.0.1','localhost','::1'],true)) {
        throw new RuntimeException('Este archivo solo admite PostgreSQL en el equipo local.');
    }
    putenv('DB_NAME='.COUNTER_DATABASE);
    if (Database::run('SELECT current_database()')->fetchColumn()!==COUNTER_DATABASE) {
        throw new RuntimeException('La conexion no corresponde a la copia de pruebas.');
    }
    $fixtures=Database::transaction(static function (): array {
        Database::run('SELECT pg_advisory_xact_lock(6830031)');
        $actor=Database::run("SELECT u.id FROM usuario u JOIN rol r ON r.id=u.rol_id
            WHERE u.nombre_usuario='recepcion' AND r.codigo='recepcionista' AND u.habilitado")->fetchColumn();
        $patient=Database::run("SELECT id FROM paciente WHERE ci='DEMO-001'")->fetchColumn();
        $doctor=Database::run("SELECT m.id,e.id AS especialidad_id FROM medico m
            JOIN medico_especialidad me ON me.medico_id=m.id JOIN especialidad e ON e.id=me.especialidad_id
            WHERE m.registro_profesional='DEMO-MED-001' AND m.activo AND me.activo AND e.activo
            AND lower(btrim(e.nombre))='medicina general'")->fetch();
        if (!$actor || !$patient || !$doctor) {
            throw new RuntimeException('No se encontraron las fichas de prueba de Ana, Rosa y recepcion.');
        }
        AppointmentService::actor((int) $actor,'CU12');
        $clock=Database::run("SELECT now() AS ahora,
            date_trunc('hour',now())+floor(extract(minute FROM now())/30)*interval '30 minutes' AS bloque")->fetch();
        $starts=Database::run("SELECT ?::timestamptz AS en_curso,
            ?::timestamptz-interval '30 minutes' AS finalizado",[$clock['bloque'],$clock['bloque']])->fetch();
        AppointmentRepository::actor((int) $actor);
        // Solo fixtures de la copia: permite tiempos pasados y fuera del horario
        // habitual. FK, CHECK, exclusiones e historial continúan funcionando.
        // La transacción revierte también el DDL si falla la preparación.
        Database::connection()->exec('ALTER TABLE cita DISABLE TRIGGER tr_validar_cita');
        $result=[];
        foreach ($starts as $kind=>$start) {
            $row=Database::run("SELECT id,inicio,fin,estado FROM cita WHERE paciente_id=? AND medico_id=?
                AND inicio=?::timestamptz AND motivo LIKE 'PRUEBA MANUAL: contador%'
                AND estado IN ('programada','confirmada') ORDER BY id LIMIT 1",
                [(int) $patient,(int) $doctor['id'],$start])->fetch();
            if (!$row) {
                $row=Database::run("INSERT INTO cita(paciente_id,medico_id,especialidad_id,inicio,fin,
                    creado_por,creado_en,motivo) VALUES(?,?,?,?::timestamptz,?::timestamptz+interval '30 minutes',
                    ?,?::timestamptz-interval '2 days',?) RETURNING id,inicio,fin,estado",
                    [(int) $patient,(int) $doctor['id'],(int) $doctor['especialidad_id'],$start,$start,
                        (int) $actor,$start,'PRUEBA MANUAL: contador '.($kind==='en_curso' ? 'en curso' : 'finalizado')])->fetch();
            }
            $result[$kind]=['row'=>$row,'counter'=>CounterService::calculate($row,$clock['ahora'])];
        }
        Database::connection()->exec('ALTER TABLE cita ENABLE TRIGGER tr_validar_cita');
        return $result;
    });
    echo 'Preparacion completada SOLO en '.COUNTER_DATABASE.".\n";
    foreach ($fixtures as $kind=>$fixture) {
        $row=$fixture['row'];
        echo ($kind==='en_curso' ? 'EN CURSO' : 'FINALIZADO').' | '.AppointmentService::code((int) $row['id']).
            ' | '.clinic_datetime($row['inicio']).' a '.clinic_input($row['fin'],'H:i').
            ' | Estado: '.ucfirst($row['estado'])."\n";
        echo 'http://127.0.0.1:8001/citas/'.(int) $row['id']."\n";
    }
    echo "Abre primero EN CURSO. Si ya termino al abrirlo, vuelve a ejecutar este archivo.\n";
} catch (Throwable $error) {
    Database::reset();
    fwrite(STDERR,"No se completo la preparacion: ".$error->getMessage()."\n"); exit(1);
}
