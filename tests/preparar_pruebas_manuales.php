<?php
declare(strict_types=1);
/**
 * Preparación de fixtures históricos SOLO en la copia local indicada.
 * Ejecutar desde la raíz del proyecto: php ruta/preparar_pruebas_manuales.php
 * No modifica .env, no envía Telegram y no escribe en la base original.
 */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$bootstrap=getcwd().'/bootstrap.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR,"Ejecuta este archivo desde la carpeta clinica-vida-salud de tu proyecto.\n"); exit(1);
}
require $bootstrap;

use App\Config;
use App\Database;
use App\AppointmentService;
use App\Repositories\AppointmentRepository;

const MANUAL_DATABASE='clinica_vida_salud_manual_test';
const MANUAL_REASONS=['PRUEBA MANUAL: primera incidencia','PRUEBA MANUAL: segunda incidencia'];

function manual_snapshot(): array
{
    return Database::transaction(static function (): array {
        Database::connection()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $result=[];
        // Comparación de valores y cantidades; nunca se imprimen contraseñas ni datos médicos.
        foreach (['usuario','rol','permiso','rol_permiso','paciente','perfil_salud','contacto_emergencia',
            'medico','especialidad','medico_especialidad','horario','ausencia_medico','parametro_sistema',
            'cita','historial_cita','incumplimiento','multa','pago_multa','solicitud_urgente',
            'historial_solicitud_urgente','migracion'] as $table) {
            $rows=Database::run('SELECT row_to_json(t)::text AS contenido FROM '.$table.' t ORDER BY 1')->fetchAll();
            $result[$table]=['count'=>count($rows),'hash'=>hash('sha256',implode("\n",array_column($rows,'contenido')))];
        }
        return $result;
    });
}

function manual_show(array $rows): void
{
    foreach ($rows as $index=>$row) {
        echo ($index+1).'. '.AppointmentService::code((int) $row['id']).' | '.
            clinic_datetime($row['inicio']).' | '.ucfirst($row['estado'])."\n";
        echo '   http://127.0.0.1:8001/citas/'.(int) $row['id']."/inasistencia\n";
    }
}

