<?php
/**
 * Carga datos FICTICIOS de demostración para la presentación (CU06, CU08 a CU12, CU14, CU15, CU21).
 *
 * Se puede ejecutar varias veces: reutiliza especialidades, médicos, pacientes y cuentas que ya existen
 * y omite los turnos ocupados. Usa los servicios del sistema, así que respeta todas las reglas y deja
 * auditoría. No crea historial pasado ni multas: esos datos nacen del uso real del sistema.
 *
 * Uso (desde la carpeta del proyecto):
 *   set CONFIRMAR_POBLAR=si
 *   set DEMO_PASSWORD=Demo-Vida-2026   (opcional; debe cumplir la política de contraseñas: de 8 caracteres a 72 bytes,
 *   con mayúscula, minúscula, número y carácter especial; crea 3 cuentas de paciente)
 *   php bin/poblar_demo.php
 */
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use App\AppointmentService; use App\Config; use App\Database; use App\DoctorService; use App\PatientService;
use App\ScheduleService; use App\SpecialtyService; use App\UrgentService; use App\UserService;
use App\Repositories\RoleRepository;

if (PHP_SAPI !== 'cli') { exit(1); }
echo 'Base: ' . Config::get('DB_NAME') . ' en ' . Config::get('DB_HOST') . PHP_EOL;
if (getenv('CONFIRMAR_POBLAR') !== 'si') { exit("Para continuar define CONFIRMAR_POBLAR=si\n"); }
$pw = getenv('DEMO_PASSWORD') ?: '';
$tz = new DateTimeZone(Config::get('APP_TIMEZONE', 'America/La_Paz'));
$log = ['especialidades' => 0, 'medicos' => 0, 'horarios' => 0, 'pacientes' => 0, 'cuentas' => 0, 'citas' => 0, 'omitidas' => 0];
$one = fn(string $sql, array $p = []) => Database::run($sql, $p)->fetchColumn();
$roleUser = fn(string $code) => (int) $one("SELECT u.id FROM usuario u JOIN rol r ON r.id=u.rol_id WHERE r.codigo=? AND u.habilitado ORDER BY u.id LIMIT 1", [$code]);

$admin = $roleUser('administrador');
if (!$admin) { exit("No hay un administrador habilitado.\n"); }
$recep = $roleUser('recepcionista');
if (!$recep) {
    if (App\Validation::passwordProblems($pw)) { exit("No hay recepcionista: define en DEMO_PASSWORD una contraseña que cumpla la política (8 a 72 bytes, con mayúscula, minúscula, número y carácter especial) para crear la cuenta recepcion.demo.\n"); }
    $recep = UserService::save(['nombre_usuario' => 'recepcion.demo', 'correo' => 'recepcion.demo@example.com',
        'rol_id' => (string) RoleRepository::byCode('recepcionista')['id'], 'password' => $pw, 'password_confirmation' => $pw, 'habilitado' => '1']);
    $log['cuentas']++;
}

// Especialidades: se reutilizan si ya existen con el mismo nombre (sin distinguir mayúsculas ni espacios).
$esp = [];
foreach ([['Medicina General', 'Atención primaria, control general y derivación a especialistas'],
          ['Pediatría', 'Atención de niños y adolescentes'],
          ['Ginecología', 'Salud de la mujer y control prenatal'],
          ['Odontología', 'Salud bucal, limpiezas y tratamientos dentales'],
          ['Cardiología', 'Diagnóstico y control de enfermedades del corazón']] as [$n, $d]) {
    $id = (int) $one("SELECT id FROM especialidad WHERE lower(regexp_replace(trim(nombre),'\\s+',' ','g'))=lower(?)", [$n]);
    if (!$id) { $id = SpecialtyService::save(['nombre' => $n, 'descripcion' => $d, 'activo' => '1'], $admin); $log['especialidades']++; }
    $esp[$n] = $id;
}

