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
            $local_domain = \WP_CLI::runcommand("option get home $skip_flag", ['return' => true]);
            \WP_CLI::Log("Set local domain ($local_domain) from local site options.");
        }

        if (isset($config['url'])) {
            $remote_domain = $config['url'];
        } else {
            $remote_domain = \WP_CLI::runcommand("$ssh_flag option get home $skip_flag", ['return' => true]);
            \WP_CLI::Log("Set remote domain ($remote_domain) from remote site options.");
        }

        if (empty($local_domain)) {
            \WP_CLI::error('Could not get local domain. Please check your config.');
        }

        if (empty($remote_domain)) {
            \WP_CLI::error('Could not get remote domain. Please check your config.');
        }

        $config_str = print_r($config, true);

        \WP_CLI::confirm(
            "WARNING: This will replace the local database/files with the remote database and files.\n\n" .
                "Config:\n" .
                "$config_str\n\n" .
                "Local domain:\n$local_domain.\n\n" .
                "Remote domain:\n$remote_domain.\n\n" .
                "Continue?"
        );

        if ($config['db_backup']) {
            $path = rtrim(ABSPATH, '/');
            $backupsDirName = 'wp-sync-backups';  // TODO allow users to specify a custom backup path
            $db_backup_path = "$path/$backupsDirName/wp_sync_backup_" . date('Ymd_His') . ".sql";

            // TODO Create the backups directory or set permissions if it already exists

            \WP_CLI::runcommand("db export $db_backup_path $skip_flag");

            \WP_CLI::log("- Database backup saved to $db_backup_path\n");
        }


        if ($config['uploads']) {
            $host = rtrim($config['host'], '/');
            $path = rtrim($config['path'], '/');

            $path_from = "$host:$path/wp-content/uploads/";
            $path_to = ABSPATH . 'wp-content/uploads/';

            \WP_CLI::log('- Transferring uploads folder.');
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

            \WP_CLI::log('- Transferring plugins folder.');
            \WpSync\Helpers::syncFiles(
                $path_from,
                $path_to,
                $config
            );

            // mu-plugins
            $path_from = "$host:$path/wp-content/mu-plugins/";
            $path_to = ABSPATH . 'wp-content/mu-plugins/';

            \WP_CLI::log('- Transferring mu-plugins folder.');
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

            \WP_CLI::log('- Transferring themes folder.');
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

            \WP_CLI::log("- Exporting $env database\n");
            // \WP_CLI::runcommand("$ssh_flag db export $exclude_string - > \"$db_sync_file\"");

            \WP_CLI::runcommand("$ssh_flag db export - > \"$db_sync_file\" $skip_flag");

            // Import into local DB
            \WP_CLI::log('- Importing database.');
            \WP_CLI::runcommand("db import \"$db_sync_file\" $skip_flag");

            // Remove temporary sync file
            unlink($db_sync_file);

            // Search and replace domains
            \WP_CLI::log('- Replacing domains and setting home and siteurl.');
            \WP_CLI::runcommand("$skip_flag option update home '$local_domain' $skip_flag");
            \WP_CLI::runcommand("$skip_flag option update siteurl '$local_domain' $skip_flag");
            \WP_CLI::runcommand("$skip_flag search-replace $remote_domain $local_domain $skip_flag");
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
            \WP_CLI::log('- Set site to load media from remote using BE Media from Remote');

            // Install and activate be-media-from-production plugin
            \WP_CLI::runcommand("plugin install --activate be-media-from-production $skip_flag");
            \WP_CLI::runcommand("config set BE_MEDIA_FROM_PRODUCTION_URL \"$remote_domain\" --type=constant $skip_flag");

            //TODO allow remote media domain to be overriden in config
        }

        //TODO add ability to activate or deactivate plugins after sync

        //TODO add ability to run arbitrary commands after sync

        \WP_CLI::runcommand("rewrite flush");
        \WP_CLI::success("Sync completed successfully.");
    }
}
