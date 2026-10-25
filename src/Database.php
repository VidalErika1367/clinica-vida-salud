<?php
declare(strict_types=1);
namespace App;
use PDO;
use PDOStatement;

final class Database
{
    private static ?PDO $connection = null;
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
            self::$connection->exec("SET TIME ZONE 'America/La_Paz'");
        }
        return self::$connection;
    }
    public static function run(string $sql, array $values = []): PDOStatement
    {
        $statement = self::connection()->prepare($sql);
        foreach (array_values($values) as $i => $value) {
            $type = match (true) {
                is_bool($value) => PDO::PARAM_BOOL, is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL, default => PDO::PARAM_STR,
            };
            $statement->bindValue($i + 1, $value, $type);
        }
        $statement->execute();
        return $statement;
    }
    public static function transaction(callable $operation): mixed
    {
        $pdo = self::connection(); $pdo->beginTransaction();
        try { $result = $operation(); $pdo->commit(); return $result; }
        catch (\Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
    }
}
