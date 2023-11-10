# WP Sync: Simple WordPress migrations from the command line

## Overview

WP Sync is a WP-CLI package designed for simple CLI migrations between WordPress environments. It provides a straightforward way to synchronize content, themes, plugins, and other aspects of WordPress sites across different environments.

## Installation

To install WP Sync, you need to have WP-CLI installed on your system. Once you have WP-CLI, you can install WP Sync using Composer:

```bash
wp package install https://github.com/jerome-toole/wp-sync
```

## Configuration
WP Sync requires a wp-sync.yml file in your WordPress project. It can be in the root directory or in a
sub-directory such as a theme.
This file contains settings for different environments like local, staging, and production.

Here's an example configuration:
```yaml
# wp-sync.yml
pull:
  db: true
  themes: false
  plugins: false
  uploads: true
  db_backup: false

environments:
  local:
    url: http://local.example.com

  staging:
    ssh: user@staging-server-ip/path/to/wordpress
    url: http://staging.example.com

  production:
    ssh: user@production-server-ip/path/to/wordpress
    url: http://production.example.com
```

## Usage

### Pull Command
The wp sync pull command allows you to synchronize your WordPress environment from a specified source.

`wp sync pull <env>`

- `<env>`: The environment to sync from (e.g., staging, production).

All options can be set via flags or in the wp-sync.yml file.

```yaml
Here's an example of using flags:
```bash
wp sync pull staging --db --themes --plugins --uploads --db_backup
```

## Contributing
Contributions are welcome. Please feel free to submit pull requests or raise issues on the GitHub repository.
It might take me a while to respond, but I'll do my best to get back to you.

## License
WP Sync is open-sourced software licensed under the MIT license.

## Author
Jerome Toole
