# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Local environment

This is a Composer-managed Drupal CMS 2.x site (Drupal 11 core) running via DDEV.

```bash
ddev start / ddev restart / ddev stop
ddev launch                          # open in browser
ddev composer install                # install PHP dependencies
ddev drush <command>                 # run any Drush command
ddev drush user:login                # get a one-time login link
ddev drush cache:rebuild
ddev drush updatedb --yes
ddev drush config:import --yes       # pull config from repo into DB
ddev drush config:export --yes       # push DB config back to repo
```

Machine-specific DDEV overrides go in `.ddev/config.local.yaml` (not committed).

## Adding a module

```bash
ddev composer require drupal/<project>
ddev drush pm:enable --yes <module_machine_name>
ddev drush cache:rebuild
```

## Production

- **Domain:** darkgray-crow-734216.hostingersite.com
- **Host:** Hostinger (shared hosting)
- **Deploy:** Push to `main` branch → GitHub Actions auto-deploys via rsync + SSH
- **Remote path:** `/home/u569401512/domains/darkgray-crow-734216.hostingersite.com`
- **Web root:** `web/` (symlinked to `public_html` on Hostinger)

### GitHub Actions secrets required

| Secret | Description |
|--------|-------------|
| `HOSTINGER_SSH_PRIVATE_KEY` | Private deploy key (matches key in `~/.ssh/authorized_keys` on server) |
| `HOSTINGER_SSH_HOST` | `76.13.202.32` |
| `HOSTINGER_SSH_PORT` | `65002` |
| `HOSTINGER_SSH_USER` | `u569401512` |
| `HOSTINGER_REMOTE_PATH` | `/home/u569401512/domains/darkgray-crow-734216.hostingersite.com` |

### Settings file

`web/sites/default/settings.php` is excluded from rsync (never overwritten on deploy).
The production settings file lives only on the server. Configure DB credentials there directly.

## Drupal config management

Config is exported to `web/sites/default/config/sync/` and committed to git.
Import on deploy is handled automatically by the GitHub Actions workflow.
# cron test Wed Jun  3 23:12:32 EDT 2026
# userEmail
The user's email address is andywaldrop@gmail.com.
