<?php

namespace WpSync;

class Init
{
    public ?string $command;
    public ?string $config_file;

    public function __construct()
    {
        $this->command = 'init';
        $this->config_file = 'wp-sync.example.yml';
    }

    /**
     * Init Command
     *
     * @when after_wp_load
     */
    public function __invoke()
    {
        // Create a file in the current directory called wp-sync.yml
        $file_path = dirname(__DIR__) . '/' . $this->config_file;

        \WP_CLI::log("Creating file at {$file_path}");

        // Copy the config file to the directory that the command was called from
        $destination_path = getcwd() . '/wp-sync.yml';

        copy($file_path, $destination_path);

        \WP_CLI::success("Created new wp-sync.yml file...");
    }
}
