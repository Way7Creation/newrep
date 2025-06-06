<?php
namespace App\Core;

/**
 * Централизованное управление путями
 */
class Paths
{
    private static array $paths = [];
    private static bool $initialized = false;

    /**
     * Инициализация путей
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        $root = dirname(__DIR__, 2);
        
        // Используем Config вместо Env
        self::$paths = [
            'root' => $root,
            'public' => $root . '/public',
            'src' => $root . '/src',
            'views' => $root . '/src/views',
            'config' => Config::get('CONFIG_PATH', '/etc/vdestor/config'),
            'logs' => Config::get('logging.path', '/var/log/vdestor'),
            'cache' => Config::get('cache.path', '/tmp/vdestor_cache'),
            'sessions' => Config::get('SESSION_SAVE_PATH', '/var/www/www-root/data/mod-tmp'),
            'uploads' => $root . '/public/uploads',
            'assets' => $root . '/public/assets',
            'storage' => Config::get('STORAGE_PATH', $root . '/storage'),
            'temp' => Config::get('TEMP_PATH', sys_get_temp_dir())
        ];

        // Создаем необходимые директории если их нет
        self::ensureDirectoriesExist();

        self::$initialized = true;
    }

    /**
     * Получить путь
     */
    public static function get(string $key, string $append = ''): string
    {
        if (!self::$initialized) {
            self::init();
        }

        if (!isset(self::$paths[$key])) {
            throw new \InvalidArgumentException("Unknown path key: {$key}");
        }

        $path = self::$paths[$key];
        
        if ($append) {
            $path .= '/' . ltrim($append, '/');
        }

        return $path;
    }

    /**
     * Проверить существование пути
     */
    public static function exists(string $key, string $append = ''): bool
    {
        try {
            return file_exists(self::get($key, $append));
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Создать URL для пути
     */
    public static function url(string $path): string
    {
        return '/' . ltrim($path, '/');
    }

    /**
     * Получить URL для ассета
     */
    public static function asset(string $path): string
    {
        // Проверяем существование скомпилированных ассетов
        $distPath = self::get('assets', 'dist');
        
        if (is_dir($distPath)) {
            return '/assets/dist/' . ltrim($path, '/');
        }
        
        // Fallback на обычные ассеты
        return '/assets/' . ltrim($path, '/');
    }

    /**
     * Получить все пути
     */
    public static function all(): array
    {
        if (!self::$initialized) {
            self::init();
        }
        
        return self::$paths;
    }

    /**
     * Добавить новый путь
     */
    public static function add(string $key, string $path): void
    {
        if (!self::$initialized) {
            self::init();
        }
        
        self::$paths[$key] = $path;
    }

    /**
     * Создать директории если их нет
     */
    private static function ensureDirectoriesExist(): void
    {
        // Директории которые должны существовать и быть записываемыми
        $requiredDirs = ['logs', 'cache', 'uploads', 'storage', 'temp'];
        
        foreach ($requiredDirs as $key) {
            if (isset(self::$paths[$key])) {
                $path = self::$paths[$key];
                
                if (!is_dir($path)) {
                    @mkdir($path, 0755, true);
                }
                
                // Проверяем права записи
                if (!is_writable($path)) {
                    @chmod($path, 0755);
                }
            }
        }
    }

    /**
     * Получить информацию о директории
     */
    public static function getDirectoryInfo(string $key): array
    {
        if (!self::$initialized) {
            self::init();
        }
        
        if (!isset(self::$paths[$key])) {
            throw new \InvalidArgumentException("Unknown path key: {$key}");
        }
        
        $path = self::$paths[$key];
        
        return [
            'path' => $path,
            'exists' => file_exists($path),
            'is_dir' => is_dir($path),
            'is_readable' => is_readable($path),
            'is_writable' => is_writable($path),
            'permissions' => file_exists($path) ? substr(sprintf('%o', fileperms($path)), -4) : null,
            'size' => is_dir($path) ? self::getDirectorySize($path) : filesize($path)
        ];
    }

    /**
     * Получить размер директории
     */
    private static function getDirectorySize(string $path): int
    {
        $size = 0;
        
        if (is_dir($path)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        }
        
        return $size;
    }
}