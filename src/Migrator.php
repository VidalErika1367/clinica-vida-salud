<?php
declare(strict_types=1);
namespace App;

/**
 * Aplica las migraciones SQL de database/migrations en orden, una sola vez cada una.
 * Lo usan bin/instalar.php y el arranque del despliegue. Cada migración se registra con su hash:
 * si un archivo ya aplicado cambia, se detiene la instalación (el instalador de Render lo verifica).
 */
final class Migrator
{
    /**
     * Ejecuta las migraciones pendientes dentro de un candado de sesión de PostgreSQL.
     *
     * @return string[] nombres de los archivos aplicados en esta ejecución
     * @throws \RuntimeException si la base ya tiene tablas sin registro o si una migración aplicada cambió
     */
    public static function apply(): array
    {
        $db = Database::connection(); $done = [];
        // Candado: dos instalaciones simultáneas no pueden aplicar la misma migración.
        $db->exec('SELECT pg_advisory_lock(9384731)');
        try {
            $db->exec('CREATE TABLE IF NOT EXISTS migracion (nombre varchar(160) PRIMARY KEY, hash varchar(64) NOT NULL, aplicada_en timestamptz NOT NULL DEFAULT now())');
            $count = (int) Database::run('SELECT count(*) FROM migracion')->fetchColumn();
            // Una base con tablas del sistema pero sin registro no se adopta: podría contener datos reales.
            if ($count === 0 && Database::run("SELECT to_regclass('public.usuario') IS NOT NULL")->fetchColumn()) {
                throw new \RuntimeException('La base ya contiene tablas del sistema. Usa una base vacía para esta primera instalación; no se borraron los datos existentes.');
            }
            $files = glob(BASE_PATH . '/database/migrations/*.sql'); sort($files);
            foreach ($files as $file) {
                $name = basename($file); $sql = file_get_contents($file); $hash = hash('sha256', $sql);
                $saved = Database::run('SELECT hash FROM migracion WHERE nombre=?', [$name])->fetchColumn();
                if ($saved !== false) {
                    // Una migración aplicada no puede cambiar: el hash lo impide.
                    if ($saved !== $hash) { throw new \RuntimeException('Se modificó una migración aplicada: ' . $name); }
                    continue;
                }
                // Cada migración y su registro se confirman juntos: no queda aplicada a medias.
                Database::transaction(function () use ($db, $sql, $name, $hash): void {
                    $db->exec($sql); Database::run('INSERT INTO migracion(nombre,hash) VALUES(?,?)', [$name, $hash]);
                });
                $done[] = $name;
            }
        } finally { $db->exec('SELECT pg_advisory_unlock(9384731)'); }
        return $done;
    }
}
