<?php
namespace App\Core;

use SessionHandlerInterface;
use PDO;

/**
 * Упрощенный обработчик сессий для БД
 * Убрано логирование для предотвращения циклических зависимостей
 */
class DBSessionHandler implements SessionHandlerInterface
{
    private PDO $pdo;
    private int $lifetime;
    private bool $tableChecked = false;

    public function __construct(PDO $pdo, int $lifetime)
    {
        $this->pdo = $pdo;
        $this->lifetime = $lifetime;
    }

    public function open($savePath, $sessionName): bool
    {
        // Проверяем существование таблицы при первом обращении
        if (!$this->tableChecked) {
            $this->ensureTableExists();
            $this->tableChecked = true;
        }
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read($sessionId): string
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT data FROM sessions WHERE session_id = :sid AND expires_at > NOW() LIMIT 1"
            );
            $stmt->execute(['sid' => $sessionId]);
            $data = $stmt->fetchColumn();
            
            return $data !== false ? (string)$data : '';
        } catch (\PDOException $e) {
            // Просто возвращаем пустую строку при ошибке
            // Логирование будет происходить на более высоком уровне
            return '';
        }
    }

    public function write($sessionId, $data): bool
    {
        try {
            $expires = date('Y-m-d H:i:s', time() + $this->lifetime);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO sessions (session_id, data, created_at, expires_at)
                VALUES (:sid, :data, NOW(), :expires)
                ON DUPLICATE KEY UPDATE
                    data = VALUES(data),
                    expires_at = VALUES(expires_at)
            ");
            
            return $stmt->execute([
                'sid' => $sessionId,
                'data' => $data,
                'expires' => $expires,
            ]);
        } catch (\PDOException $e) {
            // Возвращаем false при ошибке, PHP сам обработает
            return false;
        }
    }

    public function destroy($sessionId): bool
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE session_id = :sid");
            return $stmt->execute(['sid' => $sessionId]);
        } catch (\PDOException $e) {
            return false;
        }
    }

    public function gc($maxlifetime): int|false
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE expires_at < NOW()");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            return false;
        }
    }
    
    /**
     * Проверяем и создаем таблицу если её нет
     */
    private function ensureTableExists(): void
    {
        try {
            // Проверяем существование таблицы
            $result = $this->pdo->query("SHOW TABLES LIKE 'sessions'")->fetch();
            
            if (!$result) {
                // Создаем таблицу если её нет
                $this->pdo->exec("
                    CREATE TABLE IF NOT EXISTS sessions (
                        session_id VARCHAR(128) NOT NULL PRIMARY KEY,
                        data LONGTEXT NOT NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        expires_at DATETIME NOT NULL,
                        user_id INT DEFAULT NULL,
                        ip_address VARCHAR(45) DEFAULT NULL,
                        user_agent TEXT,
                        KEY idx_expires (expires_at),
                        KEY idx_user_id (user_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            }
        } catch (\PDOException $e) {
            // Игнорируем ошибки создания таблицы
            // Если таблица не создастся, то write() вернет false
        }
    }
}