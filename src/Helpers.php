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
            'db' => false,
            'plugins' => false,
            'themes' => false,
            'uploads' => false,
            'db_backup' => true,
            'load_media_from_remote' => true,
            'additional_search_replace' => [],
            'verbose' => false,
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

        // Handle boolean CLI flags (like --verbose)
        if (isset($assoc_args['verbose'])) {
            $config['verbose'] = true;
            unset($assoc_args['verbose']);
        }

        // Merge remaining CLI flags with config
        $config = array_merge($config, $assoc_args);

        return $config;
    }

    /**
     * Strip protocol (http/https) from a URL, leaving //domain/path
     */
    public static function strip_protocol($url) {
        return preg_replace('#^https?:#', '', rtrim($url, '/'));
    }

    public static function processAdditionalSearchReplace(array $config, string $command_prefix, string $skip_flag, bool $is_multisite = false)
    {
        if (!isset($config['additional_search_replace']) || !is_array($config['additional_search_replace']) || empty($config['additional_search_replace'])) {
            return;
        }

        \WP_CLI::log('- Processing additional search-replace operations');

        foreach ($config['additional_search_replace'] as $replacement) {
            if (!is_array($replacement) || !isset($replacement['search']) || !isset($replacement['replace'])) {
                continue;
            }

            $search = $replacement['search'];
            $replace = $replacement['replace'];

            if (empty($search) || empty($replace)) {
                continue;
            }

            $network_flag = $is_multisite ? '--network' : '';
            $quiet_flag = !empty($config['verbose']) ? '' : '--quiet';
            \WP_CLI::log("- Additional replacement: '$search' -> '$replace'");
            \WP_CLI::runcommand($command_prefix . "search-replace '$search' '$replace' --all-tables $network_flag $quiet_flag $skip_flag");
        }
    }

    public static function performMultisiteSearchReplace(string $old_domain, string $new_domain, array $config = [], string $ssh_flag = '', string $skip_flag = '--skip-plugins --skip-themes')
    {
        $command_prefix = $ssh_flag ? "$ssh_flag " : '';

        $quiet_flag = !empty($config['verbose']) ? '' : '--quiet';

        \WP_CLI::log('- Multisite network detected, performing search-replace');

        $old_domain_stripped = preg_replace('#^https?://(www\.)?#', '', rtrim($old_domain, '/'));
        $new_domain_stripped = preg_replace('#^https?://(www\.)?#', '', rtrim($new_domain, '/'));

        \WP_CLI::log("- Network search-replace: '$old_domain_stripped' -> '$new_domain_stripped'");
        \WP_CLI::runcommand($command_prefix . "search-replace '$old_domain_stripped' '$new_domain_stripped' --network --all-tables $quiet_flag $skip_flag");

        // Update network constants
        $network_domain_cmd = $command_prefix . "config get DOMAIN_CURRENT_SITE $skip_flag";
        $network_domain = \WP_CLI::runcommand($network_domain_cmd, ['return' => true, 'exit_error' => false]);

        if ($network_domain && strpos($network_domain, $old_domain) !== false) {
            $new_network_domain = str_replace($old_domain, $new_domain, $network_domain);
            \WP_CLI::runcommand($command_prefix . "config set DOMAIN_CURRENT_SITE '$new_network_domain' --type=constant $skip_flag");
            \WP_CLI::log("- Updated DOMAIN_CURRENT_SITE: $network_domain -> $new_network_domain");
        }

        // Process additional search-replace operations for multisite
        self::processAdditionalSearchReplace($config, $command_prefix, $skip_flag, true);

        \WP_CLI::log('- Completed multisite search-replace');
    }

    public static function isMultisite(string $ssh_flag = '', string $skip_flag = '--skip-plugins --skip-themes'): bool
    {
        $command_prefix = $ssh_flag ? "$ssh_flag " : '';
        $is_multisite_cmd = $command_prefix . "config get MULTISITE $skip_flag";
        $is_multisite = \WP_CLI::runcommand($is_multisite_cmd, ['return' => true, 'exit_error' => false]);

        return $is_multisite === '1';
    }

    public static function configureMultisiteMediaUrls(string $remote_domain, string $ssh_flag = '', string $skip_flag = '--skip-plugins --skip-themes')
    {
        $command_prefix = $ssh_flag ? "$ssh_flag " : '';

        // Get all sites in the network
        $sites_cmd = $command_prefix . "site list --fields=blog_id,url --format=json $skip_flag";
        $sites_json = \WP_CLI::runcommand($sites_cmd, ['return' => true, 'exit_error' => false]);

        if (!empty($sites_json)) {
            $sites = json_decode($sites_json, true);

            if (is_array($sites)) {
                foreach ($sites as $site) {
                    if (isset($site['blog_id']) && isset($site['url'])) {
                        $site_id = $site['blog_id'];
                        $site_url = rtrim($site['url'], '/');

                        // Calculate the remote site URL by domain replacement
                        $parsed_local = parse_url($site_url);
                        $parsed_remote = parse_url($remote_domain);

                        if ($parsed_local && $parsed_remote) {
                            $remote_site_url = $parsed_remote['scheme'] . '://' . $parsed_remote['host'];
                            if (isset($parsed_local['path']) && $parsed_local['path'] !== '/') {
                                $remote_site_url .= $parsed_local['path'];
                            }

                            // Set the option for this specific site
                            $option_cmd = $command_prefix . "option update be_media_from_production_url \"$remote_site_url\" --blog_id=\"$site_id\" $skip_flag";
                            \WP_CLI::runcommand($option_cmd, ['exit_error' => false]);

                            \WP_CLI::log("- Configured media from remote for site $site_id: $site_url -> $remote_site_url");
                        }
                    }
                }
            }
        }
    }

    public static function syncFiles(string $path_from, string $path_to, array $config)
    {
        $rsync_args = [
            '-avzh',
        ];

        if (!empty($config['port'])) {
            $port = $config['port'];
            $rsync_args[] = "-e 'ssh -p {$port}'";
        }

        $rsync_args = implode(' ', $rsync_args);
        $cmd = "rsync $rsync_args $path_from $path_to";

        $deploy_ignore_path = '.deployignore';

        // if a .deployignore file exists, use it
        if (file_exists("$deploy_ignore_path")) {
            \WP_CLI::log("using .deployignore file found in $deploy_ignore_path");
            $cmd .= " --exclude-from='$deploy_ignore_path'";
        } else {
            \WP_CLI::log("no .deployignore file found in $deploy_ignore_path");
            exit(1);
        }

        // \WP_CLI::error($cmd);

        \WP_CLI::log($cmd);

        passthru($cmd);
    }

    public static function runCustomCommands(array $commands, string $ssh_flag = '', string $skip_flag = '--skip-plugins --skip-themes')
    {
        if (empty($commands)) {
            return;
        }

        foreach ($commands as $command) {
            if (empty($command)) {
                continue;
            }

            // Check if command should run locally or remotely
            if (strpos($command, 'wp ') === 0) {
                // WP-CLI command - add skip flags and optional SSH
                $full_command = $ssh_flag ? "$ssh_flag $command $skip_flag" : "$command $skip_flag";
                \WP_CLI::log("  • Running WP-CLI: $command");
                \WP_CLI::runcommand(str_replace('wp ', '', $full_command));
            } else {
                // Shell command - run as-is
                \WP_CLI::log("  • Running shell: $command");
                $result = null;
                $exit_code = null;
                exec($command, $result, $exit_code);

                if ($exit_code !== 0) {
                    \WP_CLI::warning(\WP_CLI::colorize("    %R✗%n Command failed with exit code $exit_code"));
                    if (!empty($result)) {
                        \WP_CLI::log("    Output: " . implode("\n    ", $result));
                    }
                } else {
                    \WP_CLI::log(\WP_CLI::colorize("    %G✓%n Command completed successfully"));
                }
            }
        }
    }

    public static function formatConfig(array $config, string $env): string
    {
        $output = [];
        $output[] = "━━━ Sync Configuration ━━━";
        $output[] = "";
        $output[] = \WP_CLI::colorize("environment: %Y$env%n");
        $output[] = "";

        // Core sync settings
        $sync_settings = [
            'db' => 'Database',
            'themes' => 'Themes',
            'plugins' => 'Plugins',
            'uploads' => 'Uploads'
        ];

        $output[] = "sync:";
        foreach ($sync_settings as $key => $label) {
            $value = $config[$key] ? 'true' : 'false';
            $status = $config[$key] ? \WP_CLI::colorize('%G✓%n') : \WP_CLI::colorize('%R✗%n');
            $output[] = "  $key: $value $status";
        }
        $output[] = "";

        // Connection settings
        $output[] = "connection:";
        if (isset($config['host'])) {
            $output[] = "  host: " . $config['host'];
        }
        if (isset($config['port'])) {
            $output[] = "  port: " . $config['port'];
        }
        if (isset($config['path'])) {
            $output[] = "  path: " . $config['path'];
        }
        $output[] = "";

        // Optional settings
        $optional_settings = [
            'db_backup' => 'Database Backup',
            'load_media_from_remote' => 'Load Media from Remote',
            'verbose' => 'Verbose Output'
        ];

        $output[] = "options:";
        foreach ($optional_settings as $key => $label) {
            if (isset($config[$key])) {
                $value = $config[$key] ? 'true' : 'false';
                $status = $config[$key] ? \WP_CLI::colorize('%G✓%n') : \WP_CLI::colorize('%R✗%n');
                $output[] = "  $key: $value $status";
            }
        }

        // Custom commands
        $commands = ['before_pull', 'after_pull', 'before_push', 'after_push'];
        $has_commands = false;
        foreach ($commands as $cmd) {
            if (isset($config[$cmd]) && is_array($config[$cmd]) && !empty($config[$cmd])) {
                if (!$has_commands) {
                    $output[] = "";
                    $output[] = "custom_commands:";
                    $has_commands = true;
                }
                $output[] = "  $cmd:";
                foreach ($config[$cmd] as $command) {
                    $output[] = "    - \"$command\"";
                }
            }
        }

        return implode("\n", $output);
    }
}
