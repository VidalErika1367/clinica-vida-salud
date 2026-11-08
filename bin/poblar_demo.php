<?php
/**
 * Carga datos FICTICIOS de demostración para la presentación (CU06, CU08 a CU12, CU14, CU15, CU21).
 *
 * Solo trabaja con las cinco especialidades oficiales de la clínica (Medicina General, Pediatría, Ginecología,
 * Odontología y Cardiología). Crea las que falten, nunca otras, y no toca las especialidades ajenas a esa lista:
 * solo avisa sus nombres. Crea un médico demo por especialidad únicamente si no hay ningún médico activo con esa
 * especialidad activa; los médicos que ya existen se reutilizan para las citas.
 *
 * Se puede ejecutar varias veces sin duplicar nada. Antes de escribir muestra la base, el servidor y un resumen
 * de lo que va a crear, y exige CONFIRMAR_POBLAR=si. Usa los servicios del sistema, así que respeta todas las
 * reglas y deja auditoría. No crea historial pasado ni multas: esos datos nacen del uso real del sistema.
 *
 * Uso (desde la carpeta del proyecto):
 *   set CONFIRMAR_POBLAR=si
 *   set DEMO_PASSWORD=Demo-Vida-2026   (opcional; debe cumplir la política de contraseñas: de 8 caracteres a 72 bytes,
 *   con mayúscula, minúscula, número y carácter especial)
 *   php bin/poblar_demo.php
 * Con DEMO_PASSWORD crea, si no existen, admin.demo1, admin.demo2, recepcion.demo1, recepcion.demo2, medico.demo1,
 * medico.demo2 (vinculadas a médicos que no tengan cuenta), paciente.demo1 y paciente.demo2.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use App\AppointmentService; use App\Config; use App\Database; use App\DoctorService; use App\PatientService;
use App\ScheduleService; use App\SpecialtyService; use App\UrgentService; use App\UserService; use App\Validation;
use App\Repositories\RoleRepository;

if (PHP_SAPI !== 'cli') { exit(1); }
$tz = new DateTimeZone(Config::get('APP_TIMEZONE', 'America/La_Paz'));
$one = fn(string $sql, array $p = []) => Database::run($sql, $p)->fetchColumn();
$rows = fn(string $sql, array $p = []) => Database::run($sql, $p)->fetchAll();
$roleUser = fn(string $code) => (int) $one("SELECT u.id FROM usuario u JOIN rol r ON r.id=u.rol_id WHERE r.codigo=? AND u.habilitado ORDER BY u.id LIMIT 1", [$code]);
// Comparación de nombres sin distinguir mayúsculas ni espacios repetidos.
$norm = fn(string $s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));

// ---------------------------------------------------------------------------------------------
// Datos de la demostración
// ---------------------------------------------------------------------------------------------
$official = [
    'Medicina General' => 'Atención primaria, control general y derivación a especialistas',
    'Pediatría' => 'Atención de niños y adolescentes',
    'Ginecología' => 'Salud de la mujer y control prenatal',
    'Odontología' => 'Salud bucal, limpiezas y tratamientos dentales',
    'Cardiología' => 'Diagnóstico y control de enfermedades del corazón',
];
// Un médico demo por especialidad oficial; solo se crea si la especialidad no tiene médico activo.
$demoDoctors = [
    'Medicina General' => ['DEMO-MED-101', 'Marcelo', 'Antelo Suárez'],
    'Pediatría' => ['DEMO-MED-102', 'Gabriela', 'Rocha Paz'],
    'Ginecología' => ['DEMO-MED-103', 'Patricia', 'Montaño Ribera'],
    'Odontología' => ['DEMO-MED-104', 'Diego', 'Aguilera Justiniano'],
    'Cardiología' => ['DEMO-MED-105', 'Fernando', 'Vargas Terceros'],
];
$staffAccounts = [['admin.demo1', 'administrador'], ['admin.demo2', 'administrador'], ['recepcion.demo1', 'recepcionista'],
    ['recepcion.demo2', 'recepcionista'], ['medico.demo1', 'medico'], ['medico.demo2', 'medico']];
$patientAccounts = [['paciente.demo1', '3214567', 'Ana Paula', 'Roca Justiniano', '62345678'],
    ['paciente.demo2', '3325678', 'Bruno', 'Salvatierra Méndez', '63456789']];
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
$demoCis = array_merge(array_column($people, 0), array_column($patientAccounts, 1));
$motives = ['Control general', 'Dolor de cabeza frecuente', 'Control de presión arterial', 'Revisión de resultados de laboratorio',
    'Control de crecimiento', 'Fiebre y tos', 'Consulta anual', 'Control prenatal', 'Limpieza dental', 'Dolor de muela',
    'Electrocardiograma de control', 'Palpitaciones', 'Vacunación y control', 'Chequeo preventivo'];
const TARGET_APPOINTMENTS = 20;
const BUSINESS_DAYS = 4;

// ---------------------------------------------------------------------------------------------
// 1. Lectura: qué existe ya (solo SELECT) y resumen de lo que se va a crear
// ---------------------------------------------------------------------------------------------
echo 'Base: ' . Config::get('DB_NAME') . ' en ' . Config::get('DB_HOST') . PHP_EOL;
$pw = getenv('DEMO_PASSWORD') ?: '';
$passwordOk = $pw !== '' && Validation::passwordProblems($pw) === [];
$admin = $roleUser('administrador');
if (!$admin) { exit("No hay un administrador habilitado.\n"); }

$specialtyRows = $rows('SELECT id,nombre,activo FROM especialidad ORDER BY id');
$specId = []; $specActive = []; $foreign = [];
$officialByNorm = [];
foreach (array_keys($official) as $name) { $officialByNorm[$norm($name)] = $name; }
foreach ($specialtyRows as $row) {
    $key = $norm($row['nombre']);
    if (isset($officialByNorm[$key])) { $specId[$officialByNorm[$key]] = (int) $row['id']; $specActive[$officialByNorm[$key]] = (bool) $row['activo']; }
    else { $foreign[] = $row['nombre']; }
}
$missingSpecs = array_values(array_filter(array_keys($official), fn($n) => !isset($specId[$n])));
$inactiveSpecs = array_values(array_filter(array_keys($specId), fn($n) => !$specActive[$n]));

// Especialidades oficiales sin ningún médico activo con esa especialidad activa (las que no existen todavía también).
$coveredSql = 'SELECT 1 FROM medico m JOIN medico_especialidad me ON me.medico_id=m.id AND me.activo
    JOIN especialidad e ON e.id=me.especialidad_id AND e.activo WHERE m.activo AND e.id=? LIMIT 1';
$doctorsToCreate = [];
$skippedDoctors = [];
foreach ($official as $name => $_) {
    if (isset($specId[$name]) && !$specActive[$name]) { continue; }
    if (isset($specId[$name]) && $one($coveredSql, [$specId[$name]])) { continue; }
    if ($one('SELECT id FROM medico WHERE registro_profesional=?', [$demoDoctors[$name][0]])) { $skippedDoctors[] = $demoDoctors[$name][0]; continue; }
    $doctorsToCreate[] = $name;
}
$existingUsers = array_map('mb_strtolower', array_column($rows('SELECT nombre_usuario FROM usuario'), 'nombre_usuario'));
$accountsToCreate = array_values(array_filter(array_merge(array_column($staffAccounts, 0), array_column($patientAccounts, 0)),
    fn($u) => !in_array($u, $existingUsers, true)));
$existingCis = array_column($rows('SELECT ci FROM paciente'), 'ci');
$patientsToCreate = count(array_filter($people, fn($p) => !in_array($p[0], $existingCis, true)));
$placeholders = implode(',', array_fill(0, count($demoCis), '?'));
$already = (int) $one("SELECT count(*) FROM cita c JOIN paciente p ON p.id=c.paciente_id WHERE p.ci IN ($placeholders)", $demoCis);
$recep = $roleUser('recepcionista');

echo PHP_EOL . 'Resumen de lo que se va a crear:' . PHP_EOL;
echo '  Especialidades oficiales nuevas: ' . ($missingSpecs ? implode(', ', $missingSpecs) : 'ninguna') . PHP_EOL;
echo '  Médicos demo nuevos (especialidad sin médico activo): ' . ($doctorsToCreate ? implode(', ', array_map(fn($n) => $demoDoctors[$n][0] . ' (' . $n . ')', $doctorsToCreate)) : 'ninguno') . PHP_EOL;
echo '  Pacientes demo nuevos: ' . $patientsToCreate . ' de ' . count($people) . PHP_EOL;
if ($accountsToCreate && $passwordOk) { echo '  Cuentas demo nuevas: ' . implode(', ', $accountsToCreate) . PHP_EOL; }
elseif ($accountsToCreate) { echo '  Cuentas demo: no se crearán (define DEMO_PASSWORD con una contraseña que cumpla la política): ' . implode(', ', $accountsToCreate) . PHP_EOL; }
else { echo '  Cuentas demo nuevas: ninguna' . PHP_EOL; }
echo '  Citas: ' . ($already ? "ya cargadas ($already); no se agregan más" : 'hasta ' . TARGET_APPOINTMENTS . ' en los próximos ' . BUSINESS_DAYS . ' días hábiles') . PHP_EOL;
if ($foreign) { echo 'AVISO: hay especialidades fuera de la lista oficial; no se tocan: ' . implode(', ', $foreign) . PHP_EOL; }
if ($inactiveSpecs) { echo 'AVISO: especialidades oficiales inactivas; no se reactivan ni se les crea médico: ' . implode(', ', $inactiveSpecs) . PHP_EOL; }
if ($skippedDoctors) { echo 'AVISO: ya existe el registro ' . implode(', ', $skippedDoctors) . ' (inactivo o sin esa especialidad); no se crea otro médico.' . PHP_EOL; }
if (!$recep && !($passwordOk && in_array('recepcion.demo1', $accountsToCreate, true))) {
    exit("No hay recepcionista: define en DEMO_PASSWORD una contraseña que cumpla la política (8 a 72 bytes, con mayúscula, minúscula, número y carácter especial) para crear las cuentas de recepción demo.\n");
}
if (getenv('CONFIRMAR_POBLAR') !== 'si') { exit(PHP_EOL . "Para continuar define CONFIRMAR_POBLAR=si\n"); }

// ---------------------------------------------------------------------------------------------
// 2. Escritura
// ---------------------------------------------------------------------------------------------
$log = ['especialidades' => 0, 'medicos' => 0, 'horarios' => 0, 'pacientes' => 0, 'cuentas' => 0, 'vinculadas' => 0, 'citas' => 0, 'omitidas' => 0];
$newAccount = function (string $username, string $role, array $extra = []) use (&$log, $pw, $one): int {
    $id = (int) $one('SELECT id FROM usuario WHERE lower(nombre_usuario)=lower(?)', [$username]);
    if ($id) { return $id; }
    $id = UserService::save(array_merge(['nombre_usuario' => $username, 'correo' => "$username@example.com",
        'rol_id' => (string) RoleRepository::byCode($role)['id'], 'password' => $pw, 'password_confirmation' => $pw, 'habilitado' => '1'], $extra));
    $log['cuentas']++;
    return $id;
};

// Especialidades oficiales: solo se crean las que faltan.
foreach ($official as $name => $description) {
    if (!isset($specId[$name])) {
        $specId[$name] = SpecialtyService::save(['nombre' => $name, 'descripcion' => $description, 'activo' => '1'], $admin);
        $specActive[$name] = true; $log['especialidades']++;
    }
}

// Médicos demo, solo para las especialidades sin médico activo, con dos tramos diarios de lunes a viernes.
foreach ($doctorsToCreate as $name) {
    [$registro, $nombres, $apellidos] = $demoDoctors[$name];
    $id = DoctorService::save(['registro_profesional' => $registro, 'nombres' => $nombres, 'apellidos' => $apellidos, 'telefono' => '', 'correo' => '',
        'activo' => '1', 'usuario_id' => '', 'especialidades' => [(string) $specId[$name]]], $admin);
    $log['medicos']++;
    foreach (range(1, 5) as $day) {
        foreach ([['08:00', '12:00'], ['14:00', '18:00']] as [$i, $f]) {
            ScheduleService::save(['medico_id' => (string) $id, 'dia_semana' => (string) $day, 'hora_inicio' => $i, 'hora_fin' => $f, 'activo' => '1'], $admin);
            $log['horarios']++;
        }
    }
}

// Médicos activos con alguna especialidad oficial activa: son los que reciben citas.
$officialIds = array_values(array_intersect_key($specId, array_filter($specActive)));
$loadDoctors = function () use ($rows, $officialIds): array {
    $marks = implode(',', array_fill(0, max(1, count($officialIds)), '?'));
    $docs = [];
    foreach ($rows("SELECT m.id,m.usuario_id,me.especialidad_id FROM medico m JOIN medico_especialidad me ON me.medico_id=m.id AND me.activo
        JOIN especialidad e ON e.id=me.especialidad_id AND e.activo WHERE m.activo AND e.id IN ($marks) ORDER BY m.id,e.id", $officialIds ?: [0]) as $r) {
        $docs[(int) $r['id']]['id'] = (int) $r['id']; $docs[(int) $r['id']]['usuario_id'] = $r['usuario_id'];
        $docs[(int) $r['id']]['esp'][] = (int) $r['especialidad_id'];
    }
    return array_values($docs);
};
$docs = $loadDoctors();

// Cuentas de personal (solo con una DEMO_PASSWORD que cumpla la política).
$actor = $recep;
if ($passwordOk) {
    foreach ($staffAccounts as [$username, $role]) {
        if ($role === 'medico') { continue; }
        $uid = $newAccount($username, $role);
        if ($role === 'recepcionista' && !$actor) { $actor = $uid; }
    }
    // Cuentas de médico: cada una se vincula a un médico activo que todavía no tenga cuenta.
    foreach ($staffAccounts as [$username, $role]) {
        if ($role !== 'medico') { continue; }
        $uid = $newAccount($username, $role);
        if ($one('SELECT id FROM medico WHERE usuario_id=?', [$uid])) { continue; }
        $free = array_values(array_filter($docs, fn($d) => $d['usuario_id'] === null));
        if (!$free) { echo "AVISO: no hay un médico sin cuenta para vincular a $username.\n"; continue; }
        $doctor = DoctorService::find($free[0]['id']);
        $values = DoctorService::formValues($doctor);
        foreach (DoctorService::FIELDS as $field) { $values[$field] = (string) ($doctor[$field] ?? ''); }
        $values['activo'] = '1'; $values['usuario_id'] = (string) $uid; $values['version'] = DoctorService::version($doctor);
        DoctorService::save($values, $admin, $free[0]['id']);
        $log['vinculadas']++;
        $docs = $loadDoctors();
    }
}
$recep = $actor;

// Pacientes registrados por recepción (CU06). Datos inventados.
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

// Dos cuentas de paciente para la demostración (CU01), solo con una DEMO_PASSWORD válida.
$accounts = [];
if ($passwordOk) {
    foreach ($patientAccounts as [$username, $ci, $n, $a, $t]) {
        $uid = $newAccount($username, 'paciente', ['ci' => $ci, 'nombres' => $n, 'apellidos' => $a, 'telefono' => $t]);
        $accounts[$username] = $uid;
        $pat[$ci] = (int) $one('SELECT id FROM paciente WHERE usuario_id=?', [$uid]);
    }
}

// Citas en los próximos días hábiles (desde mañana): una por médico y día, hasta TARGET_APPOINTMENTS en total.
// Si un turno está ocupado se prueba con otra hora; si el médico no tiene turno ese día se omite.
$days = []; $d = new DateTimeImmutable('tomorrow', $tz);
while (count($days) < BUSINESS_DAYS) { if ((int) $d->format('N') <= 5) { $days[] = $d->format('Y-m-d'); } $d = $d->modify('+1 day'); }
$hours = ['08:30', '09:00', '09:30', '10:30', '11:00', '14:30', '15:00', '16:00', '16:30', '17:00'];
$patIds = array_values(array_filter($pat)); $made = []; $k = 0;
if ($already || !$patIds) { $days = []; }
foreach ($days as $di => $day) {
    foreach ($docs as $doc) {
        if (count($made) >= TARGET_APPOINTMENTS) { break 2; }
        $booked = false;
        for ($t = 0; $t < count($hours) && !$booked; $t++) {
            $specialty = $doc['esp'][($k + $t) % count($doc['esp'])];
            try {
                $made[] = AppointmentService::book(['especialidad_id' => (string) $specialty, 'medico_id' => (string) $doc['id'],
                    'inicio' => $day . 'T' . $hours[($di * 3 + $k + $t) % count($hours)], 'motivo' => $motives[($k + $t) % count($motives)],
                    'paciente_id' => (string) $patIds[($k + $t) % count($patIds)]], $recep);
                $log['citas']++; $booked = true;
            } catch (Throwable $ex) { /* turno ocupado o fuera de horario: se prueba otra hora */ }
        }
        if (!$booked) { $log['omitidas']++; }
        $k++;
    }
}
// Algunos cambios de estado para que el sistema muestre actividad: confirmaciones y cancelaciones.
$ra = AppointmentService::actor($recep);
$v = fn(int $id) => AppointmentService::version(AppointmentService::find($id, $ra));
foreach (array_slice($made, 0, 8) as $i => $id) {
    try { if ($i % 2 === 0) { AppointmentService::confirm($id, ['version' => $v($id)], $recep); } } catch (Throwable $ex) {}
}
foreach (array_slice($made, -3) as $id) {
    try { AppointmentService::cancel($id, ['version' => $v($id), 'motivo_cambio' => 'El paciente avisó que no podrá asistir', 'origen_cambio' => 'paciente'], $recep); } catch (Throwable $ex) {}
}
// Solicitudes urgentes de las cuentas demo (CU21), solo si todavía no tienen una pendiente.
$urgent = ['paciente.demo1' => ['Fiebre alta desde anoche y dolor de garganta; necesito una consulta hoy si es posible.', '62345678'],
           'paciente.demo2' => ['Dolor fuerte de muela que no me deja dormir.', '63456789']];
foreach ($urgent as $username => [$motivo, $contact]) {
    if (!isset($accounts[$username])) { continue; }
    $pending = (int) $one("SELECT count(*) FROM solicitud_urgente WHERE paciente_id=? AND estado='pendiente'",
        [(int) $one('SELECT id FROM paciente WHERE usuario_id=?', [$accounts[$username]])]);
    if (!$pending) {
        try { UrgentService::create(['motivo' => $motivo, 'contacto' => $contact, 'clave_envio' => bin2hex(random_bytes(16))], $accounts[$username]); } catch (Throwable $ex) {}
    }
}
echo PHP_EOL;
foreach ($log as $label => $n) { echo str_pad($label, 16) . $n . PHP_EOL; }
echo 'Totales en la base: ' . $one('SELECT count(*) FROM especialidad WHERE activo') . ' especialidades, '
    . $one('SELECT count(*) FROM medico WHERE activo') . ' médicos, ' . $one('SELECT count(*) FROM paciente') . ' pacientes, '
    . $one('SELECT count(*) FROM cita') . ' citas.' . PHP_EOL;
