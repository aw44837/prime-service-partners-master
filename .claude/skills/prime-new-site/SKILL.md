---
name: prime-new-site
description: >
  Spin up a new Prime Service Partners client site from the local psp-master-build
  Drupal CMS / DDEV master. Clones the master into a new sibling folder, renames the
  DDEV project, wires git remotes (master as upstream + a new GitHub repo as origin),
  seeds the database from the running master, fixes the encryption keys, and rebrands
  the active Dripyard subtheme (site name, colors, fonts, header logo, favicon, white
  footer logo, social links). Use when the user wants to "create a new site",
  "build a new client/PSP site", "spin up a site from psp-master", or start a brand-new
  Action/Jefco-style site. Gathers all inputs upfront (and can auto-scrape brand colors,
  fonts, logo, and favicon from the client's existing website URL), then executes end-to-end.
---

# PSP New Site Builder

Create a new Prime Service Partners client site from `psp-master-build`. This automates
the proven spin-off pattern (Jefco → Action). **Gather every input first, then execute.**

## 0. Pre-flight (run before asking anything)

From the workspace root (the folder containing `psp-master-build`):

```bash
ddev --version                                   # DDEV installed
gh auth status                                   # GitHub CLI authed (account aw44837)
ddev -j list | jq -r '.raw[].name' 2>/dev/null   # is psp-master registered/running?
ls -d psp-master-build                           # master present
```

- The master must be **running** so its DB can be exported as the seed:
  `cd psp-master-build && ddev start` if needed.
- Confirm the workspace root. The master lives at `<workspace>/psp-master-build`; the
  new site is created as a **sibling** `<workspace>/<slug>`.

## 1. Gather inputs (ask ALL of these upfront)

Collect these in as few turns as possible. Use `AskUserQuestion` for the genuine
choices (git, seed, font preset); ask the free-text values (name, slug, hex, fonts,
file paths) directly. Echo a summary back before executing.

| # | Input | Notes / default |
|---|-------|-----------------|
| 1 | **Site display name** | e.g. `Action Plumbing, Heating & Air` |
| 2 | **Slug / root folder / DDEV name** | lowercase, no spaces, e.g. `action`. Folder created as sibling of master. The folder may already exist but must be empty. |
| 3 | **GitHub repo** | Create new **private** `aw44837/<slug>` as `origin`, master as `upstream`, push? (recommended) — or local-only + upstream, or fresh git init. |
| 4 | **Seed source** | Default: export the **running master's** DB. (Alt: fresh `drush site:install`.) |
| 5 | **Primary color** | hex, e.g. `#360E93`. Decide brightness `dark`/`light` from luminance. |
| 6 | **Secondary color** | hex, e.g. `#71CDC0` (mint → `light`). |
| 7 | **Heading font** | Google Font family, e.g. `Jost`. |
| 8 | **Body font** | Google Font family, e.g. `Josefin Sans`. |
| 9 | **Header logo** | absolute path to PNG/SVG (transparent). |
| 10 | **Footer logo (white)** | absolute path, optional. For the dark footer. |
| 11 | **Favicon** | absolute path to square PNG/ICO. |
| 12 | **Market (City, State)** | e.g. `Newport, New Hampshire` — localizes the AI prompts (§9b). Accept "none / multiple markets" → strip location phrasing instead. Spell the state out, don't abbreviate. |

**Branding shortcut:** if the client already has a live website, offer to **scrape
inputs 5–11 from its URL** instead of asking for each — see §1b. Confirm the extracted
values with the user before executing.

Set shell vars for the rest of the run, e.g. `SLUG=action`, `WS="/Users/andywaldrop/Sites/prime service partners"`, `SRC=psp-master-build`.

## 1b. Pull branding from an existing site (optional)

When the user gives an existing site URL, auto-extract the brand profile. Proven on
`2coolair.net` (Scorpion-built HVAC site, but the patterns are generic).

```bash
mkdir -p /tmp/$SLUG-assets && cd /tmp/$SLUG-assets
UA='Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'
curl -sL -A "$UA" '<SITE_URL>' -o home.html

# Colors — most theme builders expose CSS custom properties:
grep -oiE '\-\-(primary|secondary|accent)[a-z-]*: *#[0-9a-fA-F]{3,8}' home.html | sort -u
# Fonts — Google Fonts import names the families (title var often names the heading):
grep -oiE 'fonts.googleapis.com[^"'\'' )]*' home.html | head
grep -oiE "\-\-fnt[a-z0-9-]*: *'[^']+'" home.html | head     # which family is the heading
# Logos — look for dark (light-bg/header) + light (dark-bg/footer) variants:
grep -oiE '<img[^>]*(logo|brand)[^>]*src="[^"]*"' home.html | grep -oiE 'src="[^"]*"'
# Download whatever you found, plus the favicon:
# curl -sL '<logo-dark-url>' -o logo-dark.png ; curl -sL '<logo-light-url>' -o logo-light.png
curl -sL '<SITE_ORIGIN>/favicon.ico' -o favicon.ico -w '%{http_code} %{content_type}\n'
```

Then:
- Map **primary → dark/light** by luminance (dark hex → `dark`; pale hex → `light`).
- **Heading font** = the family bound to the title/heading var (e.g. `--fnt-t`); **body
  font** = the other family.
- **Header logo** = the *dark* logo (it sits on the light header); **footer logo** = the
  *light/white* logo (dark footer). Verify each by viewing the file (Read the image).
- The downloaded files in `/tmp/$SLUG-assets/` become the paths for inputs 9–11.
- The site's own name/copy may differ from the desired display name — use the name the
  **user** confirmed, not necessarily the scraped one.

## 2. Export the master DB seed

```bash
cd "$WS/$SRC" && ddev export-db --file=/tmp/$SLUG-seed.sql.gz
```

## 3. Replicate master → new folder

Copy the **working tree** (includes `.git` for shared history, `vendor/`, and the secret
`.easy_encryption/` key). Exclude DDEV runtime artifacts and old seeds:

```bash
cd "$WS" && rsync -a \
  --exclude='.ddev/traefik/' \
  --exclude='.ddev/mutagen/mutagen.agents.tar.gz' \
  --exclude='**/.DS_Store' \
  --exclude='backup-file.sql' \
  --exclude='*.sql.gz' \
  "$SRC/" "$SLUG/"
```

Verify `.easy_encryption/`, `vendor/`, and `.git/` landed in `$SLUG/`.

## 4. Rename the DDEV project (BEFORE `ddev start`)

Edit `"$WS/$SLUG/.ddev/config.yaml"`: `name: psp-master` → `name: <slug>`.
This must happen before starting, or it conflicts with the running master.
(The gitignored `.ddev/.ddev-docker-compose-*.yaml` regenerate on start — ignore.)

## 5. Wire git remotes

```bash
cd "$WS/$SLUG"
git remote rename origin upstream      # master repo becomes 'upstream'
```

`origin` (the new repo) is created in step 11 after the first commit.

## 6. Start DDEV

```bash
cd "$WS/$SLUG" && ddev start
```

**Gotcha:** `ddev-router` may exceed its 60s health check and report a failure, yet
become healthy moments later. Verify rather than trusting the start exit:
`docker inspect --format '{{json .State.Health}}' ddev-router` and `ddev describe`.

## 7. Seed DB + fix keys

```bash
cd "$WS/$SLUG"
ddev import-db --file=/tmp/$SLUG-seed.sql.gz
ddev fix-keys                          # resets easy_encryption key path + lock hash
ddev drush updatedb --yes
ddev drush status                      # confirm bootstrap successful
```

Leave config alone — the seeded DB is the source of truth; do **not** `config:import`.

## 8. Detect the active theme

Do not hardcode `meridian_subtheme`; detect it:

```bash
THEME=$(cd "$WS/$SLUG" && ddev drush config:get system.theme default --format=string)
TDIR="web/themes/custom/dripyard_themes/$THEME"   # theme dir
TCFG="$TDIR/config/install/$THEME.settings.yml"   # source settings to keep in sync
```

## 9. Site name

```bash
cd "$WS/$SLUG" && ddev drush config:set system.site name '<Site display name>' --yes
```

## 9b. Localize the AI prompts (market + company name)

The master template's AI prompts reference **JefCo's** company name and market
(`Santa Rosa Beach, Florida`) in three configs — every child inherits them via the
DB seed (found the hard way 2026-07-09: five live sites generated content for the
wrong city). Replace them right after setting the site name:

- `ai_ckeditor.settings` (prompts.complete)
- `ai_automators.ai_automator.node.article.body.default`
- `ai_automators.ai_automator.node.services.field_featured_image_prompt.default`

With a market (input 12): replace `Santa Rosa Beach, Florida` → `<City, State>` and
`JefCo Air Conditioning & Plumbing` → `<Site display name>` in all string values of
those configs (recursive walk + `str_replace` via `drush php:script`, then
`cache:rebuild`).

Without a market ("none/multiple" — Swift Brothers pattern), instead replace:
- `Location: based in Santa Rosa Beach, Florida and will be used` → `The content will be used`
- `If generating images of outside, make them realistic to Santa Rosa Beach, Florida region.` → `If generating images of outside, make them realistic.`
- company name as above.

Verify: `grep -h 'company called\|Location: based\|realistic' <sync>/ai_ckeditor.settings.yml <sync>/ai_automators.*.yml` after the final config:export shows the new market/name and no `Santa Rosa`.

## 10. Rebrand the theme

For every change: update **live config / DB** *and* the theme **source file** so it
survives a fresh install/export.

**Colors** (live config — note bare `false` breaks; booleans handled in step where needed):
```bash
ddev drush config:set $THEME.settings theme_colors.colors.base_primary_color '<#PRIMARY>' --yes
ddev drush config:set $THEME.settings theme_colors.colors.base_primary_color_brightness '<dark|light>' --yes
ddev drush config:set $THEME.settings theme_colors.colors.base_secondary_color '<#SECONDARY>' --yes
ddev drush config:set $THEME.settings theme_colors.colors.base_secondary_color_brightness '<dark|light>' --yes
```
Also edit the same 4 values under `theme_colors.colors` in `$TCFG`.

**Fonts** — edit `$TDIR/css/base.css`:
- Replace the Google Fonts `@import` with the new families (css2, with weight ranges), e.g.
  `@import url('https://fonts.googleapis.com/css2?family=<Heading>:ital,wght@0,100..900;1,100..900&family=<Body>:ital,wght@0,100..700;1,100..700&display=swap');`
  (URL-encode spaces as `+`.)
- Set `--font-heading: '<Heading>', sans-serif;` and `--font-body: '<Body>', sans-serif;`
- Also set `--title-font-family: var(--font-heading);` — the base theme points the **Title**
  style at `--font-sans`, so without this override the Title (e.g. hero/page titles, any
  heading with `style: title`) keeps the base font and won't match the headlines. **Title
  should always match the heading font** unless the user explicitly asks for a different one.
- Bump heading `font-weight` (e.g. `400`→`600`) so a geometric sans keeps presence.

**Logo + favicon** — copy files into the theme, then set config. Keep the favicon's
**original extension** (`.png`, `.ico`, …) and set the matching mimetype. Booleans like
`use_default` must be set via PHP (drush `config:set ... false` stores the string `"false"`):
```bash
cp '<header logo path>'  "$WS/$SLUG/$TDIR/logo.png"
cp '<white logo path>'   "$WS/$SLUG/$TDIR/logo-white.png"   # if provided
FAV_EXT=ico   # or png — match the source file
cp '<favicon path>'      "$WS/$SLUG/$TDIR/favicon.$FAV_EXT"

P="themes/custom/dripyard_themes/$THEME"
# mimetype: image/png for .png, image/vnd.microsoft.icon for .ico
ddev drush config:set $THEME.settings logo.path     "$P/logo.png"          --yes
ddev drush config:set $THEME.settings favicon.path  "$P/favicon.$FAV_EXT"  --yes
ddev drush ev '$c=\Drupal::configFactory()->getEditable("'$THEME'.settings");
  $c->set("logo.use_default",FALSE)->set("favicon.use_default",FALSE)
    ->set("favicon.mimetype","<image/png|image/vnd.microsoft.icon>")
    ->set("features.favicon",TRUE)->save();'
```
Mirror into `$TCFG`: `logo.use_default:false` + `logo.path`, `favicon.use_default:false`
+ `favicon.path` + `favicon.mimetype`, `features.favicon:true`.

**Footer logo** is a **block_content** entity (lives in the DB, NOT config). Find the
"Footer Logo" basic block and repoint its inline `<img>` to the white logo:
```bash
ddev drush ev 'foreach(\Drupal::entityTypeManager()->getStorage("block_content")->loadMultiple() as $b){
  if(stripos($b->label(),"footer logo")!==false){ $id=$b->id();
    $fmt=$b->get("body")->format;
    $b->set("body",["value"=>"<img src=\"/themes/custom/dripyard_themes/'$THEME'/logo-white.png\" alt=\"<Site name>\" width=\"<W>\" height=\"<H>\">","format"=>$fmt]);
    $b->save(); echo "Footer Logo block $id updated\n"; } }'
```
Use the white logo's real pixel dimensions for `<W>`/`<H>` (`sips -g pixelWidth -g pixelHeight <file>`).

**Social links** — clear all placeholders in live config and in `$TCFG`:
```bash
ddev drush ev '$c=\Drupal::configFactory()->getEditable("'$THEME'.settings");
  $s=$c->get("social_media_links"); foreach($s as $k=>$v){$s[$k]="";} $c->set("social_media_links",$s)->save();'
```
(Leave any "Google My Business" / business-listing block — that's not a social profile.)

```bash
ddev drush cache:rebuild
```

## 11. Commit + create GitHub repo

```bash
cd "$WS/$SLUG"
git add .ddev/config.yaml "$TDIR/"
git commit -m "Initial <Site name> site from psp-master + theme rebrand

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```
If creating the repo (step 1 choice):
```bash
gh repo create aw44837/$SLUG --private --source=. --remote=origin --push
```

## 12. Verify (curl the live site)

```bash
cd "$WS/$SLUG"
curl -sk https://$SLUG.ddev.site/ -o /tmp/$SLUG-home.html
grep -oiE '<title>[^<]*</title>' /tmp/$SLUG-home.html                 # site name
grep -oiE 'base-primary-color: *#[0-9a-f]{6}' /tmp/$SLUG-home.html     # primary color
grep -oiE 'logo[^"]*\.png|logo-white\.png|rel="[^"]*icon[^"]*"' /tmp/$SLUG-home.html
# assets serve 200 (use the favicon extension you copied):
for a in logo.png logo-white.png favicon.$FAV_EXT; do
  curl -sk -o /dev/null -w "$a %{http_code}\n" "https://$SLUG.ddev.site/themes/custom/dripyard_themes/$THEME/$a"; done
ddev drush user:login                                                 # hand the user a login link
```

## What lives where (so deploys don't surprise you)

- **Git/config files:** theme assets, `base.css`, `$THEME.settings.yml`, `.ddev/config.yaml`.
- **Database only (not in git):** site name (`system.site`), the Footer Logo block, and
  any block content. These travel with the DB seed, not `config:export`.
- **Secret, untracked:** `.easy_encryption/` key — copied by the rsync; `fix-keys` resets
  its lock hash.

## Still manual after this skill

- Production deploy (server `settings.php`, Hostinger pull-deploy cron) — see the site's
  `CLAUDE.md` "New client site checklist".
- Replacing remaining seed/demo **content** (addresses, body copy, contact/GMB info).
