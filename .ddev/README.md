# Connect Matomo DDEV environment

A local development environment for the Connect Matomo plugin.

Matomo is deliberately not part of this environment. The plugin is pointed at an external
Matomo — by default the DDEV project in a sibling checkout of the [matomo](https://github.com/matomo-org/matomo) repository.
But any Matomo would work.

## Prerequisites

Make sure you have DDEV installed; if not, follow the official
[DDEV documentation](https://docs.ddev.com/en/stable/users/install/ddev-installation/).

## Setup steps

### 1. Start the Matomo you want to develop against

If you are using the sibling `matomo` checkout, start it first:

```
cd ../matomo && ddev start
```

Any other Matomo (self-hosted, `*.matomo.cloud`, …) works too — see
[Pointing at a different Matomo](#pointing-at-a-different-matomo).

### 2. Start the environment

```
ddev start
ddev composer install
```

### 3. Install WordPress

```
ddev wp-matomo:install
```

This downloads WordPress, writes each `wp-config.php`, installs both browsable sites, converts the
second one into a network, and activates the plugin. Useful flags:

| Flag | Effect |
| --- | --- |
| `--only=single\|multisite\|tests` | build just one of the three installs |
| `--wp-version=6.8.2` | pin the WordPress version of the browsable installs |
| `--force` | re-download core and rewrite `wp-config.php` |

The test install always uses the WordPress release that matches the installed
`wp-phpunit/wp-phpunit`.

### 4. Connect the plugin to Matomo

```
ddev wp-matomo:connect --token=<auth token>
```

`--token` is required. Create one in Matomo under
**Administration → Personal → Security → Auth tokens → Create new token**.

The command sets `piwik_mode=http`, the URL, the token and `track_mode=js` on both browsable sites,
then verifies the connection and resolves the Matomo site id. With `auto_site_config` on, Matomo
creates the site if it does not exist yet. Other flags: `--url=`, `--site=single|multisite|both`,
`--track-mode=js|proxy|default|manually|disabled`, `--insecure`.

To see what is configured and re-check the connection at any time:

```
ddev wp-matomo:status
```

### 5. Open the site

```
ddev launch
```

## Pointing at a different Matomo

On demand:

```
ddev wp-matomo:connect --url=https://analytics.example.com/ --token=<token>
```

Persistently, in `.ddev/config.local.yaml` (gitignored), followed by `ddev restart`:

```yaml
web_environment:
  - WP_MATOMO_MATOMO_URL=https://analytics.example.com/
```

A publicly resolvable Matomo needs nothing else. A Matomo running in *another DDEV project* is not
in DNS, so `.ddev/docker-compose.matomo-host.yaml` maps its hostname onto the Docker host, where
ddev-router listens. Add an entry there for any additional project you want to reach:

```yaml
services:
  web:
    extra_hosts:
      - "matomo.ddev.site:host-gateway"
      - "another-matomo.ddev.site:host-gateway"
```

If you want the reverse direction — Matomo reaching the WordPress site, e.g. for its
"is this site reachable" checks — add the same kind of file to the *Matomo* project for
`wp-matomo.ddev.site`.

## Running the tests

```
ddev wp-matomo:test
ddev wp-matomo:test --multisite
ddev wp-matomo:test --filter RestIntegrationTest
```

`--multisite` switches the WordPress PHPUnit test library into network mode. It is unrelated to the
browsable `wp/multisite` install: the suite works on its own `wptests_` tables, which it drops and
recreates on every run.

## Starting over

```
ddev wp-matomo:reset [--yes]
```

Deletes `wp/` and the database and reinstalls. It is a host command on purpose: inside the
container this repository is mounted *below* `wp/`, so deleting `wp/` from in there would recurse
through the bind mount and delete your working tree. Never run `rm -rf` under `wp/wp-content` from
inside the container.

## Changing PHP, MariaDB or WordPress versions

Create `.ddev/config.local.yaml` and override what you need, then `ddev restart`. For example:

```yaml
php_version: "8.4"
database:
  type: mariadb
  version: "11.4"
```

For WordPress, use `ddev wp-matomo:install --wp-version=<version> --force`.
