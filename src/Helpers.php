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

    /**
     * Strip protocol (http/https) from a URL, leaving //domain/path
     */
    public static function strip_protocol($url) {
        return preg_replace('#^https?:#', '', rtrim($url, '/'));
    }

    public static function performMultisiteSearchReplace(string $old_domain, string $new_domain, string $ssh_flag = '', string $skip_flag = '--skip-plugins --skip-themes')
    {
        $command_prefix = $ssh_flag ? "$ssh_flag " : '';

        // Check if this is a multisite installation
        $is_multisite_cmd = $command_prefix . "config get MULTISITE $skip_flag";
        $is_multisite = \WP_CLI::runcommand($is_multisite_cmd, ['return' => true, 'exit_error' => false]);

        if ($is_multisite !== '1') {
            // Single site - use standard search-replace
            \WP_CLI::log('- Single site detected, using standard search-replace');
            \WP_CLI::runcommand($command_prefix . "search-replace $old_domain $new_domain $skip_flag");
            return;
        }

        \WP_CLI::log('- Multisite network detected, performing comprehensive search-replace');

        // Get network domain and path configuration
        $network_domain_cmd = $command_prefix . "config get DOMAIN_CURRENT_SITE $skip_flag";
        $network_domain = \WP_CLI::runcommand($network_domain_cmd, ['return' => true, 'exit_error' => false]);

        $network_path_cmd = $command_prefix . "config get PATH_CURRENT_SITE $skip_flag";
        $network_path = \WP_CLI::runcommand($network_path_cmd, ['return' => true, 'exit_error' => false]);

        // 1. Perform comprehensive network-wide search-replace with all URL formats
        $url_patterns = [
            "https://$old_domain",
            "http://$old_domain",
            "https://www.$old_domain",
            "http://www.$old_domain",
            "//$old_domain",
            $old_domain
        ];

        $new_url_patterns = [
            "https://$new_domain",
            "http://$new_domain",
            "https://www.$new_domain",
            "http://www.$new_domain",
            "//$new_domain",
            $new_domain
        ];

        // Use protocol-less patterns for main replacement
        $old_pattern = self::strip_protocol('https://' . $old_domain);
        $new_pattern = self::strip_protocol('https://' . $new_domain);
        \WP_CLI::log("- Replacing '$old_pattern' with '$new_pattern' across network (protocol-agnostic)");
        \WP_CLI::runcommand($command_prefix . "search-replace '$old_pattern' '$new_pattern' --network --all-tables --dry-run=false $skip_flag");

        // Continue with the rest of the original patterns for completeness
        for ($i = 0; $i < count($url_patterns); $i++) {
            $old_pattern = $url_patterns[$i];
            $new_pattern = $new_url_patterns[$i];
            \WP_CLI::log("- Replacing '$old_pattern' with '$new_pattern' across network");
            \WP_CLI::runcommand($command_prefix . "search-replace '$old_pattern' '$new_pattern' --network --all-tables --dry-run=false $skip_flag");
        }

        // 2. Get all sites and perform individual site search-replace operations
        $sites_cmd = $command_prefix . "site list --fields=blog_id,url,domain,path --format=json $skip_flag";
        $sites_json = \WP_CLI::runcommand($sites_cmd, ['return' => true, 'exit_error' => false]);

        if (!empty($sites_json)) {
            $sites = json_decode($sites_json, true);

            if (is_array($sites)) {
                foreach ($sites as $site) {
                    $blog_id = $site['blog_id'];
                    $site_url = rtrim($site['url'], '/');
                    $site_domain = $site['domain'];
                    $site_path = $site['path'];

                    \WP_CLI::log("- Processing site $blog_id: $site_url");

                    // Update the site-specific tables
                    foreach ($url_patterns as $j => $old_pattern) {
                        $new_pattern = $new_url_patterns[$j];
                        $old_site_pattern = str_replace($old_domain, $site_domain, $old_pattern);
                        $new_site_pattern = str_replace($old_domain, str_replace($old_domain, $new_domain, $site_domain), $new_pattern);

                        // Only process if the patterns are different and contain the old domain
                        if ($old_site_pattern !== $new_site_pattern && strpos($old_site_pattern, $old_domain) !== false) {
                            \WP_CLI::runcommand($command_prefix . "search-replace '$old_site_pattern' '$new_site_pattern' --url='$site_url' --all-tables $skip_flag");
                        }
                    }

                    // Update site-specific options
                    $new_site_domain = str_replace($old_domain, $new_domain, $site_domain);
                    $new_site_url = str_replace($old_domain, $new_domain, $site_url);

                    if ($site_domain !== $new_site_domain) {
                        // Update home and siteurl for this specific site
                        \WP_CLI::runcommand($command_prefix . "option update home '$new_site_url' --url='$site_url' $skip_flag");
                        \WP_CLI::runcommand($command_prefix . "option update siteurl '$new_site_url' --url='$site_url' $skip_flag");

                        \WP_CLI::log("- Updated site $blog_id URLs: $site_url -> $new_site_url");
                    }
                }
            }
        }

        // 3. Update network configuration constants
        if ($network_domain && strpos($network_domain, $old_domain) !== false) {
            $new_network_domain = str_replace($old_domain, $new_domain, $network_domain);
            \WP_CLI::runcommand($command_prefix . "config set DOMAIN_CURRENT_SITE '$new_network_domain' --type=constant $skip_flag");
            \WP_CLI::log("- Updated DOMAIN_CURRENT_SITE: $network_domain -> $new_network_domain");
        }

        // 4. Update critical multisite tables directly
        $table_prefix_cmd = $command_prefix . "config get table_prefix $skip_flag";
        $table_prefix = \WP_CLI::runcommand($table_prefix_cmd, ['return' => true, 'exit_error' => false]);

        if ($table_prefix) {
            $old_domain_escaped = addslashes($old_domain);
            $new_domain_escaped = addslashes($new_domain);

            // Update wp_blogs table domains
            $blogs_table = $table_prefix . "blogs";
            \WP_CLI::runcommand($command_prefix . "db query \"UPDATE $blogs_table SET domain = REPLACE(domain, '$old_domain_escaped', '$new_domain_escaped') WHERE domain LIKE '%$old_domain_escaped%'\" $skip_flag");
            \WP_CLI::log("- Updated wp_blogs table domains");

            // Update wp_site table domain (network main domain)
            $site_table = $table_prefix . "site";
            \WP_CLI::runcommand($command_prefix . "db query \"UPDATE $site_table SET domain = REPLACE(domain, '$old_domain_escaped', '$new_domain_escaped') WHERE domain LIKE '%$old_domain_escaped%'\" $skip_flag");
            \WP_CLI::log("- Updated wp_site table domain");

            // Update siteurl and home options in all site options tables
            if (!empty($sites_json)) {
                $sites = json_decode($sites_json, true);

                if (is_array($sites)) {
                    foreach ($sites as $site) {
                        $blog_id = $site['blog_id'];
                        $site_url = rtrim($site['url'], '/');
                        $new_site_url = str_replace($old_domain, $new_domain, $site_url);

                        // Determine the options table name for this site
                        if ($blog_id == 1) {
                            $options_table = $table_prefix . "options";
                        } else {
                            $options_table = $table_prefix . $blog_id . "_options";
                        }

                        // Update home and siteurl in the options table directly
                        \WP_CLI::runcommand($command_prefix . "db query \"UPDATE $options_table SET option_value = '$new_site_url' WHERE option_name = 'home'\" $skip_flag");
                        \WP_CLI::runcommand($command_prefix . "db query \"UPDATE $options_table SET option_value = '$new_site_url' WHERE option_name = 'siteurl'\" $skip_flag");

                        // Also update any other URLs in the options table
                        \WP_CLI::runcommand($command_prefix . "db query \"UPDATE $options_table SET option_value = REPLACE(option_value, '$old_domain_escaped', '$new_domain_escaped') WHERE option_value LIKE '%$old_domain_escaped%'\" $skip_flag");

                        \WP_CLI::log("- Updated $options_table (site $blog_id) home/siteurl: $site_url -> $new_site_url");
                    }
                }
            }

            // Final comprehensive search-replace using WP-CLI for remaining data
            \WP_CLI::log("- Performing final comprehensive search-replace");

            $final_patterns = [
                "https://$old_domain",
                "http://$old_domain",
                "https://www.$old_domain",
                "http://www.$old_domain",
                "//$old_domain"
            ];

            $final_new_patterns = [
                "https://$new_domain",
                "http://$new_domain",
                "https://www.$new_domain",
                "http://$new_domain",
                "//$new_domain"
            ];

            for ($i = 0; $i < count($final_patterns); $i++) {
                $old_pattern = $final_patterns[$i];
                $new_pattern = $final_new_patterns[$i];

                // Use WP-CLI search-replace with all tables and network flags
                \WP_CLI::runcommand($command_prefix . "search-replace '$old_pattern' '$new_pattern' --all-tables --network --dry-run=false $skip_flag");
                \WP_CLI::log("- Final replacement: '$old_pattern' -> '$new_pattern'");
            }
        }

        \WP_CLI::log('- Completed comprehensive multisite search-replace');
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
                \WP_CLI::log("- Running WP-CLI command: $full_command");
                \WP_CLI::runcommand(str_replace('wp ', '', $full_command));
            } else {
                // Shell command - run as-is
                \WP_CLI::log("- Running shell command: $command");
                $result = null;
                $exit_code = null;
                exec($command, $result, $exit_code);

                if ($exit_code !== 0) {
                    \WP_CLI::warning("Command failed with exit code $exit_code: $command");
                    if (!empty($result)) {
                        \WP_CLI::log("Output: " . implode("\n", $result));
                    }
                } else {
                    \WP_CLI::success("Command completed successfully: $command");
                }
            }
        }
    }
}