// Médicos con dos tramos diarios de lunes a viernes.
$docs = [];
foreach ([['DEMO-MED-101', 'Marcelo', 'Antelo Suárez', ['Medicina General']],
          ['DEMO-MED-102', 'Gabriela', 'Rocha Paz', ['Pediatría']],
          ['DEMO-MED-103', 'Patricia', 'Montaño Ribera', ['Ginecología']],
          ['DEMO-MED-104', 'Diego', 'Aguilera Justiniano', ['Odontología']],
          ['DEMO-MED-105', 'Fernando', 'Vargas Terceros', ['Cardiología', 'Medicina General']],
          ['DEMO-MED-106', 'Valeria', 'Céspedes Áñez', ['Pediatría', 'Medicina General']]] as [$r, $n, $a, $e]) {
    $id = (int) $one('SELECT id FROM medico WHERE registro_profesional=?', [$r]);
    if (!$id) {
        $id = DoctorService::save(['registro_profesional' => $r, 'nombres' => $n, 'apellidos' => $a, 'telefono' => '', 'correo' => '',
            'activo' => '1', 'usuario_id' => '', 'especialidades' => array_map(fn($x) => (string) $esp[$x], $e)], $admin);
        $log['medicos']++;
        foreach (range(1, 5) as $day) {
            foreach ([['08:00', '12:00'], ['14:00', '18:00']] as [$i, $f]) {
                ScheduleService::save(['medico_id' => (string) $id, 'dia_semana' => (string) $day, 'hora_inicio' => $i, 'hora_fin' => $f, 'activo' => '1'], $admin);
                $log['horarios']++;
            }
        }
    }
    $docs[$r] = ['id' => $id, 'esp' => $e];
}

// Pacientes registrados por recepción (CU06). Datos inventados.
$people = [['7845123', 'Lucía', 'Rojas Vaca', '1990-04-12', 'Femenino', '70012345', 'Barrio Equipetrol, calle 3'],
    ['6932145', 'Mateo', 'Suárez Peña', '2016-09-03', 'Masculino', '71234567', 'Av. Banzer, 4to anillo'],
    ['8123456', 'Carla', 'Méndez Ortiz', '1985-11-27', 'Femenino', '72345678', 'Plan 3000, UV 150'],
    ['5698741', 'Jorge', 'Flores Arce', '1978-02-14', 'Masculino', '73456789', 'Villa 1ro de Mayo'],
    ['9012345', 'Sofía', 'Gutiérrez Lima', '2001-07-21', 'Femenino', '74567890', 'Av. Santos Dumont, 3er anillo'],
    ['4567812', 'Ricardo', 'Pinto Zabala', '1965-12-05', 'Masculino', '75678901', 'Barrio Hamacas'],
    ['7012893', 'Daniela', 'Chávez Ribera', '1997-03-30', 'Femenino', '76789012', 'Av. Alemana, 5to anillo'],
    ['6123987', 'Andrés', 'Mercado Salvatierra', '1988-08-18', 'Masculino', '77890123', 'Urbanización Los Mangales'],
    ['8456321', 'Camila', 'Torrez Vaca Díez', '2019-01-09', 'Femenino', '78901234', 'Barrio Sirari'],
    ['5234789', 'Luis Fernando', 'Peña Aguilera', '1972-10-02', 'Masculino', '79012345', 'Av. Pirai, 2do anillo'],
    ['7654321', 'Valentina', 'Justiniano Roca', '1994-05-16', 'Femenino', '60123456', 'Barrio Las Palmas'],
    ['6789012', 'Miguel', 'Saucedo Terrazas', '1959-06-25', 'Masculino', '61234567', 'Villa Primero de Mayo, UV 80']];
$pat = [];
foreach ($people as [$ci, $n, $a, $b, $s, $t, $d]) {
    $id = (int) $one('SELECT id FROM paciente WHERE ci=?', [$ci]);
    if (!$id) {
        $mail = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', explode(' ', $n)[0] . '.' . explode(' ', $a)[0])) . '@example.com';
        $id = PatientService::save(['ci' => $ci, 'nombres' => $n, 'apellidos' => $a, 'fecha_nacimiento' => $b, 'sexo' => $s,
            'telefono' => $t, 'correo' => $mail, 'direccion' => $d . ', Santa Cruz de la Sierra'], $recep);
        $log['pacientes']++;
    }
    $pat[$ci] = $id;
}

// Tres cuentas de paciente para la demostración (CU01), solo si se indicó DEMO_PASSWORD.
$accounts = [];
if (!App\Validation::passwordProblems($pw)) {
    foreach ([['paciente.demo1', '3214567', 'Ana Paula', 'Roca Justiniano', '62345678'],
              ['paciente.demo2', '3325678', 'Bruno', 'Salvatierra Méndez', '63456789'],
              ['paciente.demo3', '3436789', 'Natalia', 'Vaca Pereira', '64567890']] as [$u, $ci, $n, $a, $t]) {
        $uid = (int) $one('SELECT id FROM usuario WHERE lower(nombre_usuario)=lower(?)', [$u]);
        if (!$uid) {
            $uid = UserService::save(['nombre_usuario' => $u, 'correo' => "$u@example.com", 'rol_id' => (string) RoleRepository::byCode('paciente')['id'],
                'password' => $pw, 'password_confirmation' => $pw, 'habilitado' => '1', 'ci' => $ci, 'nombres' => $n, 'apellidos' => $a, 'telefono' => $t]);
            $log['cuentas']++;
        }
        $accounts[$u] = $uid;
        $pat[$ci] = (int) $one('SELECT id FROM paciente WHERE usuario_id=?', [$uid]);
    }
}

