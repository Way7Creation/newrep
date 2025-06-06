<?php
namespace App\Core;

/**
 * Единая точка инициализации приложения
 */
class Bootstrap
{
    private static bool $initialized = false;
    
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        try {
            // 1. Загружаем конфигурацию (новый унифицированный класс)
            Config::init();
            
            // 2. Базовые настройки PHP
            self::configurePHP();
            
            // 3. Инициализируем обработку ошибок
            self::initializeErrorHandling();
            
            // 4. Инициализируем пути
            Paths::init();
            
            // 5. Инициализируем кеш (не требует БД)
            Cache::init();
            
            // 6. Запускаем сессию (может использовать БД)
            Session::start();
            
            // 7. Инициализируем логгер (после сессии)
            Logger::initialize();
            
            // 8. Устанавливаем заголовки безопасности
            SecurityHeaders::set();
            
            self::$initialized = true;
            
        } catch (\Exception $e) {
            error_log("Bootstrap failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    private static function configurePHP(): void
    {
        // Используем новый Config класс
        $timezone = Config::get('app.timezone', 'Europe/Moscow');
        date_default_timezone_set($timezone);
        
        $debug = Config::get('app.debug', false);
        
        if ($debug) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');
        } else {
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
        }
        
        // Логирование ошибок
        ini_set('log_errors', '1');
        ini_set('error_log', Config::get('logging.path', '/var/log/php') . '/error.log');
        
        // Настройки сессий
        ini_set('session.gc_maxlifetime', Config::get('session.lifetime', 1800));
        ini_set('session.cookie_lifetime', Config::get('session.lifetime', 1800));
        ini_set('session.cookie_secure', Config::get('session.secure', true) ? '1' : '0');
        ini_set('session.cookie_httponly', Config::get('session.httponly', true) ? '1' : '0');
        ini_set('session.cookie_samesite', 'Lax');
        
        // Другие важные настройки
        ini_set('max_execution_time', '300');
        ini_set('memory_limit', '256M');
        ini_set('post_max_size', '32M');
        ini_set('upload_max_filesize', '32M');
    }
    
    private static function initializeErrorHandling(): void
    {
        // Обработчик ошибок
        set_error_handler(function($severity, $message, $file, $line) {
            // Игнорируем подавленные ошибки
            if (!(error_reporting() & $severity)) {
                return false;
            }
            
            // Преобразуем в исключение
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        
        // Обработчик исключений
        set_exception_handler(function(\Throwable $e) {
            // Логируем ошибку
            error_log(sprintf(
                "Uncaught %s: %s in %s:%d\nStack trace:\n%s",
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            ));
            
            // Показываем пользователю
            if (Config::get('app.debug', false)) {
                // В debug режиме показываем подробности
                header('Content-Type: text/plain; charset=utf-8');
                echo "Error: " . $e->getMessage() . "\n\n";
                echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
                echo "Stack trace:\n" . $e->getTraceAsString();
            } else {
                // В production показываем общую ошибку
                http_response_code(500);
                
                // Если это API запрос
                if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') === 0) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'success' => false,
                        'error' => 'Internal Server Error'
                    ]);
                } else {
                    // HTML страница ошибки
                    include __DIR__ . '/../views/errors/500.php';
                }
            }
            
            exit(1);
        });
        
        // Обработчик завершения работы
        register_shutdown_function(function() {
            $error = error_get_last();
            
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                // Фатальная ошибка
                error_log(sprintf(
                    "Fatal error: %s in %s:%d",
                    $error['message'],
                    $error['file'],
                    $error['line']
                ));
                
                if (!Config::get('app.debug', false)) {
                    http_response_code(500);
                    echo "Internal Server Error";
                }
            }
        });
    }
    
    /**
     * Проверить, инициализировано ли приложение
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }
    
    /**
     * Сбросить состояние (только для тестов)
     */
    public static function reset(): void
    {
        self::$initialized = false;
    }
}