try {
    if (!in_array(Config::get('DB_HOST','127.0.0.1'),['127.0.0.1','localhost','::1'],true)) {
        throw new RuntimeException('Este archivo solo admite PostgreSQL en el equipo local.');
    }
    $source=Config::get('DB_NAME','clinica_vida_salud');
    if ($source===MANUAL_DATABASE) {
        throw new RuntimeException('Ejecuta con la configuracion original del proyecto; el archivo selecciona la copia por si mismo.');
    }
    $original=manual_snapshot(); // Solo lectura en la conexión original.
    Database::reset();
    putenv('DB_NAME='.MANUAL_DATABASE);
    putenv('TELEGRAM_BOT_TOKEN=');
    if (Database::run('SELECT current_database()')->fetchColumn()!==MANUAL_DATABASE) {
        throw new RuntimeException('La conexion no corresponde a la copia de pruebas.');
    }
    $existing=Database::run('SELECT id,inicio,estado,motivo FROM cita WHERE motivo IN (?,?) ORDER BY motivo',
        MANUAL_REASONS)->fetchAll();
    if ($existing) {
        if (count($existing)!==2) { throw new RuntimeException('Hay una preparacion incompleta. Revisa la copia antes de continuar.'); }
        echo "Las dos citas de prueba ya existen; se conservan sus estados e historial.\n";
        manual_show($existing); exit(0);
    }
    $restored=manual_snapshot();
    if ($original!==$restored) {
        $different=array_keys(array_filter($original,static fn($v,$k)=>$v!==($restored[$k] ?? null),ARRAY_FILTER_USE_BOTH));
        throw new RuntimeException('La copia no coincide con la base original en: '.implode(', ',$different).
            '. No se crearon citas; revisa si hubo cambios despues del respaldo.');
    }
    echo "Restauracion comprobada: ".$restored['paciente']['count'].' pacientes, '.
        $restored['cita']['count']." citas; cuentas, permisos e historiales coinciden.\n";
    $rows=Database::transaction(static function (): array {
        Database::run('SELECT pg_advisory_xact_lock(6830030)');
        if (Database::run('SELECT current_database()')->fetchColumn()!==MANUAL_DATABASE) {
            throw new RuntimeException('Destino de pruebas incorrecto.');
        }
        $again=Database::run('SELECT id,inicio,estado FROM cita WHERE motivo IN (?,?) ORDER BY motivo',MANUAL_REASONS)->fetchAll();
        if (count($again)===2) { return $again; }
        if ($again) { throw new RuntimeException('Preparacion incompleta en la copia de pruebas.'); }
        $person=Database::run("SELECT p.id,p.activo,p.creado_en FROM paciente p
            JOIN usuario u ON u.id=p.usuario_id JOIN rol r ON r.id=u.rol_id
            WHERE p.ci='DEMO-001' AND r.codigo='paciente' AND u.habilitado")->fetch();
        $reception=Database::run("SELECT u.id,u.creado_en FROM usuario u JOIN rol r ON r.id=u.rol_id
            WHERE u.nombre_usuario='recepcion' AND r.codigo='recepcionista' AND u.habilitado")->fetch();
        $doctor=Database::run("SELECT m.id,m.usuario_id,e.id AS especialidad_id
            FROM medico m JOIN medico_especialidad me ON me.medico_id=m.id
            JOIN especialidad e ON e.id=me.especialidad_id
            WHERE m.registro_profesional='DEMO-MED-001' AND m.activo AND me.activo AND e.activo
            AND lower(btrim(e.nombre))='medicina general'")->fetch();
        if (!$person || !$person['activo'] || !$reception || !$doctor) {
            throw new RuntimeException('Se necesitan Ana DEMO-001 activa, la cuenta recepcion y Rosa DEMO-MED-001 con Medicina General.');
        }
        $pid=(int) $person['id']; $actor=(int) $reception['id']; $did=(int) $doctor['id'];
        AppointmentService::actor($actor,'CU17');
        if ((int) Database::run('SELECT count(*) FROM incumplimiento i JOIN cita c ON c.id=i.cita_id WHERE c.paciente_id=?',[$pid])->fetchColumn()!==0) {
            throw new RuntimeException('Ana ya tiene incidencias en la copia. Esta preparacion requiere empezar en cero.');
        }
        $hours=(int) AppointmentRepository::policy()['plazo_cancelacion_horas']+24;
        $registered=max($person['creado_en'],$reception['creado_en']);
        $slots=Database::run("WITH dias AS (
            SELECT generate_series(current_date-14,current_date-1,interval '1 day')::date AS dia
        ), turnos AS (
            SELECT s.inicio AT TIME ZONE 'America/La_Paz' AS inicio
            FROM dias d JOIN horario h ON h.dia_semana=extract(isodow FROM d.dia) AND h.medico_id=? AND h.activo
            CROSS JOIN LATERAL generate_series(d.dia+h.hora_inicio,d.dia+h.hora_fin-interval '30 minutes',
                interval '30 minutes') AS s(inicio)
        ) SELECT DISTINCT t.inicio FROM turnos t
        WHERE t.inicio+interval '30 minutes'<now()
            AND t.inicio-make_interval(hours=>?::integer)>=?::timestamptz
            AND NOT EXISTS(SELECT 1 FROM cita c WHERE c.medico_id=? AND
                tstzrange(c.inicio,c.fin,'[)') && tstzrange(t.inicio,t.inicio+interval '30 minutes','[)'))
            AND NOT EXISTS(SELECT 1 FROM ausencia_medico a WHERE a.medico_id=? AND
                tstzrange(a.inicio,a.fin,'[)') && tstzrange(t.inicio,t.inicio+interval '30 minutes','[)'))
        ORDER BY t.inicio DESC LIMIT 2",[$did,$hours,$registered,$did,$did])->fetchAll();
        if (count($slots)!==2) { throw new RuntimeException('No hay dos bloques historicos libres en los horarios de Rosa. No se crearon citas.'); }
        $slots=array_reverse($slots);
        AppointmentRepository::actor($actor);
        // Exclusivamente para fixtures vencidos en esta copia. Las FK, CHECK,
        // exclusiones y auditoría siguen activas. Un fallo revierte también el DDL.
        Database::connection()->exec('ALTER TABLE cita DISABLE TRIGGER tr_validar_cita');
        $created=[];
        foreach ($slots as $index=>$slot) {
            $created[]=Database::run("INSERT INTO cita(paciente_id,medico_id,especialidad_id,inicio,fin,
                creado_por,creado_en,motivo) VALUES(?,?,?,?::timestamptz,?::timestamptz+interval '30 minutes',
                ?,?::timestamptz-make_interval(hours=>?::integer),?) RETURNING id,inicio,estado",
                [$pid,$did,(int) $doctor['especialidad_id'],$slot['inicio'],$slot['inicio'],$actor,
                    $slot['inicio'],$hours,MANUAL_REASONS[$index]])->fetch();
        }
        Database::connection()->exec('ALTER TABLE cita ENABLE TRIGGER tr_validar_cita');
        return $created;
    });
    echo "Preparacion completada SOLO en ".MANUAL_DATABASE.".\n";
    echo "Citas ficticias vencidas para comprobar primera advertencia y segunda incidencia:\n";
    manual_show($rows);
} catch (Throwable $error) {
    Database::reset();
    fwrite(STDERR,"No se completo la preparacion: ".$error->getMessage()."\n"); exit(1);
}