// Citas en los próximos días hábiles (desde mañana). Si un turno está ocupado, se omite.
$motives = ['Control general', 'Dolor de cabeza frecuente', 'Control de presión arterial', 'Revisión de resultados de laboratorio',
    'Control de crecimiento', 'Fiebre y tos', 'Consulta anual', 'Control prenatal', 'Limpieza dental', 'Dolor de muela',
    'Electrocardiograma de control', 'Palpitaciones', 'Vacunación y control', 'Chequeo preventivo'];
$days = []; $d = new DateTimeImmutable('tomorrow', $tz);
while (count($days) < 4) { if ((int) $d->format('N') <= 5) { $days[] = $d->format('Y-m-d'); } $d = $d->modify('+1 day'); }
$hours = ['08:30', '09:00', '09:30', '10:30', '11:00', '14:30', '15:00', '16:00', '16:30', '17:00'];
$patIds = array_values($pat); $made = []; $k = 0;
$already = (int) $one("SELECT count(*) FROM cita c JOIN medico m ON m.id=c.medico_id WHERE m.registro_profesional LIKE 'DEMO-MED-1%'");
if ($already) { $days = []; echo "Las citas de demostración ya estaban cargadas ($already); no se agregan más.\n"; }
foreach ($days as $di => $day) {
    foreach ($docs as $doc) {
        foreach ([0] as $slot) {
            $k++;
            $e = $doc['esp'][$k % count($doc['esp'])];
            $start = $day . 'T' . $hours[($di * 3 + $k + $slot * 4) % count($hours)];
            try {
                $made[] = AppointmentService::book(['especialidad_id' => (string) $esp[$e], 'medico_id' => (string) $doc['id'], 'inicio' => $start,
                    'motivo' => $motives[$k % count($motives)], 'paciente_id' => (string) $patIds[$k % count($patIds)]], $recep);
                $log['citas']++;
            } catch (Throwable $ex) { $log['omitidas']++; }
        }
    }
}
// Algunos cambios de estado para que el sistema muestre actividad: confirmaciones, cancelaciones y una reprogramación.
$ra = AppointmentService::actor($recep);
$v = fn(int $id) => AppointmentService::version(AppointmentService::find($id, $ra));
foreach (array_slice($made, 0, 8) as $i => $id) {
    try { if ($i % 2 === 0) { AppointmentService::confirm($id, ['version' => $v($id)], $recep); } } catch (Throwable $ex) {}
}
foreach (array_slice($made, -3) as $id) {
    try { AppointmentService::cancel($id, ['version' => $v($id), 'motivo_cambio' => 'El paciente avisó que no podrá asistir', 'origen_cambio' => 'paciente'], $recep); } catch (Throwable $ex) {}
}
// Solicitudes urgentes de las cuentas demo (CU21).
$urg = ['paciente.demo1' => 'Fiebre alta desde anoche y dolor de garganta; necesito una consulta hoy si es posible.',
        'paciente.demo2' => 'Dolor fuerte de muela que no me deja dormir.'];
foreach ($urg as $u => $motivo) {
    if (!isset($accounts[$u])) { continue; }
    $exists = (int) $one("SELECT count(*) FROM solicitud_urgente WHERE paciente_id=? AND estado='pendiente'", [$pat[array_search($u, array_keys($accounts)) === 0 ? '3214567' : '3325678']]);
    if (!$exists) {
        try { UrgentService::create(['motivo' => $motivo, 'contacto' => $u === 'paciente.demo1' ? '62345678' : '63456789', 'clave_envio' => bin2hex(random_bytes(16))], $accounts[$u]); } catch (Throwable $ex) {}
    }
}
foreach ($log as $k2 => $n) { echo str_pad($k2, 16) . $n . PHP_EOL; }
echo 'Totales en la base: ' . $one('SELECT count(*) FROM especialidad WHERE activo') . ' especialidades, '
    . $one('SELECT count(*) FROM medico WHERE activo') . ' médicos, ' . $one('SELECT count(*) FROM paciente') . ' pacientes, '
    . $one('SELECT count(*) FROM cita') . ' citas.' . PHP_EOL;
