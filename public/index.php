<?php
declare(strict_types=1);
// Punto de entrada HTTP: enruta cada petición a su controlador y muestra las pantallas de error.
require dirname(__DIR__) . '/bootstrap.php';
use App\Controller;
use App\PatientController;
use App\ProfileController;
use App\DoctorController;
use App\SpecialtyController;
use App\ScheduleController;
use App\AvailabilityController;
use App\AppointmentController;
use App\AppointmentReadController;
use App\UrgentController;
use App\FineController;
use App\AbsenceController;
use App\CommunicationController;
use App\HttpException;
use App\Session;
use App\View;

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($method === 'POST' && $path === '/salir' && !isset($_SESSION['uid'])) { redirect('/ingresar'); }
    // Webhook de Telegram: se autentica con su secreto, no con sesión ni token CSRF (CU27).
    if ($method==='POST' && $path==='/telegram/webhook') { CommunicationController::webhook(); exit; }
    // Todo formulario POST exige el token CSRF de la sesión.
    if ($method === 'POST') { Session::verifyToken($_POST['csrf'] ?? null); }
    // Rutas exactas "MÉTODO /ruta" agrupadas por módulo y caso de uso.
    $routes = [
        // Acceso y sesión: ingreso (CU02), registro público de pacientes (CU01) y cierre de sesión (CU03).
        'GET /' => [Controller::class, 'loginForm'],
        'GET /ingresar' => [Controller::class, 'loginForm'],
        'POST /sesion' => [Controller::class, 'login'],
        'GET /registro' => [Controller::class, 'registerForm'],
        'POST /registro' => [Controller::class, 'register'],
        'POST /salir' => [Controller::class, 'logout'],
        // Restablecimiento de contraseña con apoyo de recepción (amplía CU02): página pública y cambio obligatorio.
        'GET /olvide-password' => [Controller::class, 'forgotPassword'],
        'GET /cambiar-password' => [Controller::class, 'passwordChangeForm'],
        'POST /cambiar-password' => [Controller::class, 'passwordChange'],
        // Panel principal y ubicación de la clínica (sin CU propio).
        'GET /panel' => [Controller::class, 'dashboard'],
        'GET /ubicacion' => [Controller::class, 'location'],
        // Perfil de salud del paciente (CU07).
        'GET /mi-perfil' => [ProfileController::class, 'form'],
        'POST /mi-perfil' => [ProfileController::class, 'save'],
        // Médicos (CU08) y especialidades (CU09).
        'GET /medicos' => [DoctorController::class, 'index'],
        'GET /medicos/nuevo' => [DoctorController::class, 'form'],
        'POST /medicos/nuevo' => [DoctorController::class, 'save'],
        'GET /especialidades' => [SpecialtyController::class, 'index'],
        'GET /especialidades/nueva' => [SpecialtyController::class, 'form'],
        'POST /especialidades/nueva' => [SpecialtyController::class, 'save'],
        // Horarios de atención (CU10) y consulta de disponibilidad (CU11).
        'GET /horarios' => [ScheduleController::class, 'index'],
        'GET /horarios/nuevo' => [ScheduleController::class, 'form'],
        'POST /horarios/nuevo' => [ScheduleController::class, 'save'],
        'GET /disponibilidad' => [AvailabilityController::class, 'index'],
        // Citas: listado (CU12 a CU17 según el rol) y programación (CU12).
        'GET /citas' => [AppointmentController::class, 'index'],
        'GET /citas/nueva' => [AppointmentController::class, 'bookingForm'],
        'POST /citas/nueva' => [AppointmentController::class, 'book'],
        // Agenda médica (CU18), historial de citas (CU19) y reportes administrativos (CU20).
        'GET /agenda' => static fn()=>AppointmentReadController::show('agenda'),
        'GET /historial' => static fn()=>AppointmentReadController::show('historial'),
        'GET /reportes' => static fn()=>AppointmentReadController::show('reportes'),
        // Citas urgentes: solicitud por el paciente (CU21) y gestión por recepción (CU22).
        'GET /urgencias' => [UrgentController::class, 'index'],
        'GET /urgencias/nueva' => [UrgentController::class, 'form'],
        'POST /urgencias/nueva' => [UrgentController::class, 'create'],
        // Advertencias y multas (CU24) y registro de pagos de multa (CU23).
        'GET /multas' => [FineController::class, 'index'],
        'GET /pagos' => static fn()=>FineController::index(true),
        // Ausencias médicas (CU26).
        'GET /ausencias' => [AbsenceController::class, 'index'],
        'GET /ausencias/nueva' => [AbsenceController::class, 'form'],
        'POST /ausencias/nueva' => [AbsenceController::class, 'create'],
        // Vinculación de avisos por Telegram (CU27), avisos del sistema (CU28) y contacto con recepción (CU30).
        'GET /telegram' => [CommunicationController::class, 'telegram'],
        'POST /telegram/activar' => [CommunicationController::class, 'issue'],
        'POST /telegram/desactivar' => [CommunicationController::class, 'unlink'],
        'GET /avisos' => [CommunicationController::class, 'notices'],
        'GET /contacto-recepcion' => [CommunicationController::class, 'contact'],
        // Reactivación de pacientes inactivos (CU25).
        'GET /reactivaciones' => [PatientController::class, 'reactivationIndex'],
        // Usuarios (CU04) y roles y permisos (CU05).
        'GET /usuarios' => [Controller::class, 'users'],
        'GET /usuarios/nuevo' => [Controller::class, 'userForm'],
        'POST /usuarios/nuevo' => [Controller::class, 'saveUser'],
        'GET /roles' => [Controller::class, 'roles'],
        'POST /roles' => [Controller::class, 'saveRoles'],
        // Pacientes: gestión administrativa (CU06).
        'GET /pacientes' => [PatientController::class, 'index'],
        'GET /pacientes/nuevo' => [PatientController::class, 'form'],
        'POST /pacientes/nuevo' => [PatientController::class, 'save'],
    ];
    // Una cuenta con contraseña temporal solo puede cambiarla o cerrar sesión.
    App\Auth::enforcePasswordChange($method, $path);
    // Búsqueda exacta primero; después, rutas con parámetros numéricos.
    $route = $routes[$method . ' ' . $path] ?? null;
    if ($route) { $route(); }
    // Contador de la cita (CU29) y reintento autorizado de un aviso (CU28).
    elseif ($method==='GET' && preg_match('#^/citas/([1-9][0-9]*)/contador$#',$path,$m)) { CommunicationController::counter((int) $m[1]); }
    elseif (preg_match('#^/avisos/([1-9][0-9]*)/reintentar$#',$path,$m) && $method==='POST') { CommunicationController::retry((int) $m[1]); }
    // Ausencias: detalle y gestión de cada cita afectada (CU26).
    elseif (preg_match('#^/ausencias/([1-9][0-9]*)(?:/citas/([1-9][0-9]*))?$#',$path,$m)) {
        if ($method==='GET' && !isset($m[2])) { AbsenceController::detail((int) $m[1]); }
        elseif ($method==='GET' && isset($m[2])) { AbsenceController::manageForm((int) $m[1],(int) $m[2]); }
        elseif ($method==='POST' && isset($m[2])) { AbsenceController::manage((int) $m[1],(int) $m[2]); }
        else { throw new HttpException(404,'No se encontró la página solicitada.'); }
    }
    // Edición de un usuario existente y restablecimiento de su contraseña por administración (CU04).
    elseif ($method === 'POST' && preg_match('#^/usuarios/([1-9][0-9]*)/restablecer$#', $path, $m)) { Controller::resetUserPassword((int) $m[1]); }
    elseif (preg_match('#^/usuarios/([1-9][0-9]*)/editar$#', $path, $m) && in_array($method, ['GET','POST'], true)) {
        $method === 'GET' ? Controller::userForm((int) $m[1]) : Controller::saveUser((int) $m[1]);
    } elseif (preg_match('#^/citas/([1-9][0-9]*)(?:/(confirmar|reprogramar|cancelar|atender|inasistencia))?$#',$path,$m)) {
        // Cita concreta: detalle (CU19, CU29), confirmación (CU13), reprogramación (CU14), cancelación (CU15), atención (CU16) e inasistencia (CU17).
        $id=(int) $m[1]; $action=$m[2] ?? '';
        if ($method==='GET' && $action==='') { AppointmentController::detail($id); }
        elseif ($method==='POST' && $action==='confirmar') { AppointmentController::confirm($id); }
        elseif ($method==='GET' && $action==='reprogramar') { AppointmentController::rescheduleForm($id); }
        elseif ($method==='POST' && $action==='reprogramar') { AppointmentController::reschedule($id); }
        elseif ($method==='GET' && $action==='cancelar') { AppointmentController::cancelForm($id); }
        elseif ($method==='POST' && $action==='cancelar') { AppointmentController::cancel($id); }
        elseif ($method==='GET' && in_array($action,['atender','inasistencia'],true)) { AppointmentController::finishForm($id,$action==='inasistencia'); }
        elseif ($method==='POST' && in_array($action,['atender','inasistencia'],true)) { AppointmentController::finish($id,$action==='inasistencia'); }
        else { throw new HttpException(404,'No se encontró la página solicitada.'); }
    // Edición de un horario de atención (CU10).
    } elseif (preg_match('#^/horarios/([1-9][0-9]*)/editar$#',$path,$m) && in_array($method,['GET','POST'],true)) {
        // Formulario (GET) y guardado (POST) del horario (CU10).
        $method==='GET' ? ScheduleController::form((int) $m[1]) : ScheduleController::save((int) $m[1]);
    } elseif (preg_match('#^/(medicos|especialidades)/([1-9][0-9]*)(/editar)?$#',$path,$m)) {
        // Ficha y edición de médico (CU08) o de especialidad (CU09), según el primer segmento de la ruta.
        $controller=$m[1] === 'medicos' ? DoctorController::class : SpecialtyController::class;
        $id=(int) $m[2]; $editing=isset($m[3]);
        if ($method === 'GET') { $editing ? $controller::form($id) : $controller::detail($id); }
        elseif ($method === 'POST' && $editing) { $controller::save($id); }
        else { throw new HttpException(404,'No se encontró la página solicitada.'); }
    } elseif (preg_match('#^/urgencias/([1-9][0-9]*)(/gestionar)?$#',$path,$m)) {
        // Detalle de la solicitud urgente (CU21, CU22) y su gestión por recepción (CU22).
        if ($method==='GET' && !isset($m[2])) { UrgentController::detail((int) $m[1]); }
        elseif ($method==='POST' && isset($m[2])) { UrgentController::manage((int) $m[1]); }
        else { throw new HttpException(404,'No se encontró la página solicitada.'); }
    } elseif (preg_match('#^/multas/([1-9][0-9]*)/pagar$#',$path,$m) && in_array($method,['GET','POST'],true)) {
        // Formulario (GET) y registro (POST) del pago de una multa (CU23).
        $method==='GET' ? FineController::form((int) $m[1]) : FineController::pay((int) $m[1]);
    } elseif (preg_match('#^/pacientes/([1-9][0-9]*)(?:/(editar|desactivar|reactivar|restablecer))?$#', $path, $m)) {
        // Ficha, edición (CU06), desactivación (CU06), reactivación administrativa (CU25) y restablecimiento de contraseña del paciente (CU06).
        $id = (int) $m[1]; $action = $m[2] ?? '';
        if ($method === 'GET' && $action === '') { PatientController::detail($id); }
        elseif ($method === 'GET' && $action === 'editar') { PatientController::form($id); }
        elseif ($method === 'POST' && $action === 'editar') { PatientController::save($id); }
        elseif ($method === 'POST' && $action === 'desactivar') { PatientController::deactivate($id); }
        elseif ($method === 'GET' && $action === 'reactivar') { PatientController::reactivateForm($id); }
        elseif ($method === 'POST' && $action === 'reactivar') { PatientController::reactivate($id); }
        elseif ($method === 'POST' && $action === 'restablecer') { PatientController::resetPassword($id); }
        else { throw new HttpException(404, 'No se encontró la página solicitada.'); }
    } else { throw new HttpException(404, 'No se encontró la página solicitada.'); }
} catch (HttpException $e) {
    View::render('error', ['title' => 'Revisa tu solicitud', 'message' => $e->getMessage(), 'code' => $e->status, 'user' => null], $e->status);
} catch (Throwable $e) {
    error_log('Vida Salud: ' . get_class($e) . ', código ' . $e->getCode());
    View::render('error', ['title' => 'No se pudo completar la solicitud', 'code' => 500, 'user' => null,
        'message' => 'No se pudo completar la operación. Inténtalo nuevamente o consulta con administración.'], 500);
}
