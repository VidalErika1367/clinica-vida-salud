<?php
declare(strict_types=1);
namespace App;

/**
 * Limita los intentos de ingreso fallidos por clave (por ejemplo, por identidad).
 * Estado en archivos JSON bajo storage/ con bloqueo exclusivo: 8 intentos y 15 minutos de espera.
 * Lo usa el ingreso (CU02).
 */
final class RateLimiter
{
    /**
     * Cuenta un intento o limpia el contador de la clave, de forma atómica.
     *
     * @param string $key clave de control (se guarda como hash, sin el texto original)
     * @param bool $clear true para limpiar el contador tras un ingreso correcto
     * @return bool true si el intento está permitido
     * @throws \RuntimeException si no se puede abrir o bloquear el archivo de control
     */
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
    /**
     * Registra un intento fallido y dice si todavía se puede intentar.
     *
     * @param string $key clave de control
     * @return bool false si ya se alcanzó el límite
     */
    public static function claim(string $key): bool { return self::change($key, false); }
    /**
     * Borra el contador de la clave; se llama después de un ingreso correcto.
     *
     * @param string $key clave de control
     * @return void
     */
    public static function clear(string $key): void { self::change($key, true); }
}
