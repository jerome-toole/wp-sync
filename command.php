<?php

if (!class_exists('WP_CLI')) {
    return;
}

if (file_exists($autoloader = __DIR__ . '/vendor/autoload.php')) {
    require_once $autoloader;
}

\WP_CLI::add_command('sync pull', new WpSync\Pull);
\WP_CLI::add_command('sync push', new WpSync\Push);
\WP_CLI::add_command('sync init', new WpSync\Init);
