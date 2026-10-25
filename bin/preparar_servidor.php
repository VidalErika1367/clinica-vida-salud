<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap.php';

try {
    if (version_compare(PHP_VERSION, '8.2', '<') || !extension_loaded('mbstring') || !extension_loaded('pdo_pgsql')) {
        throw new RuntimeException('Se requiere PHP 8.2 o superior con mbstring y pdo_pgsql.');
    }
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
        if (App\Config::get($key) === '') { throw new RuntimeException('Falta configurar ' . $key . ' en el servidor.'); }
    }
    $migrations = App\Migrator::apply();
    foreach ($migrations as $name) { echo '[OK] ' . $name . PHP_EOL; }
    if (!$migrations) { echo '[OK] La base ya esta actualizada.' . PHP_EOL; }

    // Dos arranques simultáneos no deben crear dos administradores iniciales.
    $db = App\Database::connection();
    $db->exec('SELECT pg_advisory_lock(9384732)');
    try {
        if (App\Repositories\UserRepository::enabledAdministratorCount() > 0) {
            echo '[OK] Se conserva el administrador existente.' . PHP_EOL;
        } else {
            $values = [];
            foreach (['CLINICA_ADMIN_USER', 'CLINICA_ADMIN_EMAIL', 'CLINICA_ADMIN_PASSWORD'] as $key) {
                $value = getenv($key);
                if ($value === false || $value === '') {
                    throw new RuntimeException('Configura ' . $key . ' en Render para crear el primer administrador.');
                }
                $values[$key] = $value;
            }
            $role = App\Repositories\RoleRepository::byCode('administrador');
            App\UserService::save([
                'nombre_usuario' => $values['CLINICA_ADMIN_USER'],
                'correo' => $values['CLINICA_ADMIN_EMAIL'],
                'password' => $values['CLINICA_ADMIN_PASSWORD'],
                'password_confirmation' => $values['CLINICA_ADMIN_PASSWORD'],
                'rol_id' => (int) $role['id'],
                'habilitado' => true,
            ]);
            echo '[OK] Administrador inicial creado.' . PHP_EOL;
        }
    } finally {
        $db->exec('SELECT pg_advisory_unlock(9384732)');
    }
} catch (App\ValidationException $e) {
    foreach ($e->errors as $error) { fwrite(STDERR, $error . PHP_EOL); }
    exit(1);
} catch (Throwable $e) {
    // No imprimir las credenciales ni el contenido de las excepciones de conexión.
    $message = $e instanceof PDOException
        ? 'No se pudo preparar PostgreSQL. Revisa la conexion, permisos y disponibilidad de la base.'
        : $e->getMessage();
    fwrite(STDERR, 'No se inicio el servidor: ' . $message . PHP_EOL);
    exit(1);
}
