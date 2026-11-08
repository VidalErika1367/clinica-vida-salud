<?php
declare(strict_types=1);
namespace App;
use PDO;
use PDOStatement;

/**
 * Acceso a PostgreSQL mediante PDO. Es la única vía hacia la base de datos.
 * Todas las consultas usan parámetros preparados; nunca se concatena entrada del usuario en SQL.
 * Lo usan todos los repositorios de src/Repositories.
 */
final class Database
{
    /** Conexión PDO compartida por la petición o el proceso. */
    private static ?PDO $connection = null;
    /**
     * Devuelve la conexión PDO compartida; la crea en la primera llamada.
     *
     * @return PDO conexión con excepciones de error y filas asociativas
     * @throws \RuntimeException si la extensión pdo_pgsql no está activa
     */
    public static function connection(): PDO
    {
        if (self::$connection === null) {
            if (!extension_loaded('pdo_pgsql')) {
                throw new \RuntimeException('Activa la extensión pdo_pgsql en php.ini.');
            }
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=5',
                Config::get('DB_HOST', '127.0.0.1'), (int) Config::get('DB_PORT', '5432'),
                Config::get('DB_NAME', 'clinica_vida_salud'), Config::get('DB_SSLMODE', 'prefer'));
            self::$connection = new PDO($dsn, Config::get('DB_USER', 'postgres'), Config::get('DB_PASSWORD'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // La zona horaria fija evita que las fechas de citas se interpreten en otra hora local.
            self::$connection->exec("SET TIME ZONE 'America/La_Paz'");
        }
        return self::$connection;
    }
    /**
     * Ejecuta una consulta preparada con parámetros enlazados según su tipo.
     *
     * @param string $sql consulta con marcadores "?" posicionales
     * @param array $values valores en el mismo orden que los marcadores
     * @return PDOStatement sentencia ya ejecutada
     * @throws \PDOException si la base rechaza la consulta
     */
    public static function run(string $sql, array $values = []): PDOStatement
    {
        $statement = self::connection()->prepare($sql);
        foreach (array_values($values) as $i => $value) {
            // El tipo explícito evita que enteros y booleanos lleguen a PostgreSQL como texto.
            $type = match (true) {
                is_bool($value) => PDO::PARAM_BOOL, is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL, default => PDO::PARAM_STR,
            };
            $statement->bindValue($i + 1, $value, $type);
        }
        $statement->execute();
        return $statement;
    }
    /**
     * Descarta la conexión compartida. Lo usa el proceso de tareas tras un error para empezar limpio.
     *
     * @return void
     */
    public static function reset(): void
    {
        try { if (self::$connection?->inTransaction()) { self::$connection->rollBack(); } }
        catch (\PDOException) { /* Una conexión caída ya no permite revertir en el cliente. */ }
        finally { self::$connection=null; }
    }
    /**
     * Ejecuta una operación dentro de una transacción: confirma si termina bien y revierte si falla.
     *
     * @param callable $operation trabajo que debe completarse o revertirse como una unidad
     * @return mixed valor devuelto por la operación
     * @throws \Throwable el error original, después de revertir la transacción
     */
    public static function transaction(callable $operation): mixed
    {
        $pdo = self::connection(); $pdo->beginTransaction();
        try { $result = $operation(); $pdo->commit(); return $result; }
        catch (\Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
    }
}
