<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use App\Controller;
use App\PatientController;
use App\ProfileController;
use App\DoctorController;
use App\SpecialtyController;
use App\ScheduleController;
use App\AvailabilityController;
use App\AppointmentController;
use App\HttpException;
use App\Session;
use App\View;

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($method === 'POST' && $path === '/salir' && !isset($_SESSION['uid'])) { redirect('/ingresar'); }
    if ($method === 'POST') { Session::verifyToken($_POST['csrf'] ?? null); }
    $routes = [
        'GET /' => [Controller::class, 'loginForm'],
        'GET /ingresar' => [Controller::class, 'loginForm'],
        'POST /sesion' => [Controller::class, 'login'],
        'GET /registro' => [Controller::class, 'registerForm'],
        'POST /registro' => [Controller::class, 'register'],
        'POST /salir' => [Controller::class, 'logout'],
        'GET /panel' => [Controller::class, 'dashboard'],
        'GET /mi-perfil' => [ProfileController::class, 'form'],
        'POST /mi-perfil' => [ProfileController::class, 'save'],
        'GET /medicos' => [DoctorController::class, 'index'],
        'GET /medicos/nuevo' => [DoctorController::class, 'form'],
        'POST /medicos/nuevo' => [DoctorController::class, 'save'],
        'GET /especialidades' => [SpecialtyController::class, 'index'],
        'GET /especialidades/nueva' => [SpecialtyController::class, 'form'],
        'POST /especialidades/nueva' => [SpecialtyController::class, 'save'],
        'GET /horarios' => [ScheduleController::class, 'index'],
        'GET /horarios/nuevo' => [ScheduleController::class, 'form'],
        'POST /horarios/nuevo' => [ScheduleController::class, 'save'],
        'GET /disponibilidad' => [AvailabilityController::class, 'index'],
        'GET /citas' => [AppointmentController::class, 'index'],
        'GET /citas/nueva' => [AppointmentController::class, 'bookingForm'],
        'POST /citas/nueva' => [AppointmentController::class, 'book'],
        'GET /usuarios' => [Controller::class, 'users'],
        'GET /usuarios/nuevo' => [Controller::class, 'userForm'],
        'POST /usuarios/nuevo' => [Controller::class, 'saveUser'],
        'GET /roles' => [Controller::class, 'roles'],
        'POST /roles' => [Controller::class, 'saveRoles'],
        'GET /pacientes' => [PatientController::class, 'index'],
        'GET /pacientes/nuevo' => [PatientController::class, 'form'],
        'POST /pacientes/nuevo' => [PatientController::class, 'save'],
    ];
    $route = $routes[$method . ' ' . $path] ?? null;
    if ($route) { $route(); }
    elseif (preg_match('#^/usuarios/([1-9][0-9]*)/editar$#', $path, $m) && in_array($method, ['GET','POST'], true)) {
        $method === 'GET' ? Controller::userForm((int) $m[1]) : Controller::saveUser((int) $m[1]);
    } elseif (preg_match('#^/citas/([1-9][0-9]*)(?:/(confirmar|reprogramar|cancelar))?$#',$path,$m)) {
        $id=(int) $m[1]; $action=$m[2] ?? '';
        if ($method==='GET' && $action==='') { AppointmentController::detail($id); }
        elseif ($method==='POST' && $action==='confirmar') { AppointmentController::confirm($id); }
        elseif ($method==='GET' && $action==='reprogramar') { AppointmentController::rescheduleForm($id); }
        elseif ($method==='POST' && $action==='reprogramar') { AppointmentController::reschedule($id); }
        elseif ($method==='GET' && $action==='cancelar') { AppointmentController::cancelForm($id); }
        elseif ($method==='POST' && $action==='cancelar') { AppointmentController::cancel($id); }
        else { throw new HttpException(404,'No se encontró la página solicitada.'); }
    } elseif (preg_match('#^/horarios/([1-9][0-9]*)/editar$#',$path,$m) && in_array($method,['GET','POST'],true)) {
        $method==='GET' ? ScheduleController::form((int) $m[1]) : ScheduleController::save((int) $m[1]);
    } elseif (preg_match('#^/(medicos|especialidades)/([1-9][0-9]*)(/editar)?$#',$path,$m)) {
        $controller=$m[1] === 'medicos' ? DoctorController::class : SpecialtyController::class;
        $id=(int) $m[2]; $editing=isset($m[3]);
        if ($method === 'GET') { $editing ? $controller::form($id) : $controller::detail($id); }
        elseif ($method === 'POST' && $editing) { $controller::save($id); }
        else { throw new HttpException(404,'No se encontró la página solicitada.'); }
    } elseif (preg_match('#^/pacientes/([1-9][0-9]*)(?:/(editar|desactivar))?$#', $path, $m)) {
        $id = (int) $m[1]; $action = $m[2] ?? '';
        if ($method === 'GET' && $action === '') { PatientController::detail($id); }
        elseif ($method === 'GET' && $action === 'editar') { PatientController::form($id); }
        elseif ($method === 'POST' && $action === 'editar') { PatientController::save($id); }
        elseif ($method === 'POST' && $action === 'desactivar') { PatientController::deactivate($id); }
        else { throw new HttpException(404, 'No se encontró la página solicitada.'); }
    } else { throw new HttpException(404, 'No se encontró la página solicitada.'); }
} catch (HttpException $e) {
    View::render('error', ['title' => 'Revisa tu solicitud', 'message' => $e->getMessage(), 'code' => $e->status, 'user' => null], $e->status);
} catch (Throwable $e) {
    error_log('Vida Salud: ' . get_class($e) . ', código ' . $e->getCode());
    View::render('error', ['title' => 'No se pudo completar la solicitud', 'code' => 500, 'user' => null,
        'message' => 'No se pudo completar la operación. Inténtalo nuevamente o consulta con administración.'], 500);
}
