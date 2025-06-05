<?php
namespace App\Core;

/**
 * Простая система конфигурации для B2B
 */
class Config
{
    private static array $config = [];
    private static bool $loaded = false;
    
    /**
     * Загрузить конфигурацию
     */
    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        
        // Загружаем .env файл
        $envFile = '/etc/vdestor/config/.env';
        if (file_exists($envFile)) {
            self::loadEnv($envFile);
        }
        
        // Загружаем конфигурацию приложения
        $configFile = '/etc/vdestor/config/app.php';
        if (file_exists($configFile)) {
            self::$config = require $configFile;
        }
        
        // Переопределяем из переменных окружения
        self::applyEnvOverrides();
        
        self::$loaded = true;
    }
    
    /**
     * Получить значение конфигурации
     */
    public static function get(string $key, $default = null)
    {
        if (!self::$loaded) {
            self::load();
        }
        
        // Поддержка точечной нотации: 'database.host'
        $segments = explode('.', $key);
        $value = self::$config;
        
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        
        return $value;
    }
    
    /**
     * Установить значение конфигурации
     */
    public static function set(string $key, $value): void
    {
        if (!self::$loaded) {
            self::load();
        }
        
        $segments = explode('.', $key);
        $config = &self::$config;
        
        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $config[$segment] = $value;
            } else {
                if (!isset($config[$segment]) || !is_array($config[$segment])) {
                    $config[$segment] = [];
                }
                $config = &$config[$segment];
            }
        }
    }
    
    /**
     * Загрузить .env файл
     */
    private static function loadEnv(string $file): void
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            if (strpos($line, '=') !== false) {
                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value, '"\'');
                
                $_ENV[$name] = $value;
                putenv("{$name}={$value}");
            }
        }
    }
    
    /**
     * Применить переопределения из окружения
     */
    private static function applyEnvOverrides(): void
    {
        // Маппинг переменных окружения на конфигурацию
        $mappings = [
            'APP_DEBUG' => 'app.debug',
            'APP_URL' => 'app.url',
            'DB_HOST' => 'database.host',
            'DB_PORT' => 'database.port',
            'DB_NAME' => 'database.name',
            'DB_USER' => 'database.user',
            'DB_PASSWORD' => 'database.password',
            'SESSION_HANDLER' => 'session.handler',
            'CACHE_DRIVER' => 'cache.driver'
        ];
        
        foreach ($mappings as $env => $config) {
            $value = $_ENV[$env] ?? getenv($env);
            if ($value !== false) {
                self::set($config, $value);
            }
        }
    }
}

/**
 * Простой helper для обратной совместимости
 */
class Env
{
    public static function get(string $key, $default = null)
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }
    
    public static function load(): void
    {
        Config::load();
    }
}