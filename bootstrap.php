<?php
declare(strict_types=1);

define('BASE_PATH', __DIR__);
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $path = BASE_PATH . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) { require $path; }
    }
});
require BASE_PATH . '/src/helpers.php';
App\Config::load(BASE_PATH . '/.env');
date_default_timezone_set(App\Config::get('APP_TIMEZONE', 'America/La_Paz'));
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (PHP_SAPI !== 'cli') {
    App\Session::start();
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    header('Cache-Control: no-store, private');
    header('Content-Type: text/html; charset=UTF-8');
}
