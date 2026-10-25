<?php
declare(strict_types=1);
namespace App;

final class Migrator
{
    public static function apply(): array
    {
        $db = Database::connection(); $done = [];
        $db->exec('SELECT pg_advisory_lock(9384731)');
        try {
            $db->exec('CREATE TABLE IF NOT EXISTS migracion (nombre varchar(160) PRIMARY KEY, hash varchar(64) NOT NULL, aplicada_en timestamptz NOT NULL DEFAULT now())');
            $count = (int) Database::run('SELECT count(*) FROM migracion')->fetchColumn();
            if ($count === 0 && Database::run("SELECT to_regclass('public.usuario') IS NOT NULL")->fetchColumn()) {
                throw new \RuntimeException('La base ya contiene tablas del sistema. Usa una base vacía para esta primera instalación; no se borraron los datos existentes.');
            }
            $files = glob(BASE_PATH . '/database/migrations/*.sql'); sort($files);
            foreach ($files as $file) {
                $name = basename($file); $sql = file_get_contents($file); $hash = hash('sha256', $sql);
                $saved = Database::run('SELECT hash FROM migracion WHERE nombre=?', [$name])->fetchColumn();
                if ($saved !== false) {
                    if ($saved !== $hash) { throw new \RuntimeException('Se modificó una migración aplicada: ' . $name); }
                    continue;
                }
                Database::transaction(function () use ($db, $sql, $name, $hash): void {
                    $db->exec($sql); Database::run('INSERT INTO migracion(nombre,hash) VALUES(?,?)', [$name, $hash]);
                });
                $done[] = $name;
            }
        } finally { $db->exec('SELECT pg_advisory_unlock(9384731)'); }
        return $done;
    }
}
