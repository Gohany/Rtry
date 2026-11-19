<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests\Support;

/**
 * Database errors in the shapes the drivers and frameworks really throw. The PDO and mysqli
 * shapes were captured from MySQL 8.0.43 through pdo_mysql and mysqli on PHP 8.2 and 8.5
 * (identical on both): a statement's PDOException has the SQLSTATE as its string code and
 * errorInfo [state, number, server text]; a connect error's has the number as its int code
 * and the same errorInfo; a mysqli_sql_exception has the number as its code, the server text
 * as its message and getSqlState().
 */
final class DbErrors
{
    /** pdo_mysql's statement error: "SQLSTATE[HY000]: General error: 1366 Incorrect integer value: ..." */
    public static function pdo(string $state, int $number, string $text): \PDOException
    {
        return PdoShaped::make(sprintf('SQLSTATE[%s]: %s: %d %s', $state, self::description($state), $number, $text), $state, [$state, $number, $text]);
    }

    /** pdo_mysql's connect error: "SQLSTATE[HY000] [1049] Unknown database '...'" */
    public static function pdoConnect(string $state, int $number, string $text): \PDOException
    {
        return PdoShaped::make(sprintf('SQLSTATE[%s] [%d] %s', $state, $number, $text), $number, [$state, $number, $text]);
    }

    public static function mysqli(string $state, int $number, string $text): \Throwable
    {
        $e = new \mysqli_sql_exception($text, $number);
        (new \ReflectionProperty(\mysqli_sql_exception::class, 'sqlstate'))->setValue($e, $state);
        return $e;
    }

    /**
     * Laravel's Illuminate\Database\QueryException (10+): a PDOException that copies the
     * driver's code and errorInfo and appends the SQL with its bindings substituted.
     *
     * @param list<string> $bindings
     */
    public static function laravel(\PDOException $previous, string $sql, array $bindings): \PDOException
    {
        foreach ($bindings as $binding) {
            $sql = preg_replace('~\?~', $binding, $sql, 1) ?? $sql;
        }
        return PdoShaped::make($previous->getMessage() . ' (Connection: mysql, SQL: ' . $sql . ')', $previous->getCode(), $previous->errorInfo, $previous);
    }

    /**
     * Doctrine DBAL 3/4 over PDO: the wrapper (Exception\DriverException) and the driver's
     * exception (Driver\PDO\Exception) both have getSQLState() and the driver's number as code.
     */
    public static function dbal(\PDOException $pdo): \RuntimeException
    {
        $info = $pdo->errorInfo ?? [];
        $driver = new DbalShaped($pdo->getMessage(), (int) ($info[1] ?? 0), isset($info[0]) ? (string) $info[0] : null, $pdo);
        return new DbalShaped('An exception occurred while executing a query: ' . $pdo->getMessage(), $driver->getCode(), $driver->getSQLState(), $driver);
    }

    private static function description(string $state): string
    {
        return match (substr($state, 0, 2)) {
            '23' => 'Integrity constraint violation',
            '40' => 'Serialization failure',
            '42' => 'Syntax error or access violation',
            '22' => 'Invalid datetime format',
            '55' => 'Lock not available',
            '57' => 'Query canceled',
            default => 'General error',
        };
    }
}
