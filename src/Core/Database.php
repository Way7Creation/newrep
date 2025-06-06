<?php
namespace App\Core;

use PDO;
use PDOException;

/**
 * Менеджер БД - простой и без циклических зависимостей
 */
class Database
{
    private static ?PDO $connection = null;
    private static array $stats = [
        'query_count' => 0,
        'total_time' => 0,
        'last_query' => null
    ];

    /**
     * Получить подключение к БД
     */
    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            self::connect();
        }

        // Проверяем что соединение живое
        try {
            self::$connection->query('SELECT 1');
        } catch (PDOException $e) {
            // Переподключаемся если соединение потеряно
            self::$connection = null;
            self::connect();
        }

        return self::$connection;
    }

    /**
     * Подключиться к БД
     */
    private static function connect(): void
    {
        // Используем унифицированный Config
        $host = Config::get('database.host', 'localhost');
        $port = Config::get('database.port', 3306);
        $dbname = Config::get('database.name');
        $user = Config::get('database.user');
        $password = Config::get('database.password');
        $charset = Config::get('database.charset', 'utf8mb4');

        if (!$dbname || !$user) {
            throw new \RuntimeException('Database configuration missing');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        try {
            self::$connection = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$charset}_unicode_ci",
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_PERSISTENT => false
            ]);
            
        } catch (PDOException $e) {
            // Не логируем здесь чтобы избежать циклических зависимостей
            error_log("Database connection failed: " . $e->getMessage());
            throw new \RuntimeException("Database connection failed", 0, $e);
        }
    }

    /**
     * Выполнить запрос с подготовленными параметрами
     */
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $start = microtime(true);
        
        try {
            $pdo = self::getConnection();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            // Обновляем статистику
            self::$stats['query_count']++;
            self::$stats['total_time'] += microtime(true) - $start;
            self::$stats['last_query'] = $sql;
            
            return $stmt;
            
        } catch (PDOException $e) {
            // Логируем в error_log чтобы избежать циклических зависимостей
            error_log(sprintf(
                "Query failed: %s\nSQL: %s\nParams: %s",
                $e->getMessage(),
                $sql,
                json_encode($params)
            ));
            throw $e;
        }
    }

    /**
     * Начать транзакцию
     */
    public static function beginTransaction(): bool
    {
        return self::getConnection()->beginTransaction();
    }

    /**
     * Подтвердить транзакцию
     */
    public static function commit(): bool
    {
        return self::getConnection()->commit();
    }

    /**
     * Откатить транзакцию
     */
    public static function rollBack(): bool
    {
        return self::getConnection()->rollBack();
    }

    /**
     * Проверить, есть ли активная транзакция
     */
    public static function inTransaction(): bool
    {
        return self::getConnection()->inTransaction();
    }

    /**
     * Получить ID последней вставленной записи
     */
    public static function lastInsertId(): string
    {
        return self::getConnection()->lastInsertId();
    }

    /**
     * Экранировать строку (не рекомендуется, используйте подготовленные запросы)
     */
    public static function quote($value): string
    {
        return self::getConnection()->quote($value);
    }

    /**
     * Получить статистику
     */
    public static function getStats(): array
    {
        return array_merge(self::$stats, [
            'average_time' => self::$stats['query_count'] > 0 
                ? self::$stats['total_time'] / self::$stats['query_count'] 
                : 0,
            'is_connected' => self::$connection !== null
        ]);
    }

    /**
     * Сбросить статистику
     */
    public static function resetStats(): void
    {
        self::$stats = [
            'query_count' => 0,
            'total_time' => 0,
            'last_query' => null
        ];
    }

    /**
     * Закрыть соединение
     */
    public static function disconnect(): void
    {
        self::$connection = null;
    }

    /**
     * Проверить доступность БД
     */
    public static function isAvailable(): bool
    {
        try {
            self::query('SELECT 1');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}