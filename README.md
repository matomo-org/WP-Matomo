# Connect Matomo (former WP-Matomo, WP-Piwik)

This [WordPress](https://wordpress.org) plugin adds a [Matomo](http://matomo.org) stats site to your blog's dashboard. It's also able to add the Matomo tracking code to your blog.

## How to use this plugin

To use this plugin you will need your own Matomo instance. If you do not already have a Matomo setup, you have two simple options: use either [self-hosted](http://matomo.org/) or [cloud-hosted](http://matomo.org/hosting/).

This repository was created to develop and maintain Connect Matomo (WP-Matomo, WP-Piwik). Please see the WordPress plugin directory if you like to use this plugin: https://wordpress.org/plugins/wp-piwik/

## Development

Local development uses [DDEV](https://ddev.com/). It serves a single-site and a multisite
WordPress with this plugin activated, and runs the PHPUnit suite. Matomo is *not* part of that
environment — the plugin is pointed at an external Matomo, which can be any instance you like.

```bash
ddev start
ddev composer install
ddev wp-matomo:install
ddev wp-matomo:connect --token=<your matomo auth token>
ddev launch
```

Tests: `ddev wp-matomo:test` and `ddev wp-matomo:test --multisite`.

See [.ddev/README.md](.ddev/README.md) for the full setup, how to point at a different Matomo,
and the things that fail silently.
