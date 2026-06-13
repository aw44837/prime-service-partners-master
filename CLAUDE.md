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

## Production (Hostinger shared hosting)

Sites built from this template deploy to Hostinger shared hosting. Push-based GitHub
Actions rsync deploys do not work there; deploys are **pulled server-side** instead
(approach proven on the Jefco site, 2026-06).

### Pull live → local (`ddev pull hostinger`)

To refresh a local site from production, fill in `.ddev/providers/hostinger.yaml` with this
site's SSH host/user/port + server checkout path (see the per-site **Production** section in
its own `CLAUDE.md`), then run **`ddev pull hostinger`**. It dumps the live DB over SSH,
imports it, runs `ddev fix-keys` (resets the Easy Encryption lock hash for local), rebuilds
cache, and rsyncs the live files dir down (skipping regenerable derivatives). Read-only on
production. Note: importing the live DB overwrites local active config — re-run
`ddev drush config:import` if you have local-only config (e.g. a not-yet-deployed field).

### How deploys work

- An hPanel cron job runs a thin PHP wrapper that calls a bash deploy script via
  `proc_open()` — `exec`/`shell_exec`/`passthru`/`popen` are all disabled in
  Hostinger's PHP CLI.
- The deploy script runs: `git pull`, then `drush cache:rebuild`,
  `drush updatedb --yes`, `drush config:import --yes`, `drush cache:rebuild`,
  with all output appended to a deploy log file.
- Drush must be invoked as:

  ```bash
  <php-binary> <site>/vendor/drush/drush/drush.php --root=<site>/web <command>
  ```

  Do **not** use `vendor/bin/drush` or `vendor/drush/drush/drush` — both are bash
  shims that PHP will print instead of executing.

### New client site checklist

1. **Server `settings.php`** (lives only on the server, never deployed) must set:
   - DB credentials
   - `$settings['hash_salt']`
   - `$settings['file_private_path']`
   - `$settings['config_sync_directory']`
   - Easy Encryption private key path override — exported config hardcodes the
     local DDEV path `/var/www/html/...`:

     ```php
     $config['key.key.easy_encrypted__<id>__private_key']['key_provider_settings']['file_location']
       = '/home/<user>/.../.easy_encryption/<key-file>';
     ```

2. **Copy the Easy Encryption key.** `.easy_encryption/` at the repo root is
   untracked (secret) — copy it to each environment manually.
3. **Reset the key lock hash.** After copying the key or moving a DB between
   environments, the lock hash in Drupal state
   (`easy_encryption.lock.<key_id>` = sha256 of the key value) must be reset to
   match. Locally, `ddev fix-keys` copies the key and resets the hash in one step.
4. **Set up the deploy cron.** In hPanel, create a cron job running the PHP
   wrapper; verify a deploy end-to-end via the deploy log.

## Drupal config management

Config is exported to `web/sites/default/config/sync/` and committed to git.
On production, the deploy script imports it via `drush config:import --yes`.
