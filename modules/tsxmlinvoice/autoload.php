<?php

if (!defined('TSXMLINVOICE_AUTOLOAD_REGISTERED')) {
    define('TSXMLINVOICE_AUTOLOAD_REGISTERED', true);

    spl_autoload_register(function ($class) {
        $prefix = 'Modules\\Tsxmlinvoice\\';

        if (0 !== strpos($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    });
}
