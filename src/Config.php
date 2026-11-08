<?php
declare(strict_types=1);
namespace App;

/**
 * Lectura de configuración desde .env y variables de entorno. Lo usan todas las capas de la aplicación.
 * Porqué: en el alojamiento las variables del servidor tienen prioridad sobre el archivo .env.
 */
final class Config
{
    /** Valores leídos del archivo .env. */
    private static array $values = [];
    /**
     * Carga el archivo .env (ignora líneas vacías y comentarios; quita comillas envolventes).
     *
     * @param string $file ruta del archivo .env
     * @return void
     */
    public static function load(string $file): void
    {
        if (!is_file($file)) { return; }
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) { continue; }
            if (!preg_match('/^([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $m)) { continue; }
            $value = trim($m[2]);
            if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], true) && substr($value, -1) === $value[0]) {
                $value = substr($value, 1, -1);
            }
            self::$values[$m[1]] = $value;
        }
    }
    /**
     * Devuelve una variable: primero el entorno del proceso y después el .env.
     *
     * @param string $key nombre de la variable
     * @param string $default valor si no existe en ninguna fuente
     * @return string valor
     */
    public static function get(string $key, string $default = ''): string
    {
        $external = getenv($key);
        return $external === false ? (self::$values[$key] ?? $default) : $external;
    }
    /**
     * Lee una variable booleana ("true", "1", "on", etc.).
     *
     * @param string $key nombre de la variable
     * @param bool $default valor si no existe
     * @return bool
     */
    public static function bool(string $key, bool $default = false): bool
    {
        return filter_var(self::get($key, $default ? 'true' : 'false'), FILTER_VALIDATE_BOOL);
    }
}
