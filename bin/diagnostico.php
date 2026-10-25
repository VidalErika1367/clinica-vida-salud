<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap.php';
$errors = 0;
$report = function (string $label, bool $ok, string $detail = '') use (&$errors): void {
    echo ($ok ? '[OK] ' : '[FALTA] ') . $label . ($detail !== '' ? ': ' . $detail : '') . PHP_EOL;
    if (!$ok) { $errors++; }
};
$report('PHP 8.2 o superior', version_compare(PHP_VERSION, '8.2', '>='), PHP_VERSION);
foreach (['pdo', 'pdo_pgsql', 'mbstring', 'session', 'openssl'] as $extension) { $report('Extensión ' . $extension, extension_loaded($extension)); }
$report('Archivo .env', is_file(BASE_PATH . '/.env') || getenv('DB_NAME') !== false, 'Copia .env.example como .env y completa tus datos.');
$report('Carpeta storage con escritura', is_writable(BASE_PATH . '/storage'));
if (extension_loaded('pdo_pgsql')) {
    try {
        $version = App\Database::run('SHOW server_version')->fetchColumn();
        $report('Conexión con PostgreSQL', true, (string) $version);
        $report('Tablas del sistema', (bool) App\Database::run("SELECT to_regclass('public.usuario') IS NOT NULL")->fetchColumn(), 'Si faltan, ejecuta php bin/instalar.php');
    } catch (Throwable $e) { $report('Conexión con PostgreSQL', false, 'Revisa que esté iniciado y que los datos de .env sean correctos.'); }
}
exit($errors ? 1 : 0);
