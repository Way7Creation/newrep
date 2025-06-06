<?php
namespace App\Core;

/**
 * Упрощенный логгер без циклических зависимостей
 * Не использует сессии и БД внутри себя
 */
class Logger
{
    private static bool $initialized = false;
    private static string $logPath;
    private static bool $useDatabase = false;
    private static string $logLevel = 'info';
    
    // Уровни логирования
    const EMERGENCY = 'emergency';
    const ALERT = 'alert';
    const CRITICAL = 'critical';
    const ERROR = 'error';
    const WARNING = 'warning';
    const NOTICE = 'notice';
    const INFO = 'info';
    const DEBUG = 'debug';
    
    // Приоритеты уровней
    private static array $levels = [
        self::EMERGENCY => 0,
        self::ALERT => 1,
        self::CRITICAL => 2,
        self::ERROR => 3,
        self::WARNING => 4,
        self::NOTICE => 5,
        self::INFO => 6,
        self::DEBUG => 7,
    ];
    
    /**
     * Инициализация логгера
     */
    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        // Используем Config
        self::$logPath = Config::get('logging.path', '/var/log/vdestor');
        self::$logLevel = Config::get('logging.level', 'info');
        
        // Создаем директорию если её нет
        if (!is_dir(self::$logPath)) {
            @mkdir(self::$logPath, 0755, true);
        }

        // Проверяем доступность БД для логирования (но не используем её сразу)
        self::$useDatabase = Config::get('logging.use_database', true);

        self::$initialized = true;
    }

    /**
     * Записать лог
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        if (!self::$initialized) {
            self::initialize();
        }
        
        // Проверяем уровень логирования
        if (!self::shouldLog($level)) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        
        // Всегда пишем в файл
        self::logToFile($level, $message, $context, $timestamp);

        // В БД пишем только если это безопасно (не из критических мест)
        if (self::$useDatabase && self::isSafeToLogToDatabase()) {
            self::logToDatabase($level, $message, $context, $timestamp);
        }
    }

    /**
     * Проверить, нужно ли логировать этот уровень
     */
    private static function shouldLog(string $level): bool
    {
        $currentLevel = self::$levels[self::$logLevel] ?? self::$levels[self::INFO];
        $messageLevel = self::$levels[$level] ?? self::$levels[self::INFO];
        
        return $messageLevel <= $currentLevel;
    }

    /**
     * Безопасно ли логировать в БД
     */
    private static function isSafeToLogToDatabase(): bool
    {
        // Не логируем в БД если:
        // 1. Мы внутри обработчика сессий
        // 2. Мы внутри обработчика ошибок
        // 3. БД недоступна
        
        static $isLogging = false;
        
        if ($isLogging) {
            return false; // Предотвращаем рекурсию
        }
        
        // Проверяем стек вызовов
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        foreach ($trace as $frame) {
            $class = $frame['class'] ?? '';
            if (strpos($class, 'SessionHandler') !== false || 
                strpos($class, 'ErrorHandler') !== false) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Запись в файл
     */
    private static function logToFile(string $level, string $message, array $context, string $timestamp): void
    {
        $filename = self::$logPath . '/' . date('Y-m-d') . '.log';
        
        // Форматируем сообщение
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $line = sprintf(
            "[%s] %s: %s%s\n",
            $timestamp,
            strtoupper($level),
            $message,
            $contextStr
        );
        
        // Атомарная запись
        @file_put_contents($filename, $line, FILE_APPEND | LOCK_EX);
        
        // Для критических ошибок дублируем в error_log
        if (in_array($level, [self::EMERGENCY, self::ALERT, self::CRITICAL, self::ERROR])) {
            error_log($line);
        }
    }

    /**
     * Запись в БД (с защитой от рекурсии)
     */
    private static function logToDatabase(string $level, string $message, array $context, string $timestamp): void
    {
        static $isLogging = false;
        
        if ($isLogging) {
            return; // Предотвращаем рекурсию
        }

        $isLogging = true;

        try {
            // Получаем user_id и session_id безопасно
            $userId = null;
            $sessionId = null;
            
            if (session_status() === PHP_SESSION_ACTIVE) {
                $userId = $_SESSION['user_id'] ?? null;
                $sessionId = session_id();
            }
            
            Database::query(
                "INSERT INTO application_logs (level, message, context, created_at) 
                 VALUES (?, ?, ?, ?)",
                [
                    $level,
                    $message,
                    json_encode($context),
                    $timestamp
                ]
            );
        } catch (\Exception $e) {
            // Игнорируем ошибки БД при логировании
            // Записываем только в файл
            $errorMsg = sprintf(
                "[%s] LOGGER_ERROR: Failed to log to database: %s\n",
                date('Y-m-d H:i:s'),
                $e->getMessage()
            );
            @file_put_contents(self::$logPath . '/logger-errors.log', $errorMsg, FILE_APPEND | LOCK_EX);
        } finally {
            $isLogging = false;
        }
    }

    // Методы-обертки для удобства
    
    public static function emergency(string $message, array $context = []): void
    {
        self::log(self::EMERGENCY, $message, $context);
    }

    public static function alert(string $message, array $context = []): void
    {
        self::log(self::ALERT, $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::log(self::CRITICAL, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log(self::ERROR, $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log(self::WARNING, $message, $context);
    }

    public static function notice(string $message, array $context = []): void
    {
        self::log(self::NOTICE, $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log(self::INFO, $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log(self::DEBUG, $message, $context);
    }

    public static function security(string $message, array $context = []): void
    {
        $context['security_event'] = true;
        self::log(self::WARNING, "[SECURITY] {$message}", $context);
    }

    /**
     * Очистка старых логов
     */
    public static function cleanup(int $daysToKeep = 30): int
    {
        $count = 0;
        $cutoffTime = strtotime("-{$daysToKeep} days");
        
        // Очистка файлов
        $files = glob(self::$logPath . '/*.log');
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }
        
        // Очистка БД
        if (self::$useDatabase) {
            try {
                $cutoffDate = date('Y-m-d', $cutoffTime);
                $stmt = Database::query(
                    "DELETE FROM application_logs WHERE created_at < ?",
                    [$cutoffDate]
                );
                $count += $stmt->rowCount();
            } catch (\Exception $e) {
                // Игнорируем ошибки очистки
            }
        }
        
        return $count;
    }

    /**
     * Получить последние логи
     */
    public static function getRecent(int $limit = 100, ?string $level = null): array
    {
        $logs = [];
        
        // Читаем из текущего файла
        $todayLog = self::$logPath . '/' . date('Y-m-d') . '.log';
        
        if (file_exists($todayLog)) {
            $lines = file($todayLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $lines = array_reverse($lines); // Последние сначала
            
            foreach ($lines as $line) {
                if (count($logs) >= $limit) {
                    break;
                }
                
                // Парсим строку лога
                if (preg_match('/\[(.*?)\] (\w+): (.*)/', $line, $matches)) {
                    $logLevel = strtolower($matches[2]);
                    
                    if ($level === null || $logLevel === $level) {
                        $logs[] = [
                            'timestamp' => $matches[1],
                            'level' => $logLevel,
                            'message' => $matches[3]
                        ];
                    }
                }
            }
        }
        
        return $logs;
    }
}