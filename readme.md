# WP CLI Sync: Simple WordPress migrations from the command line

WP CLI Sync is a WP-CLI package designed for simple CLI migrations between WordPress environments.
It provides a straightforward way to synchronize the database, themes, plugins etc. between different environments.

[Installation](#installation) | [Usage](#usage) | [Options](#options)

### Example:
Download and rewrite the database from staging.
Dynamically load media from staging if it doesn't exist locally.
Backup the database locally before synchronizing.

Setup your wp-sync.yml file:
```yaml
# wp-sync.yml
pull:
  db: true
  plugins: true
  db_backup: true
  load_media_from_remote: true

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
  db_backup: true
  load_media_from_remote: true

push:
  db: true
  themes: false
  plugins: true
  uploads: false
  db_backup: true

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

### Pull Command
The wp sync pull command allows you to synchronize your WordPress environment from a specified source.

`wp sync pull <env> <options>`

- `<env>`: The environment to sync from (e.g., staging, production).

All the settings in the wp-sync.yml file can optionally be set as flags in your CLI command.

Here's an example of using flags:
```bash
wp sync pull staging --themes=true --db_backup=false
```

### Push Command
The wp sync push command allows you to synchronize your WordPress environment to a specified destination.

`wp sync push <env>`

- `<env>`: The environment to sync to (e.g., staging, production).

All the settings in the wp-sync.yml file can optionally be set as flags in your CLI command.

Here's an example of using flags:
```bash
wp sync push staging --themes=true --db_backup=false
```

### Options
- `db`: Synchronize the database. Default: false.
- `themes`: Synchronize themes. Default: false.
- `plugins`: Synchronize plugins. Default: false.
- `uploads`: Synchronize uploads. Default: false.
- `db_backup`: Backup the database locally before synchronizing. Default: false.
- `load_media_from_remote`: Load media from the remote environment when synchronizing the database (using [be-media-from-production](https://github.com/billerickson/BE-Media-from-Production)). Default: false.

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
