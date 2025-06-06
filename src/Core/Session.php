<?php
namespace App\Core;

/**
 * Унифицированный менеджер сессий
 * Все операции с сессиями должны проходить через этот класс
 */
class Session
{
    private static bool $started = false;
    private static array $flashData = [];
    
    /**
     * Запустить сессию
     */
    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        if (headers_sent($file, $line)) {
            throw new \RuntimeException("Cannot start session, headers sent in {$file}:{$line}");
        }

        // Настройки сессии из конфигурации
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        
        session_set_cookie_params([
            'lifetime' => (int)Config::get('session.lifetime', 1800),
            'path' => '/',
            'domain' => '',
            'secure' => $secure && Config::get('session.secure', true),
            'httponly' => Config::get('session.httponly', true),
            'samesite' => 'Lax'
        ]);
        
        session_name(Config::get('session.name', 'VDE_SESSION'));

        // Настройка обработчика
        $handler = Config::get('session.handler', 'files');
        
        if ($handler === 'db') {
            try {
                $pdo = Database::getConnection();
                $lifetime = (int)Config::get('session.lifetime', 1800);
                $dbHandler = new DBSessionHandler($pdo, $lifetime);
                session_set_save_handler($dbHandler, true);
                
                // Устанавливаем сборщик мусора
                ini_set('session.gc_probability', '1');
                ini_set('session.gc_divisor', '100');
                ini_set('session.gc_maxlifetime', (string)$lifetime);
                
            } catch (\Exception $e) {
                // Fallback на файлы если БД недоступна
                error_log("DB session handler failed, using files: " . $e->getMessage());
                self::setupFileHandler();
            }
        } else {
            self::setupFileHandler();
        }

        // Запуск сессии
        if (!session_start()) {
            throw new \RuntimeException("Failed to start session");
        }

        self::$started = true;
        
        // Валидация и безопасность
        self::validateSession();
        
        // Обработка flash данных
        self::processFlashData();
    }

    /**
     * Настройка файлового обработчика
     */
    private static function setupFileHandler(): void
    {
        $path = Config::get('SESSION_SAVE_PATH', '/var/www/www-root/data/mod-tmp');
        
        if (!is_dir($path)) {
            $path = sys_get_temp_dir();
        }
        
        if (!is_writable($path)) {
            throw new \RuntimeException("Session save path is not writable: {$path}");
        }
        
        ini_set('session.save_handler', 'files');
        ini_set('session.save_path', $path);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }

    /**
     * Валидация сессии
     */
    private static function validateSession(): void
    {
        $now = time();
        
        // Инициализация при первом запуске
        if (!isset($_SESSION['_initialized'])) {
            $_SESSION['_initialized'] = true;
            $_SESSION['_fingerprint'] = self::generateFingerprint();
            $_SESSION['_created_at'] = $now;
            $_SESSION['_last_activity'] = $now;
            $_SESSION['_regenerated'] = $now;
            return;
        }
        
        // Проверка fingerprint
        $fingerprint = self::generateFingerprint();
        if ($_SESSION['_fingerprint'] !== $fingerprint) {
            self::destroy();
            self::start();
            return;
        }

        // Проверка времени жизни
        $maxLifetime = (int)Config::get('session.lifetime', 1800);
        if ($now - $_SESSION['_last_activity'] > $maxLifetime) {
            self::destroy();
            self::start();
            return;
        }
        
        $_SESSION['_last_activity'] = $now;

        // Регенерация ID каждые 30 минут
        if ($now - $_SESSION['_regenerated'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['_regenerated'] = $now;
        }
    }

    /**
     * Генерация отпечатка браузера
     */
    private static function generateFingerprint(): string
    {
        $data = [
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            // Используем только первые 3 октета IP для мобильных пользователей
            substr($_SERVER['REMOTE_ADDR'] ?? '', 0, strrpos($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', '.'))
        ];
        
        return hash('sha256', implode('|', $data));
    }

    /**
     * Получить значение из сессии
     */
    public static function get(string $key, $default = null)
    {
        self::ensureStarted();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Установить значение в сессию
     */
    public static function set(string $key, $value): void
    {
        self::ensureStarted();
        $_SESSION[$key] = $value;
    }

    /**
     * Проверить существование ключа
     */
    public static function has(string $key): bool
    {
        self::ensureStarted();
        return isset($_SESSION[$key]);
    }

    /**
     * Удалить значение из сессии
     */
    public static function remove(string $key): void
    {
        self::ensureStarted();
        unset($_SESSION[$key]);
    }

    /**
     * Получить все данные сессии
     */
    public static function all(): array
    {
        self::ensureStarted();
        
        // Исключаем служебные ключи
        $data = $_SESSION;
        unset($data['_initialized'], $data['_fingerprint'], $data['_created_at'], 
              $data['_last_activity'], $data['_regenerated'], $data['_flash']);
        
        return $data;
    }

    /**
     * Очистить все данные сессии (кроме служебных)
     */
    public static function clear(): void
    {
        self::ensureStarted();
        
        $keep = ['_initialized', '_fingerprint', '_created_at', '_last_activity', '_regenerated'];
        $preserved = [];
        
        foreach ($keep as $key) {
            if (isset($_SESSION[$key])) {
                $preserved[$key] = $_SESSION[$key];
            }
        }
        
        $_SESSION = $preserved;
    }

    /**
     * Flash данные (доступны только на следующий запрос)
     */
    public static function flash(string $key, $value): void
    {
        self::ensureStarted();
        
        if (!isset($_SESSION['_flash'])) {
            $_SESSION['_flash'] = [];
        }
        
        $_SESSION['_flash'][$key] = $value;
    }

    /**
     * Получить flash данные
     */
    public static function getFlash(string $key, $default = null)
    {
        self::ensureStarted();
        return self::$flashData[$key] ?? $default;
    }

    /**
     * Обработка flash данных
     */
    private static function processFlashData(): void
    {
        // Загружаем предыдущие flash данные
        if (isset($_SESSION['_flash'])) {
            self::$flashData = $_SESSION['_flash'];
            unset($_SESSION['_flash']);
        }
    }

    /**
     * Уничтожить сессию
     */
    public static function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }
            
            session_destroy();
        }
        
        self::$started = false;
        self::$flashData = [];
    }

    /**
     * Регенерировать ID сессии
     */
    public static function regenerate(bool $deleteOld = true): bool
    {
        self::ensureStarted();
        
        $result = session_regenerate_id($deleteOld);
        $_SESSION['_regenerated'] = time();
        
        return $result;
    }

    /**
     * Проверить, активна ли сессия
     */
    public static function isActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    /**
     * Получить ID сессии
     */
    public static function getId(): string
    {
        self::ensureStarted();
        return session_id();
    }

    /**
     * Установить ID сессии (только до start())
     */
    public static function setId(string $id): void
    {
        if (self::$started) {
            throw new \RuntimeException("Cannot set session ID after session started");
        }
        
        session_id($id);
    }

    /**
     * Убедиться что сессия запущена
     */
    private static function ensureStarted(): void
    {
        if (!self::$started && session_status() !== PHP_SESSION_ACTIVE) {
            self::start();
        }
    }

    /**
     * Сохранить данные сессии и закрыть
     */
    public static function save(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}