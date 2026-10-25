<?php
declare(strict_types=1);
namespace App;

final class Controller
{
    private static function guest(): void { if (Auth::current()) { redirect('/panel'); } }
    public static function loginForm(): void
    {
        self::guest(); View::render('login', ['title' => 'Iniciar sesión']);
    }
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
    public static function registerForm(): void
    {
        self::guest(); UserService::requireRegistrationAllowed();
        View::render('register', ['title' => 'Crear cuenta de paciente']);
    }
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
    public static function logout(): void { Session::logout(); redirect('/ingresar'); }
    public static function dashboard(): void
    {
        $user = Auth::requireUser(); $stats = [];
        if ($user['rol'] === 'administrador') {
            $stats = UserService::statistics();
        }
        View::render('dashboard', ['title' => 'Mi panel', 'user' => $user, 'stats' => $stats]);
    }
    public static function users(): void
    {
        Auth::requireAdmin('CU04');
        $query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
        $users = UserService::search($query);
        View::render('users', ['title' => 'Usuarios', 'users' => $users, 'query' => $query]);
    }
    public static function userForm(?int $id = null): void
    {
        Auth::requireAdmin('CU04');
        View::render('user_form', ['title' => $id ? 'Editar usuario' : 'Nuevo usuario', 'editing' => $id,
            'old' => $id ? UserService::find($id) : ['habilitado' => true], 'roles' => UserService::roles()]);
    }
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
    public static function roles(): void
    {
        Auth::requireAdmin('CU05');
        View::render('roles', RoleService::viewData());
    }
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
}
