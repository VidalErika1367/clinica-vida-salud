<?php
declare(strict_types=1);
namespace App;

final class RateLimiter
{
    private static function change(string $key, bool $clear): bool
    {
        $path = BASE_PATH . '/storage/acceso-' . hash('sha256', $key) . '.json';
        $file = fopen($path, 'c+');
        if (!$file || !flock($file, LOCK_EX)) { throw new \RuntimeException('No se puede registrar el control de acceso.'); }
        try {
            $state = json_decode(stream_get_contents($file) ?: '{}', true) ?: [];
            if ($clear || (int) ($state['until'] ?? 0) <= time()) { $state = ['attempts' => 0, 'until' => time() + 900]; }
            $allowed = (int) $state['attempts'] < 8;
            if (!$clear && $allowed) { $state['attempts']++; }
            rewind($file); ftruncate($file, 0); fwrite($file, json_encode($state, JSON_THROW_ON_ERROR)); fflush($file);
            return $allowed;
        } finally { flock($file, LOCK_UN); fclose($file); }
    }
    public static function claim(string $key): bool { return self::change($key, false); }
    public static function clear(string $key): void { self::change($key, true); }
}
