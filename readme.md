# WP CLI Sync: Simple WordPress migrations from the command line

WP CLI Sync is a WP-CLI package designed for single-command CLI migrations between WordPress environments.
It provides a straightforward way to synchronize the database, themes, plugins etc. between different environments.

[Installation](#installation) | [Usage](#usage) | [Options](#options)

### Example:
Setup your wp-sync.yml file:
```yaml
# wp-sync.yml
pull:
  db: true
  plugins: true

environments:
  staging:
    user: user
    host: staging-server-ip
    path: /path/to/wordpress
    ssh: user@staging-server-ip/path/to/wordpress
    url: http://staging.example.com
```

Then run:
```bash
$ wp sync pull staging
```

What this does:
- Backs up the database locally before doing anything.
- Downloads and rewrites the database from staging.
- Loads media from staging if it doesn't exist locally.

## Installation

### Requirements
- [WP-CLI](https://make.wordpress.org/cli/handbook/guides/installing/) installed on each environment you want to sync.
- SSH access to all environments.

Install WP CLI Sync using `wp package install`:

```bash
wp package install https://github.com/jerome-toole/wp-sync.git
```

## Usage
### Set up your wp-sync.yml file
WP CLI Sync requires a wp-sync.yml file in your WordPress project. It can be in the root directory or in a
sub-directory such as a theme. Run the command from the same directory as your wp-sync.yml file.

This file contains the configuration for each command and the access details for your environments.

Here's an example configuration:
```yaml
# wp-sync.yml
pull:
  db: true
  themes: false
  plugins: true
  uploads: false
  db_backup: false
  load_media_from_remote: true

push:
  db: true
  themes: false
  plugins: true
  uploads: false
  db_backup: true
  load_media_from_remote: false

environments:
  local:
    url: http://local.example.com

  staging:
    user: user
    host: staging-server-ip
    path: /path/to/wordpress
    ssh: user@staging-server-ip/path/to/wordpress
    url: http://staging.example.com

  production:
    user: user
    host: production-server-ip
    path: /path/to/wordpress
    url: http://production.example.com
```

### Commands
#### Pull
`wp sync pull` synchronizes your WordPress environment from a specified environment.

`wp sync pull <env> <options>`

- `<env>`: The environment to sync from (e.g., staging, production).
- `<options>`: The options to use for the sync. See [Options](#options) for more details.

Here's an example using option flags:
```bash
wp sync pull staging --db=true --load_media_from_remote=true
```

#### Push Command
`wp sync push` synchronizes your local WordPress environment to a specified environment.

This command is used in the same way as the pull command, but synchronizes **from** your local environment **to** another environment.
`wp sync push <env> <options>`

### Options
- `db`: Synchronize the database. Default: false.
- `themes`: Synchronize themes. Default: false.
- `plugins`: Synchronize plugins. Default: false.
- `uploads`: Synchronize uploads. Default: false.
- `db_backup`: Backup the database locally before synchronizing. Default: true.
- `load_media_from_remote`: Load media from the remote environment when synchronizing the database (using [be-media-from-production](https://github.com/billerickson/BE-Media-from-Production)). Default: true.

## Custom Command Hooks

WP CLI Sync supports running arbitrary commands at key points during sync operations. This allows you to perform custom tasks like additional search-replace operations, cache clearing, or maintenance mode activation.

### Hook Types
- `before_pull` / `before_push`: Execute before sync operations begin
- `after_pull` / `after_push`: Execute after sync operations complete

### Configuration
Commands can be defined at global (pull/push) or environment-specific levels:

```yaml
pull:
  db: true
  plugins: true
  after_pull:
    - "wp search-replace 'old-string' 'new-string'"
    - "wp cache flush"

environments:
  staging:
    before_pull:
      - "wp maintenance-mode activate"
    after_pull:
      - "wp maintenance-mode deactivate"
```

### Command Types
- **WP-CLI commands**: Start with `wp ` and are executed with appropriate skip flags
- **Shell commands**: Any other commands are executed as shell commands
- **Remote execution**: Commands automatically run on remote server when using SSH

## WordPress Multisite Support

WP CLI Sync includes comprehensive support for WordPress multisite networks:

### Automatic Detection
- Automatically detects multisite installations
- Switches to network-aware operations when multisite is detected

### Enhanced Search-Replace
- Uses `--network` flag for network-wide search-replace operations
- Updates `DOMAIN_CURRENT_SITE` constant in wp-config.php
- Updates individual site domains in the database
- Handles both subdomain and subdirectory multisite configurations

### Media from Remote
- Network-activates BE Media from Production plugin
- Configures media loading at the network level
- Supports both subdomain and subdirectory multisite setups

### Multisite Example Configuration
```yaml
pull:
  db: true
  plugins: true
  after_pull:
    # Network-wide search replace
    - "wp search-replace 'staging.example.com' 'local.example.com' --network"
    # Flush cache on all sites
    - "wp site list --field=url --format=csv | xargs -I {} wp --url={} cache flush"

environments:
  staging:
    host: staging.example.com
    path: /var/www/html
    url: https://staging.example.com
```

## Contributing
Contributions are welcome. Please feel free to submit pull requests or raise issues on the GitHub repository.
It might take me a while to respond, but I'll do my best to get back to you.

## License
WP CLI Sync is open-sourced software licensed under the MIT license.

## Author
Jerome Toole

## Credits
Thanks to [maneuver-agency/wp-cli-sync](https://github.com/maneuver-agency/wp-cli-sync) which I referenced while building this package.

Thanks to Bill Erickson's [be-media-from-production](https://github.com/billerickson/BE-Media-from-Production) for powering the load_media_from_remote option.

Thanks to [WP Migrate](https://deliciousbrains.com/wp-migrate-db-pro/) for providing the motivation.
