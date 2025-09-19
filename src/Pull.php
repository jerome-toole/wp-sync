<?php

namespace WpSync;

class Pull
{
    public ?string $command;
    public ?string $config_file;

    public function __construct()
    {
        $this->command = 'pull';
        $this->config_file = 'wp-sync.yml';
    }

    /**
     * Pull Command
     *
     * <env>
     * : The environment to sync from.
     *
     * [--db_backup=<true|false>]
     * : Whether to backup the local database before syncing.
     *
     * [--verbose]
     * : Show detailed search-replace output tables.
     * TODO Document all options
     *
     *
     * @when after_wp_load
     */
    public function __invoke($args, $assoc_args)
    {
        $env = $args[0];

        if (empty($env)) {
            \WP_CLI::error("Provide an environment to sync. e.g. 'wp sync pull staging'");
        }

        if (!file_exists($this->config_file)) {
            \WP_CLI::error("The wp-sync.yml file does not exist.");
            //TODO allow users to run the command from CLI only
        }

        $yaml_config = spyc_load_file($this->config_file);

        $config = \WpSync\Helpers::buildConfig($this->command, $env, $assoc_args, $yaml_config);

        if (!isset($config['host']) || !isset($config['path'])) {
            \WP_CLI::error("The 'host' and 'path' setting must be provided in wp-sync.yml or as a CLI flag");
        }

        if (isset($config['user'])) {
            // TODO separate user and host so that host doesn't get overwritten
            $config['host'] = $config['user'] . '@' . $config['host'];
        }

        // String for non-wp-cli ssh commands
        $ssh_command = "ssh {$config['host']}";

        if (isset($config['port'])) {
            $ssh_command .= " -p {$config['port']}";
        }

        $ssh_command .= " 'cd {$config['path']} && "; // TODO check if path should be optional

        $ssh_flag_parts = [
            'host' => $config['host'],
            'port' => null,
            'path' => $config['path'],
        ];

        if (isset($config['port'])) {
            $ssh_flag_parts['port'] = ':' . $config['port'];
        }

        $ssh_flag = "--ssh=" . implode('', $ssh_flag_parts);
        $skip_flag = "--skip-plugins --skip-themes";

        if (isset($yaml_config['environments']['local']['url'])) {
            $local_domain = $yaml_config['environments']['local']['url'];
        } else {
            $local_domain = \WP_CLI::runcommand("option get home $skip_flag", [
                'return' => true,
                'exit_error' => false,
            ]);

            if (empty($local_domain)) {
                \WP_CLI::Error("Could not get local domain. Please check your config.");
            }

            \WP_CLI::Log("Local domain set from site options: ($local_domain)");
        }


        if (isset($config['url'])) {
            $remote_domain = $config['url'];
        } else {
            // \WP_CLI::Log("$ssh_flag option get home $skip_flag");
            $remote_domain = \WP_CLI::runcommand("$ssh_flag option get home $skip_flag", [
                'return' => true,
                'exit_error' => false,
            ]);

            if (empty($remote_domain)) {
                \WP_CLI::Error("Could not get remote domain. Please check your connection config\n[$ssh_flag].");
            }

            \WP_CLI::Log("Remote domain set from site options: ($remote_domain)");
        }

        $config_display = \WpSync\Helpers::formatConfig($config, $env);

        // Build dynamic warning based on config
        $warnings = [];
        if ($config['db']) {
            $warnings[] = "   • Local database will be replaced with remote database";
        }
        $file_operations = [];
        if ($config['themes']) $file_operations[] = 'themes';
        if ($config['plugins']) $file_operations[] = 'plugins';
        if ($config['uploads']) $file_operations[] = 'uploads';
        if (!empty($file_operations)) {
            $warnings[] = "   • Local " . implode(', ', $file_operations) . " will be replaced with remote files";
        }
        if (!empty($warnings)) {
            $warnings[] = "   • This action cannot be undone without a backup";
        }

        \WP_CLI::confirm(
            "\n" .
            $config_display . "\n\n" .
            "━━━ Domain Configuration ━━━\n" .
            "local_domain:  $local_domain\n" .
            "remote_domain: $remote_domain\n\n" .
            (!empty($warnings) ? \WP_CLI::colorize("%Y⚠️  This will overwrite your LOCAL environment%n\n") . implode("\n", $warnings) . "\n\n" : "") .
            \WP_CLI::colorize("Continue with pull from %Y'$env'%n to %G'local'%n?")
        );

        // Run before_pull commands
        if (isset($config['before_pull']) && is_array($config['before_pull'])) {
            \WP_CLI::line();
            \WP_CLI::log(\WP_CLI::colorize('%C━━━ Running Before Pull Commands ━━━%n'));
            \WpSync\Helpers::runCustomCommands($config['before_pull'], $ssh_flag, $skip_flag);
            \WP_CLI::log('');
        }

        if ($config['db_backup']) {
            // TODO secure the backups directory

            $path = rtrim(ABSPATH, '/');
            $backupsDirName = 'wp-sync-backups';  // TODO allow users to specify a custom backup path
            $backupsPath = "$path/$backupsDirName";
            $backupFileName = "wp_sync_backup_" . date('Ymd_His') . ".sql";

            if (!file_exists($backupsPath)) {
                mkdir($backupsPath, 0755, true);
            } else {
                if (!is_writable($backupsPath)) {
                    \WP_CLI::error("The backups directory is not writable. Please check the permissions.");
                }
            }

            \WP_CLI::runcommand("db export $backupsPath/$backupFileName $skip_flag");

            \WP_CLI::success("Database backup saved to $backupsPath/$backupFileName");
            \WP_CLI::log('');
        }


        if ($config['uploads']) {
            $host = rtrim($config['host'], '/');
            $path = rtrim($config['path'], '/');

            $path_from = "$host:$path/wp-content/uploads/";
            $path_to = ABSPATH . 'wp-content/uploads/';

            \WP_CLI::line();
            \WP_CLI::log(\WP_CLI::colorize('%C━━━ Transferring Files ━━━%n'));
            \WP_CLI::log('• Syncing uploads folder...');
            \WpSync\Helpers::syncFiles(
                $path_from,
                $path_to,
                $config
            );
        }

        if ($config['plugins']) {
            $host = rtrim($config['host'], '/');
            $path = rtrim($config['path'], '/');

            $path_from = "$host:$path/wp-content/plugins/";
            $path_to = ABSPATH . 'wp-content/plugins/';

            \WP_CLI::log('• Syncing plugins folder...');
            \WpSync\Helpers::syncFiles(
                $path_from,
                $path_to,
                $config
            );

            // mu-plugins
            $path_from = "$host:$path/wp-content/mu-plugins/";
            $path_to = ABSPATH . 'wp-content/mu-plugins/';

            \WP_CLI::log('• Syncing mu-plugins folder...');
            \WpSync\Helpers::syncFiles(
                $path_from,
                $path_to,
                $config
            );
        }

        if ($config['themes']) {
            $host = rtrim($config['host'], '/');
            $path = rtrim($config['path'], '/');

            $path_from = "$host:$path/wp-content/themes/";
            $path_to = ABSPATH . 'wp-content/themes/';

            \WP_CLI::log('• Syncing themes folder...');

            \WpSync\Helpers::syncFiles(
                $path_from,
                $path_to,
                $config
            );
        }

        //TODO add ability to rsync other folders

        // //TODO test mismatched prefixes
        // $local_prefix = \WP_CLI::runcommand("config get table_prefix");
        // $remote_prefix = \WP_CLI::runcommand(
        //     "$ssh_flag config get table_prefix",
        //     ['return' => true]
        // );
        // if ($local_prefix !== $remote_prefix) {
        //     \WP_CLI::confirm("The database prefixes do not match. WP Sync will update the local prefix in wp-config.php to match the remote prefix. Continue?");

        //     \WP_CLI::runcommand("wp config set table_prefix $remote_prefix");
        // }


        //TODO allow users to exclude tables
        // $exclude_string = '';

        // if (!$config['users']) {
        //     $exclude_tables[] = 'users';
        //     $exclude_tables[] = 'usermeta';
        // }

        // if (!empty($exclude_tables)) {
        //     $exclude_string = '--exclude_tables=' . implode(',', array_map(function ($t) use ($dbprefix) {
        //         return $dbprefix . $t;
        //     }, $exclude_tables));
        // }

        if ($config['db']) {
            // Export the remote database
            $db_sync_file = ABSPATH . 'wp-sync-temp.sql';

            \WP_CLI::line();
            \WP_CLI::log(\WP_CLI::colorize('%C━━━ Syncing Database ━━━%n'));
            \WP_CLI::log(\WP_CLI::colorize("%C•%n Exporting database from %Y$env%n..."));
            // \WP_CLI::runcommand("$ssh_flag db export $exclude_string - > \"$db_sync_file\"");

            \WP_CLI::runcommand("$ssh_flag db export --all-tablespaces --single-transaction --quick --lock-tables=false $skip_flag - > \"$db_sync_file\"");

            // Import into local DB
            \WP_CLI::log(\WP_CLI::colorize('%C•%n Importing database...'));
            \WP_CLI::runcommand("db import \"$db_sync_file\" $skip_flag");

            // Remove temporary sync file
            unlink($db_sync_file);

            // Search and replace domains
            \WP_CLI::log(\WP_CLI::colorize('%C•%n Updating domains and URLs...'));

            // Check if this is a multisite installation
            if (\WpSync\Helpers::isMultisite('', $skip_flag)) {
                \WP_CLI::log(\WP_CLI::colorize('%C•%n %MMultisite%n installation detected, using network-aware search-replace'));
                \WpSync\Helpers::performMultisiteSearchReplace($remote_domain, $local_domain, $config, '', $skip_flag);
            } else {
                \WP_CLI::runcommand("$skip_flag option update home '$local_domain' $skip_flag");
                \WP_CLI::runcommand("$skip_flag option update siteurl '$local_domain' $skip_flag");
                $quiet_flag = !empty($config['verbose']) ? '' : '--quiet';
                \WP_CLI::runcommand("$skip_flag search-replace $remote_domain $local_domain --all-tables $quiet_flag $skip_flag");

                // Process additional search-replace operations for single site
                \WpSync\Helpers::processAdditionalSearchReplace($config, '', $skip_flag);
            }
            \WP_CLI::log('');
        }

        // TODO set up custom search and replace
        // Search and replace domains
        // $search_replace = isset($config['search-replace']) ? $config['search-replace'] : false;
        // if ($search_replace) {
        //   \WP_CLI::log("- Custom search-replace is defined\n");

        //   if (is_array($search_replace)) {
        //     foreach ($search_replace as $search => $replace) {
        //       \WP_CLI::log("- Custom search-replace: Replacing $search with $replace.\n");
        //       \WP_CLI::runcommand("search-replace $search $replace --no-report");
        //     }
        //   }
        // }

        //TODO Remove custom database records
        // $remove_from_database = isset($config['remove_from_database']) ? $config['remove_from_database'] : false;
        // if ($remove_from_database) {
        //   \WP_CLI::log("- Custom remove_from_database is defined\n");

        //   if (is_array($remove_from_database)) {
        //     foreach ($remove_from_database as $item => $config) {
        //         // types: table, post type, options,
        //         if ($config['type'] == 'postmeta') {
        //             $table = 'postmeta';
        //         } else {
        //             $table = 'posts';
        //         }

        //         \WP_CLI::runcommand("db query 'delete from {$table_postmeta} where post_id in (select id from {$table_posts} where post_type = \"$post_type\");delete from {$table_posts} where post_type = \"$post_type\";'");
        //     }
        //   }
        // }

        if ($config['load_media_from_remote']) {
            \WP_CLI::log(\WP_CLI::colorize('%C━━━ Configuring Remote Media Loading ━━━%n'));
            \WP_CLI::log(\WP_CLI::colorize('%C•%n Installing %BBE Media from Production%n plugin...'));

            // Install and activate be-media-from-production plugin
            \WP_CLI::runcommand("plugin install https://github.com/billerickson/be-media-from-production/archive/master.zip --force --activate $skip_flag");

            // Configure media from remote for multisite or single site
            if (\WpSync\Helpers::isMultisite('', $skip_flag)) {
                \WP_CLI::log(\WP_CLI::colorize('%C•%n Configuring media from remote for %Mmultisite%n network...'));

                // Network activate the plugin for all sites
                \WP_CLI::runcommand("plugin activate be-media-from-production --network $skip_flag");

                // For multisite, the plugin can work with a single constant
                // The plugin should be smart enough to handle multisite URLs
                \WP_CLI::runcommand("config set BE_MEDIA_FROM_PRODUCTION_URL \"$remote_domain\" --type=constant $skip_flag");

                \WP_CLI::success(\WP_CLI::colorize("%G✓%n Configured network-wide media from remote: %G$local_domain%n %B->%n %Y$remote_domain%n"));
                \WP_CLI::log("  Note: BE Media from Production will automatically handle subdomain/subdirectory mappings");
            } else {
                // Single site configuration
                \WP_CLI::runcommand("config set BE_MEDIA_FROM_PRODUCTION_URL \"$remote_domain\" --type=constant $skip_flag");
                \WP_CLI::success(\WP_CLI::colorize("%G✓%n Configured media from remote: %G$local_domain%n %B->%n %Y$remote_domain%n"));
            }
            \WP_CLI::log('');

            //TODO allow remote media domain to be overriden in config
        }

        //TODO add ability to activate or deactivate plugins after sync

        //TODO add ability to run arbitrary commands after sync

        \WP_CLI::log(\WP_CLI::colorize('%C━━━ Finalizing Sync ━━━%n'));
        \WP_CLI::log(\WP_CLI::colorize('%C•%n Flushing rewrite rules...'));
        \WP_CLI::runcommand("rewrite flush");

        // Run after_pull commands
        if (isset($config['after_pull']) && is_array($config['after_pull'])) {
            \WP_CLI::line();
            \WP_CLI::log(\WP_CLI::colorize('%C━━━ Running After Pull Commands ━━━%n'));
            \WpSync\Helpers::runCustomCommands($config['after_pull'], $skip_flag);
        }

        \WP_CLI::line();
        \WP_CLI::log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        \WP_CLI::success("🎉 Pull from '$env' to 'local' completed successfully!");
        \WP_CLI::log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }
}
