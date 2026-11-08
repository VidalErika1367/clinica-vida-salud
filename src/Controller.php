<?php
declare(strict_types=1);
namespace App;

/**
 * Controlador general: ingreso (CU02), registro público de pacientes (CU01), cierre de sesión (CU03),
 * panel, ubicación de la clínica, usuarios (CU04) y roles y permisos (CU05).
 */
final class Controller
{
    /** Redirige al panel si ya hay sesión; evita mostrar ingreso o registro a un usuario autenticado. */
    private static function guest(): void { if (Auth::current()) { redirect('/panel'); } }
    /**
     * Muestra el formulario de ingreso (CU02).
     *
     * @return void
     */
    public static function loginForm(): void
    {
        self::guest(); View::render('login', ['title' => 'Iniciar sesión']);
    }
    /**
     * Procesa el ingreso (CU02). Aplica un límite de intentos por dirección e identidad.
     * Porqué: el contador va antes de verificar la contraseña para que los intentos fallidos
     * no puedan probarse sin límite. Un ingreso correcto limpia el contador.
     *
     * @return void
     */
    public static function login(): void
    {
        self::guest();
        $identity = mb_strtolower(input('identidad'));
        $key = ($_SERVER['REMOTE_ADDR'] ?? 'local') . '|' . mb_substr($identity, 0, 160);
        if (!RateLimiter::claim($key)) {
            View::render('login', ['title' => 'Iniciar sesión', 'old' => ['identidad' => $identity],
                'errors' => ['general' => 'Se alcanzó el límite de intentos. Espera 15 minutos antes de volver a ingresar.']], 429); return;
        }
        if (strlen($identity) <= 160 && strlen(raw_password()) <= 72 && Auth::attempt($identity, raw_password())) {
            RateLimiter::clear($key); redirect('/panel');
        }
        View::render('login', ['title' => 'Iniciar sesión', 'old' => ['identidad' => $identity],
            'errors' => ['general' => 'No se pudo iniciar sesión. Revisa tus credenciales o consulta en recepción.']], 422);
    }
    /**
     * Muestra el formulario público de registro de pacientes (CU01).
     *
     * @return void
     * @throws HttpException 403 si el registro público está deshabilitado en la matriz de permisos
     */
    public static function registerForm(): void
    {
        self::guest(); UserService::requireRegistrationAllowed();
        View::render('register', ['title' => 'Crear cuenta de paciente']);
    }
    /**
     * Toma los campos de cuenta del formulario. Las contraseñas se leen sin normalizar.
     *
     * @return array datos de la cuenta
     */
    private static function data(): array
    {
        $data = [];
        foreach (['nombre_usuario','correo','rol_id','ci','nombres','apellidos','telefono'] as $key) { $data[$key] = input($key); }
        $data['password'] = raw_password(); $data['password_confirmation'] = raw_password('password_confirmation');
        $data['habilitado'] = input('habilitado') === '1';
        return $data;
    }
    private static function safeOld(array $data): array
    {
        unset($data['password'], $data['password_confirmation']); return $data;
    }
    /**
     * Registra una cuenta de paciente desde el formulario público (CU01).
     * El rol siempre es "paciente": el campo rol_id del formulario se ignora.
     *
     * @return void
     * @throws HttpException 403 si el registro público está deshabilitado
     */
    public static function register(): void
    {
        self::guest(); $data = self::data();
        try {
            UserService::save($data, public: true);
            Session::flash('Tu cuenta de paciente está lista. Ya puedes iniciar sesión.'); redirect('/ingresar');
        } catch (ValidationException $e) {
            View::render('register', ['title' => 'Crear cuenta de paciente', 'errors' => $e->errors, 'old' => self::safeOld($data)], 422);
        }
    }
    /**
     * Cierra la sesión (CU03). Solo acepta POST con token CSRF, validado en public/index.php.
     *
     * @return void
     */
    public static function logout(): void { Session::logout(); redirect('/ingresar'); }
    /**
     * Muestra el panel principal. Los administradores ven estadísticas globales.
     *
     * @return void
     */
    public static function dashboard(): void
    {
        $user = Auth::requireUser(); $stats = [];
        if ($user['rol'] === 'administrador') {
            $stats = UserService::statistics();
        }
        View::render('dashboard', ['title' => 'Mi panel', 'user' => $user, 'stats' => $stats]);
    }
    /**
     * Muestra la dirección y el mapa de la clínica a cualquier usuario con sesión.
     *
     * @return void
     */
    public static function location(): void
    {
        $user=Auth::requireUser();
        View::render('clinic_location',['title'=>'Ubicación de la clínica','section'=>'ubicacion',
            'user'=>$user,'location'=>ClinicInfo::location()]);
    }
    /**
     * Lista usuarios con búsqueda por texto y filtro por rol (CU04). Solo administradores.
     * El rol llega por GET (?rol=codigo) para poder compartir el enlace; un valor que no sea un rol existente se ignora.
     *
     * @return void
     * @throws HttpException 403 sin permiso CU04
     */
    public static function users(): void
    {
        Auth::requireAdmin('CU04');
        $query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
        $roles = UserService::roles();
        $roleCode = UserService::roleFilter($_GET['rol'] ?? null, $roles);
        $users = UserService::search($query, $roleCode);
        View::render('users', ['title' => 'Usuarios', 'users' => $users, 'query' => $query, 'roles' => $roles, 'roleCode' => $roleCode]);
    }
    /**
     * Muestra el formulario de alta o edición de un usuario (CU04).
     *
     * @param int|null $id usuario a editar; null para un alta nueva
     * @param array $resetErrors errores del formulario de restablecimiento de contraseña, si se acaba de enviar
     * @return void
     * @throws HttpException 403 sin permiso CU04; 404 si el usuario no existe
     */
    public static function userForm(?int $id = null, array $resetErrors = []): void
    {
        Auth::requireAdmin('CU04');
        View::render('user_form', ['title' => $id ? 'Editar usuario' : 'Nuevo usuario', 'editing' => $id,
            'old' => $id ? UserService::find($id) : ['habilitado' => true], 'roles' => UserService::roles(),
            'resetErrors' => $resetErrors], $resetErrors ? 422 : 200);
    }
    /**
     * Guarda el alta o cambio de un usuario (CU04). Revalida la cuenta en el servicio.
     *
     * @param int|null $id usuario a modificar; null para un alta
     * @return void
     * @throws HttpException 403 sin permiso CU04; 404 si el usuario a editar no existe
     */
    public static function saveUser(?int $id = null): void
    {
        Auth::requireAdmin('CU04');
        if ($id !== null) { UserService::find($id); }
        $data = self::data();
        try {
            UserService::save($data, $id);
            Session::flash($id ? 'Los cambios del usuario se guardaron.' : 'El usuario fue registrado.'); redirect('/usuarios');
        } catch (ValidationException $e) {
            View::render('user_form', ['title' => $id ? 'Editar usuario' : 'Nuevo usuario', 'editing' => $id,
                'old' => self::safeOld($data), 'errors' => $e->errors, 'roles' => UserService::roles()], 422);
        }
    }
    /**
     * Muestra la matriz de permisos por rol (CU05). Solo administradores.
     *
     * @return void
     * @throws HttpException 403 sin permiso CU05
     */
    public static function roles(): void
    {
        Auth::requireAdmin('CU05');
        View::render('roles', RoleService::viewData());
    }
    /**
     * Guarda la matriz de permisos (CU05). Los cambios aplican desde la siguiente solicitud.
     *
     * @return void
     * @throws HttpException 403 sin permiso CU05
     */
    public static function saveRoles(): void
    {
        $user = Auth::requireAdmin('CU05');
        try {
            $changes = RoleService::saveMatrix($_POST, (int) $user['id']);
            Session::flash($changes > 0 ? 'Los permisos se guardaron. Se aplicarán en las siguientes solicitudes.'
                : 'La matriz ya estaba actualizada; no hubo cambios.');
            redirect('/roles');
        } catch (ValidationException $e) {
            View::render('roles', RoleService::viewData() + ['errors' => $e->errors], 422);
        }
    }
    /**
     * Restablece la contraseña de cualquier cuenta con una contraseña temporal (amplía CU04). Solo administradores.
     * La cuenta deberá cambiarla al ingresar y sus sesiones abiertas dejan de ser válidas.
     *
     * @param int $id cuenta a restablecer
     * @return void
     * @throws HttpException 403 sin permiso CU04; 404 si la cuenta no existe
     */
    public static function resetUserPassword(int $id): void
    {
        $actor = Auth::requireAdmin('CU04');
        try {
            UserService::restablecer($id, ['password' => raw_password(), 'password_confirmation' => raw_password('password_confirmation')], $actor, false);
            Session::flash('Contraseña temporal guardada. La persona deberá cambiarla al ingresar y sus sesiones abiertas se cerraron.');
            redirect('/usuarios/' . $id . '/editar');
        } catch (ValidationException $e) {
            self::userForm($id, $e->errors);
        }
    }
    /**
     * Página pública "¿Olvidaste tu contraseña?" (amplía CU02): explica que recepción restablece la contraseña
     * y ofrece los contactos de WhatsApp con un mensaje prellenado.
     *
     * @return void
     */
    public static function forgotPassword(): void
    {
        self::guest();
        View::render('forgot_password', ['title' => 'Olvidé mi contraseña', 'user' => null,
            'contacts' => ReceptionContact::whatsapp(ReceptionContact::FORGOT_MESSAGE)]);
    }
    /**
     * Cuenta con contraseña temporal autenticada, o null si no hay sesión o no tiene nada que cambiar.
     * Sin sesión redirige al ingreso; sin contraseña temporal pendiente, al panel.
     *
     * @return array usuario autenticado con contraseña temporal pendiente
     */
    private static function temporaryAccount(): array
    {
        $user = Auth::requireUser();
        if (!$user['debe_cambiar_password']) { redirect('/panel'); }
        return $user;
    }
    /**
     * Muestra "Cambiar contraseña", la única página disponible mientras la contraseña sea temporal (amplía CU02).
     *
     * @param array $errors errores del envío anterior
     * @return void
     */
    public static function passwordChangeForm(array $errors = []): void
    {
        $user = self::temporaryAccount();
        View::render('change_password', ['title' => 'Cambiar contraseña', 'user' => null, 'errors' => $errors,
            'account' => $user['nombre_usuario']], $errors ? 422 : 200);
    }
    /**
     * Guarda la contraseña nueva (distinta de la temporal), quita la obligación de cambiarla y regenera la sesión.
     *
     * @return void
     */
    public static function passwordChange(): void
    {
        $user = self::temporaryAccount();
        try {
            $hash = UserService::changeTemporaryPassword($user, ['password' => raw_password(), 'password_confirmation' => raw_password('password_confirmation')]);
        } catch (ValidationException $e) {
            self::passwordChangeForm($e->errors); return;
        }
        Session::login((int) $user['id'], $hash);
        Session::flash('Contraseña actualizada. Ya puedes usar el sistema.');
        redirect('/panel');
    }
}
