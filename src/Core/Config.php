<?php
namespace App\Core;

/**
 * Единая система конфигурации
 * Загружает .env файл и предоставляет доступ к настройкам
 */
class Config
{
    private static array $env = [];
    private static array $config = [];
    private static bool $loaded = false;
    
    /**
     * Инициализация конфигурации
     */
    public static function init(): void
    {
        if (self::$loaded) {
            return;
        }
        
        // Загружаем .env файл
        self::loadEnvFile();
        
        // Загружаем дефолтную конфигурацию
        self::loadDefaults();
        
        self::$loaded = true;
    }
    
    /**
     * Получить значение из окружения или конфигурации
     */
    public static function get(string $key, $default = null)
    {
        if (!self::$loaded) {
            self::init();
        }
        
        // Сначала проверяем переменные окружения
        if (isset(self::$env[$key])) {
            return self::$env[$key];
        }
        
        // Затем проверяем в $_ENV и getenv()
        $envValue = $_ENV[$key] ?? getenv($key);
        if ($envValue !== false) {
            return $envValue;
        }
        
        // Проверяем в конфигурации (поддержка точечной нотации)
        $value = self::getFromArray($key, self::$config);
        
        return $value !== null ? $value : $default;
    }
    
    /**
     * Установить значение конфигурации (только для текущего запроса)
     */
    public static function set(string $key, $value): void
    {
        if (!self::$loaded) {
            self::init();
        }
        
        self::$config[$key] = $value;
    }
    
    /**
     * Проверить существование ключа
     */
    public static function has(string $key): bool
    {
        if (!self::$loaded) {
            self::init();
        }
        
        return isset(self::$env[$key]) || 
               isset($_ENV[$key]) || 
               getenv($key) !== false ||
               self::getFromArray($key, self::$config) !== null;
    }
    
    /**
     * Загрузить .env файл
     */
    private static function loadEnvFile(): void
    {
        $envFile = '/etc/vdestor/config/.env';
        
        if (!file_exists($envFile)) {
            // Пробуем альтернативные пути
            $alternativePaths = [
                dirname(__DIR__, 2) . '/.env',
                '/var/www/.env',
                $_SERVER['DOCUMENT_ROOT'] . '/../.env'
            ];
            
            foreach ($alternativePaths as $path) {
                if (file_exists($path)) {
                    $envFile = $path;
                    break;
                }
            }
        }
        
        if (!file_exists($envFile)) {
            return; // Продолжаем работу без .env файла
        }
        
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Пропускаем комментарии
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Парсим строку
            if (strpos($line, '=') !== false) {
                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                
                // Убираем кавычки
                $value = trim($value, '"\'');
                
                // Обрабатываем специальные значения
                if ($value === 'true' || $value === 'false') {
                    $value = $value === 'true';
                } elseif (is_numeric($value)) {
                    $value = strpos($value, '.') !== false ? (float)$value : (int)$value;
                }
                
                self::$env[$name] = $value;
                $_ENV[$name] = $value;
                putenv("{$name}={$value}");
            }
        }
    }
    
    /**
     * Загрузить дефолтные настройки
     */
    private static function loadDefaults(): void
    {
        self::$config = [
            // Приложение
            'app' => [
                'name' => self::$env['APP_NAME'] ?? 'VDestor B2B',
                'env' => self::$env['APP_ENV'] ?? 'production',
                'debug' => self::$env['APP_DEBUG'] ?? false,
                'url' => self::$env['APP_URL'] ?? 'https://vdestor.ru',
                'timezone' => self::$env['APP_TIMEZONE'] ?? 'Europe/Moscow',
            ],
            
            // База данных
            'database' => [
                'host' => self::$env['DB_HOST'] ?? 'localhost',
                'port' => self::$env['DB_PORT'] ?? 3306,
                'name' => self::$env['DB_NAME'] ?? '',
                'user' => self::$env['DB_USER'] ?? '',
                'password' => self::$env['DB_PASSWORD'] ?? '',
                'charset' => self::$env['DB_CHARSET'] ?? 'utf8mb4',
            ],
            
            // Сессии
            'session' => [
                'handler' => self::$env['SESSION_HANDLER'] ?? 'files',
                'lifetime' => self::$env['SESSION_LIFETIME'] ?? 1800,
                'name' => self::$env['SESSION_NAME'] ?? 'VDE_SESSION',
                'secure' => self::$env['SESSION_SECURE'] ?? true,
                'httponly' => self::$env['SESSION_HTTPONLY'] ?? true,
            ],
            
            // Кеш
            'cache' => [
                'driver' => self::$env['CACHE_DRIVER'] ?? 'file',
                'path' => self::$env['CACHE_PATH'] ?? '/tmp/vdestor_cache',
                'ttl' => self::$env['CACHE_TTL'] ?? 3600,
            ],
            
            // Логи
            'logging' => [
                'path' => self::$env['LOG_PATH'] ?? '/var/log/vdestor',
                'level' => self::$env['LOG_LEVEL'] ?? 'info',
                'days' => self::$env['LOG_DAYS'] ?? 7,
            ],
            
            // Email
            'mail' => [
                'driver' => self::$env['MAIL_DRIVER'] ?? 'mail',
                'host' => self::$env['MAIL_HOST'] ?? '',
                'port' => self::$env['MAIL_PORT'] ?? 587,
                'from' => [
                    'address' => self::$env['MAIL_FROM_ADDRESS'] ?? 'noreply@vdestor.ru',
                    'name' => self::$env['MAIL_FROM_NAME'] ?? 'VDestor B2B',
                ],
                'encryption' => self::$env['MAIL_ENCRYPTION'] ?? 'tls',
            ],
        ];
    }
    
    /**
     * Получить значение из массива с поддержкой точечной нотации
     */
    private static function getFromArray(string $key, array $array)
    {
        if (isset($array[$key])) {
            return $array[$key];
        }
        
        // Поддержка точечной нотации (например: database.host)
        $segments = explode('.', $key);
        $value = $array;
        
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        
        return $value;
    }
}

/**
 * Алиас для обратной совместимости
 * @deprecated Используйте Config::get() вместо Env::get()
 */
class Env
{
    public static function get(string $key, $default = null)
    {
        return Config::get($key, $default);
    }
    
    public static function load(): void
    {
        Config::init();
    }
}