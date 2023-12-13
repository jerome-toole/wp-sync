<?php

namespace WpSync;

class Helpers
{
    public static function array_merge_deep(array $array1, array $array2): array
    {
        $merged = $array1;

        foreach ($array2 as $key => &$value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = self::array_merge_deep($merged[$key], $value);
            } else if (is_numeric($key)) {
                if (!in_array($value, $merged)) {
                    $merged[] = $value;
                }
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    public static function buildConfig(string $command, string $env, array $assoc_args, array $yaml_config): array
    {
        // Set Config Defaults
        $config = [
            'db' => true,
            'db_backup' => true,
            'plugins' => false,
            'themes' => false,
            'uploads' => false,
            'load_media_from_remote' => true,
        ];

        // Merge command specific config settings
        $config = \WpSync\Helpers::array_merge_deep($config, $yaml_config[$command]);

        // Merge environment specific config settings
        $config = \WpSync\Helpers::array_merge_deep($config, $yaml_config['environments'][$env]);

        if (isset($config[$command])) {
            if (is_array($config[$command])) {
                // Merge the environment command config (e.g. 'pull') with the main config so that
                // env specific settings override the main config.)
                $config = array_merge($config, $config[$command]);
            }

            // Remove the nested command config from the main config
            unset($config[$command]);
        }

        // Handle CLI flags
        // Move any $assoc_args that start with pull-- to the action array
        foreach ($assoc_args as $key => $value) {
            if (strpos($key, "$action--") === 0) {
                $new_key = str_replace("$action--", '', $key);
                $config[$new_key] = $value;
                unset($assoc_args[$key]);
            }
        }

        // Merge remaining CLI flags with config
        $config = array_merge($config, $assoc_args);

        return $config;
    }

    public static function syncFiles(string $path_from, string $path_to, array $config)
    {
        $rsync_args = [
            '-avzh',
            '--progress',
        ];

        if (!empty($config['port'])) {
            $port = $config['port'];
            $rsync_args[] = "-e 'ssh -p {$port}'";
        }

        $rsync_args = implode(' ', $rsync_args);

        $cmd = "rsync $rsync_args $path_from $path_to";

        \WP_CLI::log($cmd);

        passthru($cmd);
    }
}
