<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap.php';
function ask(string $label): string { fwrite(STDOUT, $label); return trim(fgets(STDIN) ?: ''); }
function askPassword(): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'powershell -NoProfile -Command "$p=Read-Host \'Contraseña del administrador\' -AsSecureString; $b=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($p); try {[Runtime.InteropServices.Marshal]::PtrToStringBSTR($b)} finally {[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b)}"';
        $password = shell_exec($command);
        if ($password === null) { throw new RuntimeException('No se pudo leer la contraseña con PowerShell.'); }
        return rtrim($password, "\r\n");
    }
    if (!stream_isatty(STDIN)) { throw new RuntimeException('Ejecuta este comando desde una terminal interactiva.'); }
    fwrite(STDOUT, 'Contraseña del administrador (no se mostrará): ');
    $mode = shell_exec('stty -g');
    if (!$mode) { throw new RuntimeException('No se pudo configurar la lectura de contraseña.'); }
    shell_exec('stty -echo');
    try { return rtrim(fgets(STDIN) ?: '', "\r\n"); }
    finally { shell_exec('stty ' . escapeshellarg(trim($mode))); fwrite(STDOUT, PHP_EOL); }
}
try {
    $count = App\Repositories\UserRepository::enabledAdministratorCount();
    if ($count > 0) { echo 'Ya existe un administrador habilitado. Para agregar cuentas, usa Usuarios dentro de la web.' . PHP_EOL; exit(0); }
    $username = getenv('CLINICA_ADMIN_USER');
    $email = getenv('CLINICA_ADMIN_EMAIL');
    $password = getenv('CLINICA_ADMIN_PASSWORD');
    $username = $username === false ? ask('Nombre de usuario del administrador: ') : $username;
    $email = $email === false ? ask('Correo del administrador: ') : $email;
    $password = $password === false ? askPassword() : $password;
    $role = (int) App\Repositories\RoleRepository::byCode('administrador')['id'];
    App\UserService::save(['nombre_usuario'=>$username,'correo'=>$email,'password'=>$password,'password_confirmation'=>$password,'rol_id'=>$role,'habilitado'=>true]);
    echo 'Administrador creado. Ya puedes iniciar sesión con tu usuario y contraseña.' . PHP_EOL;
} catch (App\ValidationException $e) { foreach ($e->errors as $error) { fwrite(STDERR, $error . PHP_EOL); } exit(1); }
catch (Throwable $e) { fwrite(STDERR, 'No se pudo crear el administrador: ' . $e->getMessage() . PHP_EOL); exit(1); }
