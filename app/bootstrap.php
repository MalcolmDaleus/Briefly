<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_DIR', __DIR__);
define('DATA_DIR', APP_ROOT . '/data');

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

spl_autoload_register(static function (string $class): void {
    $file = APP_DIR . '/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_DIR . '/helpers.php';

ensure_data_dir();
