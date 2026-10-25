<?php
declare(strict_types=1);
namespace App;

final class Config
{
    private static array $values = [];
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
    public static function get(string $key, string $default = ''): string
    {
        $external = getenv($key);
        return $external === false ? (self::$values[$key] ?? $default) : $external;
    }
    public static function bool(string $key, bool $default = false): bool
    {
        return filter_var(self::get($key, $default ? 'true' : 'false'), FILTER_VALIDATE_BOOL);
    }
}
