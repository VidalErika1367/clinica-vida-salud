<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap.php';
try {
    if (version_compare(PHP_VERSION, '8.2', '<') || !extension_loaded('mbstring')) { throw new RuntimeException('Se requiere PHP 8.2 o superior con mbstring y pdo_pgsql.'); }
    if (!is_file(BASE_PATH . '/.env') && getenv('DB_NAME') === false) { throw new RuntimeException('Primero copia .env.example como .env y completa la conexión.'); }
    $files = App\Migrator::apply();
    foreach ($files as $file) { echo '[OK] ' . $file . PHP_EOL; }
    if (!$files) { echo '[OK] La base ya está actualizada.' . PHP_EOL; }
    echo 'Siguiente paso: php bin/crear_admin.php' . PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR, 'No se completó la instalación: ' . $e->getMessage() . PHP_EOL); exit(1); }
