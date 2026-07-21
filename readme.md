# WP CLI Sync: Simple WordPress migrations from the command line

WP CLI Sync is a WP-CLI package designed for single-command CLI migrations between WordPress environments.
It provides a straightforward way to synchronize the database, themes, plugins etc. between different environments.

[Quick start](#quick-start) | [Installation](#installation) | [Usage](#usage) | [Options](#options)

## Quick start

1. **Install the package** (once per machine):
   ```bash
   wp package install https://github.com/jerome-toole/wp-sync.git
   ```

2. **Create a config file** in your project folder — this can be your WordPress root or your theme, as long as you run the command from there:
   ```bash
   wp sync init
   ```
   This creates a `wp-sync.yml` file in the current directory.

3. **Add your server details.** Open `wp-sync.yml` and fill in an environment. You only need two things: a way to reach the server (an SSH alias or a hostname/IP) and the path to the WordPress installation on it:
   ```yaml
   environments:
     staging:
       host: my-staging-alias    # ~/.ssh/config alias, or a hostname/IP
       path: /path/to/wordpress  # remote WordPress root
       url: https://staging.example.com
   ```
   Using an `~/.ssh/config` alias is recommended — see [Connecting to your environments](#connecting-to-your-environments).

4. **Choose what to sync** in the `pull:` block (`db`, `themes`, `plugins`, `uploads`), check your settings are correct, then run:
   ```bash
   wp sync pull staging
   ```

That's it. `pull` copies the chosen parts of the remote environment down to your local site, backing up your local database first by default. Use [`wp sync push`](#push-command) to go the other way.

### Example:
Setup your wp-sync.yml file:
```yaml
# wp-sync.yml
pull:
  db: true
  plugins: true

environments:
  staging:
    host: my-staging-alias   # an ~/.ssh/config alias, or a hostname/IP
    path: /path/to/wordpress
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
    # Reference an ~/.ssh/config alias; user/port/key come from there.
    host: my-staging-alias
    path: /path/to/wordpress
    url: http://staging.example.com

  production:
    # Or set an explicit host with user/port as separate keys.
    host: production-server-ip
    user: user
    port: 22
    path: /path/to/wordpress
    url: http://production.example.com
```

### Connecting to your environments
`host` is the SSH destination used by `ssh`, `rsync` and WP-CLI's `--ssh` flag, so it should be an `~/.ssh/config` alias, a hostname, or an IP — not a `user@host:port` string. Keep the user and port as separate keys (see below). There are two ways to configure a connection:

**1. SSH config alias (recommended).** Define the connection once in `~/.ssh/config`:

```
Host my-staging-alias
    HostName 203.0.113.10
    User deploy
    Port 2222
    IdentityFile ~/.ssh/deploy_key
```

then reference it by name:

```yaml
staging:
  host: my-staging-alias
  path: /path/to/wordpress
```

`user`, `port` and the identity key all come from `~/.ssh/config`, so leave `user`/`port` out of `wp-sync.yml`. Setting them here overrides the alias, which is usually not what you want.

Using an alias also keeps the server IP, user and port out of `wp-sync.yml`, so the file can be committed to git without exposing your infrastructure. The connection details live in each collaborator's own `~/.ssh/config`, so everyone on the team defines the same alias name locally. (Your `url` and the SSH key are unaffected either way — the key is never stored in `wp-sync.yml`.)

**2. Explicit host.** Give a hostname or IP, with `user` and `port` as **separate** keys:

```yaml
staging:
  host: staging-server-ip
  user: user   # optional
  port: 22     # optional
  path: /path/to/wordpress
```

Don't combine them into the `host` value (e.g. `user@host:2222`). A bare `user@host` works, but an embedded `:port` breaks file transfers — `port` must be its own key. `path` (the remote WordPress root) is always required.

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
