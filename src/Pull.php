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

        if (!isset($config['ssh'])) {
            \WP_CLI::error("An 'ssh' setting must be provided in wp-sync.yml or as a CLI flag");
        }

        $ssh_flag = "--ssh=" . $config['ssh'];

        if (isset($yaml_config['environments']['local']['url'])) {
            $local_domain = $yaml_config['environments']['local']['url'];
        } else {
            $local_domain = \WP_CLI::runcommand("option get home", ['return' => true]);
            \WP_CLI::Log("Set local domain ($local_domain) from local database.");
        }

        if (isset($config['url'])) {
            $remote_domain = $config['url'];
        } else {
            $remote_domain = \WP_CLI::runcommand("$ssh_flag option get home", ['return' => true]);
            \WP_CLI::Log("Set remote domain ($remote_domain) from remote database.");
        }

        if (empty($local_domain)) {
            \WP_CLI::error('Could not get local domain. Please check your config.');
        }

        if (empty($remote_domain)) {
            \WP_CLI::error('Could not get remote domain. Please check your config.');
        }

        $config_str = print_r($config, true);

        \WP_CLI::confirm(
            "WARNING: This will replace the local database and files with the remote database and files.\n\n" .
                "Local domain:\n$local_domain.\n\n" .
                "Remote domain:\n$remote_domain.\n\n" .
                "Config:\n" .
                "$config_str\n\n" .
                "Continue?"
        );

        if ($config['db_backup']) {
            $db_backup_path = ABSPATH . "wp_sync_db_backup_" . date('Ymd_His') . ".sql";

            \WP_CLI::runcommand("db export $db_backup_path");
            \WP_CLI::log("- Database backup saved to $db_backup_path");
        }

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
            \WP_CLI::log("- Exporting $env database");
            // \WP_CLI::runcommand("$ssh_flag db export $exclude_string - > \"$db_sync_file\"");
            \WP_CLI::runcommand("$ssh_flag db export - > \"$db_sync_file\"");

            // Import into local DB
            \WP_CLI::log('- Importing database.');
            \WP_CLI::runcommand("db import \"$db_sync_file\"");

            // Remove temporary sync file
            unlink($db_sync_file);

            // Search and replace domains
            \WP_CLI::log('- Replacing domains');
            \WP_CLI::runcommand("search-replace $remote_domain $local_domain --no-report");
        }

        // TODO set up custom search and replace
        // Search and replace domains
        // $search_replace = isset($config['search-replace']) ? $config['search-replace'] : false;
        // if ($search_replace) {
        //   \WP_CLI::log("- Custom search-replace is defined");

        //   if (is_array($search_replace)) {
        //     foreach ($search_replace as $search => $replace) {
        //       \WP_CLI::log("- Custom search-replace: Replacing $search with $replace.");
        //       \WP_CLI::runcommand("search-replace $search $replace --no-report");
        //     }
        //   }
        // }

        //TODO Remove custom database records
        // $remove_from_database = isset($config['remove_from_database']) ? $config['remove_from_database'] : false;
        // if ($remove_from_database) {
        //   \WP_CLI::log("- Custom remove_from_database is defined");

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

        if ($config['media_from_remote']) {
            \WP_CLI::log('- Set site to load media from remote using BE Media from Remote');

            // Install and activate be-media-from-production plugin
            \WP_CLI::runcommand("plugin install --activate be-media-from-production");
            \WP_CLI::runcommand("config set BE_MEDIA_FROM_PRODUCTION_URL \"$remote_domain\" --type=constant");

            //TODO allow remote media domain to be overriden in config
        }

        if ($config['uploads']) {
            // rsync needs a fully qualified remote path with a colon before the path.
            $ssh = rtrim($config['ssh'], '/');
            $ssh = preg_replace('/\//', ':/', $ssh, 1);

            //TODO replace with non-wp function
            $local_uploads_path = ABSPATH . 'wp-content/uploads/';
            $remote_uploads_path = "$ssh/wp-content/uploads/";

            $cmd = "rsync -azh $remote_uploads_path $local_uploads_path";

            // Transfer all uploaded files
            \WP_CLI::log('- Transferring uploads folder.');
            passthru($cmd);
        }

        if ($config['plugins']) {
            // rsync needs a fully qualified remote path with a colon before the path.
            $ssh = rtrim($config['ssh'], '/');
            $ssh = preg_replace('/\//', ':/', $ssh, 1);

            $local_plugins_path = ABSPATH . 'wp-content/plugins/';
            $remote_plugins_path = "$ssh/wp-content/plugins/";

            $cmd = "rsync -azh $remote_plugins_path $local_plugins_path";

            // Transfer all uploaded files
            \WP_CLI::log('- Transferring themes folder.');
            passthru($cmd);
        }

        if ($config['themes']) {
            // rsync needs a fully qualified remote path with a colon before the path.
            $ssh = rtrim($config['ssh'], '/');
            $ssh = preg_replace('/\//', ':/', $ssh, 1);

            $local_themes_path = ABSPATH . 'wp-content/themes/';
            $remote_themes_path = "$ssh/wp-content/themes/";

            $cmd = "rsync -azh $remote_themes_path $local_themes_path";

            // Transfer all uploaded files
            \WP_CLI::log('- Transferring themes folder.');
            passthru($cmd);
        }

        //TODO add ability to rsync other folders

        //TODO add ability to activate or deactivate plugins after sync

        //TODO Add push configuration.

        //TODO add ability to run arbitrary commands after sync

        \WP_CLI::success("Sync completed successfully.");
    }
}
