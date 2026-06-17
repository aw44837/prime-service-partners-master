---
name: drupal-update-rollout
description: >
  Update Drupal core and contrib modules across the Prime Service Partners estate:
  apply the update on psp-master-build first, pause for manual testing, then roll the
  dependency update out to every child site (action, Jefco, 2cool, clark-comfort,
  glenn-jones, swiftbrothers) and verify each locally, optionally pushing to live.
  Use when the user wants to "update Drupal", "update core and modules", "apply the
  security update", "patch the sites", "roll out updates to the children", or run the
  recurring monthly/security update cycle. Snapshots every DB before touching it,
  propagates only composer manifests to children (never clobbering per-site branding
  config), gates each commit behind a drift check, and stops before live unless told
  otherwise.
---

# PSP Drupal Update Rollout

Apply a Drupal core + module update to `psp-master-build`, test, then propagate it to
the six child client sites and (optionally) deploy live. The estate is a master
template plus children, each its own git repo with `upstream` = the master's GitHub repo.

**Golden rules**
- **Snapshot every DB before updating it** (`ddev snapshot`). Cheap rollback insurance.
- **Master first, then PAUSE** for the user's manual test before touching any child.
- **Children take only `composer.json` + `composer.lock` from upstream — never config.**
  Per-site branding/content lives in each child's `config/sync`; merging master config
  would clobber it. (Config Split's `site` split only covers `system.site` + keys.)
- **Commit only update-driven config.** A `config:export` usually also surfaces
  pre-existing drift (stale branding, removed fields). Gate every commit on a drift check.
- **Default to stopping before live.** Live deploy is a separate, explicit go-ahead.

## 0. Pre-flight

```bash
cd "<workspace>/psp-master-build"
ddev --version && ddev status          # master DDEV up
ddev composer --version                # note: Composer 2.10+ has the advisory policy (see Gotchas)
git remote -v                          # origin = prime-service-partners-master
```

Children (dir → ddev name): `action→action`, `Jefco→jefco`, `2cool→2cool`,
`clark-comfort→clark-comfort`, `glenn-jones→glenn-jones`, `swiftbrothers→swiftbrothers`.
All live as siblings of `psp-master-build`. Each has `upstream` = the master repo and
`origin` = its own repo.

## 1. Check for updates (master)

```bash
cd "<workspace>/psp-master-build"
ddev composer outdated "drupal/*" --direct      # what's available
ddev composer audit 2>&1 | head                 # security advisories (core etc.)
```
`drush pm:security` is removed — use `composer audit`. Note any **major** bumps (`~` in
outdated) and fragile areas (**Canvas** especially) — flag these to the user; they raise
breakage odds and may need a separate test run or get blocked upstream.

If nothing is outdated and audit is clean, stop — nothing to do.

## 2. Update master (then PAUSE)

```bash
cd "<workspace>/psp-master-build"
ddev snapshot --name pre-update-$(date +%Y%m%d)

ddev composer update -W                 # full update: moves core + transitive deps
                                        # (e.g. guzzlehttp/psr7) to fixed versions at once
# For a wanted MAJOR bump, after the full update try:
#   ddev composer require drupal/<pkg>:^<major> -W
# If it fails with a real dependency conflict, the major is blocked upstream — hold it.

ddev drush updatedb -y
ddev drush config:export -y
ddev drush cache:rebuild

ddev drush status | grep -i 'drupal version'    # confirm new version
ddev composer audit                              # expect "No security vulnerability advisories found."
ddev drush watchdog:show --severity=Error --count=15   # note timestamps — ignore pre-update ones
```

**Commit only the update-driven config (Bucket A).** A `config:export` typically also
dumps pre-existing drift. Classify before committing:
- **Update-driven (commit):** `composer.lock`, `web/sites/default/default.settings.php`
  (core scaffold), `canvas.component.*` / `canvas.folder.*` (1.x version-hash recompute +
  folder `dependencies.config` from `canvas_post_update_0018/0019`),
  `canvas.content_template.*`, `canvas_ai.settings`, `ai_agents.*`.
- **Drift (revert with `git checkout -- <file>`):** `meridian_subtheme.settings`
  (branding), `system.site`, `roof_types`/`field_image` deletions + their display
  cascades, `webform.webform.contact_*`, `views.view.reviews`, `config_split...site`
  (often just `{}`→`{  }` YAML noise), `pathauto.pattern.taxonomy_term`.
- **New `??` `canvas.pattern.*` / `entity_block.config_split`:** DB content, not the
  update — leave untracked.

Quick drift check (empty = clean):
```bash
git status -s web/sites/default/config/sync/ \
  | grep -vE 'canvas\.(component|folder|content_template|pattern)|canvas_ai|ai_agents'
```
Stage update-driven files (`git add -u <sync> composer.json composer.lock default.settings.php`),
commit, **but do not push yet**.

### → PAUSE. Hand the user a login link and let them test manually.
```bash
ddev drush uli
```
Test: homepage + nav, and a **Canvas editor** screen (`/canvas/editor/node/<nid>`) — the
Canvas jump is the top risk. Wait for their OK before proceeding.

### Gate before children
On approval, **push master to origin** so children can fetch it (master's repo is the
template, not a live site — safe to push):
```bash
git push origin main
```

## 3. Roll out to each child (local; snapshot first)

Pilot one child fully, confirm clean, then batch the rest. **One child at a time.** Per child:

```bash
cd "<workspace>/<child-dir>"
ddev start
ddev snapshot --name pre-update-$(date +%Y%m%d)
git fetch upstream

# Propagate ONLY the manifests — but check for divergence first:
if git diff --quiet HEAD upstream/main -- composer.json; then
  git checkout upstream/main -- composer.json composer.lock      # identical constraints → safe overwrite
  ddev composer install
else
  # Child has a bespoke composer.json (e.g. Jefco lacks drupal/config_split).
  # DO NOT overwrite it. Update in place instead:
  ddev composer update -W
fi

ddev drush updatedb -y
ddev drush cache:rebuild
ddev drush status | grep -i 'drupal version'      # confirm 11.3.x target
ddev drush config:export -y
```

**Drift gate — never auto-commit if non-Canvas/AI config appears:**
```bash
DRIFT=$(git status -s web/sites/default/config/sync/ \
  | grep -vE 'canvas\.(component|folder|content_template|pattern)|canvas_ai|ai_agents')
```
- If `$DRIFT` is non-empty: revert those files
  (`... | grep -v '^??' | awk '{print $NF}' | xargs git checkout --`), re-check, then commit.
  Common child drift: `field_additional_body_content` (Jefco never got the field master
  added), stale branding/`system.site` (swiftbrothers), `views.reviews`.
- Commit update-driven config + `composer.lock` (+ `composer.json` if overwritten +
  `default.settings.php`). **Do NOT push.** Leave untracked `canvas.pattern.*` alone.

Smoke check: `curl -sk -o /dev/null -w '%{http_code}' https://<ddev-name>.ddev.site/`
should be `200`; `ddev drush watchdog:show --severity=Error` should show no errors newer
than the update time.

Final sweep:
```bash
for d in action Jefco 2cool clark-comfort glenn-jones swiftbrothers; do
  (cd "<workspace>/$d" && echo "$d: $(ddev drush status 2>/dev/null | grep -i 'drupal version') \
    | +$(git rev-list --count origin/main..HEAD) unpushed")
done
```

## 4. Verify (visual)

Per site: `ddev drush uli` → login, then check homepage, primary nav, and a **Canvas
editor** screen `https://<ddev-name>.ddev.site/canvas/editor/node/<nid>` (find nodes with
`ddev drush sqlq "SELECT nid,type,title FROM node_field_data WHERE type IN ('page','services') LIMIT 4"`).
Prioritize any child that needed special handling (in-place update, drift reverts).
Look for: editor loads + component tree renders + edit/save works; no broken front-end
components; branding intact; console clean (a pre-existing "Menu Display / `drupal_block`"
twig warning is not from the update).

## 5. Live deploy (only on explicit go-ahead)

Deploys are **pulled server-side** on Hostinger (see master `CLAUDE.md`): push each child
to its `origin`, then the per-site cron runs the deploy script (`~/<site>-deploy.sh` on the
server).

⚠️ **The deploy script MUST run `composer install` — the original scripts did not.**
`vendor/`, `web/core`, and contrib are **gitignored** (Composer-managed), so a code/module
update's new files do NOT travel through git. A deploy that only does
`git pull → cache:rebuild → updatedb → config:import` lands new config + lock against OLD
code → `config:import` breaks. Each `~/<site>-deploy.sh` needs, right after `git pull`:
```bash
/usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$REPO" &&
```
(server has composer at `/usr/local/bin/composer`; also `export HOME=/home/<user>` for cron).
Fixed for Jefco 2026-06-17; the other live scripts need the same one-line addition before
their first code-update deploy. Verify a deploy with `tail ~/<site>-deploy.log` + live
`drush status`. **Live sites:** action, Jefco, 2cool, clark-comfort, glenn-jones (domains
`*.droptech.dev`); swiftbrothers is not live yet.

⚠️ **`config:import` reverts to committed config.** Any child whose committed `config/sync`
is stale vs its DB (e.g. swiftbrothers' branding, `system.site`) will have that config
**reverted on deploy**. Reconcile drift (export + commit the real per-site config) BEFORE
deploying such a site. See `[[deploy-config-import-reverts-branding]]`.

```bash
cd "<workspace>/<child-dir>" && git push origin main
# then watch the deploy log on the server / re-pull to confirm
```
Post-deploy QA per site: page loads, Canvas editor, encryption-key errors
(`ddev fix-keys` is the local remedy; on server the key path override must be set),
caching glitches (`cache:rebuild` again before judging).

## Gotchas (learned 2026-06-17)

- **Composer 2.10 advisory `policy: true`** blocks the resolver on any advisory-affected
  version. A narrow `composer require pkg:^x -W` fails because it won't bump *other*
  locked packages off now-blocked versions (e.g. core 11.3.11, psr7 2.8.1). **Fix: full
  `composer update -W`** — it moves everything to fixed versions at once. Do NOT disable
  the global advisory policy (it's a security mitigation and the wrong fix; the harness
  will block it). The policy is *helping* — it forces you off vulnerable pins.
- **`drupal/tagify` is pinned to 1.x** by `drupal_cms_admin_ui` (`^1.2`, via
  `drupal_cms_starter`). `require tagify:^2.0` fails with a real conflict — hold at 1.x
  until the Drupal CMS admin recipe allows `^2`.
- **Jefco diverges**: its `composer.json` lacks `drupal/config_split` (predates the
  template). Never overwrite it — update in place. Its config_split entity is inactive
  (`status: false`), harmless, but a template inconsistency to reconcile separately.
- **Children carry stale committed config** (master, Jefco, swiftbrothers seen). This is
  pre-existing drift, not from the update — the drift gate keeps it out of the update
  commit, but it's a live-deploy landmine (§5).
- See `[[drupal-update-composer-gotchas]]` in memory.

## Rollback

- DB: `ddev snapshot restore pre-update-<date>` (per site).
- Code: `git checkout -- composer.json composer.lock && ddev composer install`; discard
  the local commit if made (`git reset --hard HEAD~1` before any push).
- If nothing was pushed, production is untouched regardless.
