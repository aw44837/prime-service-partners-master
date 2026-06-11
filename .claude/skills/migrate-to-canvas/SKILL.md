---
name: migrate-to-canvas
description: >
  Migrate a single page from a client's existing website (typically a Scorpion CMS /
  Drupal-Canvas-style site) into a Drupal Experience Builder ("Canvas") page on a PSP
  site. Works for any core page that uses the Canvas component tree — Home, About,
  Services, Contact, Areas We Serve, etc. Recons the origin page (sections, copy,
  images), studies the target Canvas page + a completed sibling site as the component
  vocabulary, runs a media pipeline (origin images -> Drupal media), then edits and
  rebuilds the Canvas component tree to match the origin's layout and content. Use when
  the user wants to "migrate a page to Canvas", "rebuild <page> from the origin site",
  "recreate the <client> homepage/about/services page", or move a Scorpion page into the
  Drupal page builder. Aims for a strong, fully-editable starting point — not pixel-perfect.
---

# Migrate a page into Canvas

Rebuild one page from a client's origin site as a Drupal **Canvas** page, reusing the
existing SDC component library. Proven on the 2cool homepage (modeled on the Action
remake). **Set expectations: a strong, editable starting point; the user does final
image/styling touches.**

## How Canvas stores a page (read this first)

- Each page is a **`canvas_page`** content entity. Its layout lives in the
  **`components`** field (type `component_tree`, multi-value).
- Each component item has: `uuid`, `component_id` (e.g. `sdc.dripyard_base.heading`),
  `component_version` (a hash — must match the installed component), `inputs` (a JSON
  string of the component's props), `parent_uuid`, `slot`, `label`. Nesting is by
  `parent_uuid` + `slot`; **top-level sections** have `parent_uuid` = null and render in
  field order.
- **All visible content is inline in `inputs`** (no separate block entities):
  - heading: `inputs.text` — a **plain string** (Twig auto-escapes it).
  - text/paragraph: `inputs.text` = `{ "value": "<p>…</p>", "format": "canvas_html_block" }`.
  - image / background-image: `inputs.image.target_id` = a **media entity id**.
  - a blank image uses `inputs.image.sourceType: "default-relative-url"` → placeholder.
- Common SDC vocabulary: `layout-dynamic` (section/grid: `column_count`, `row_count`,
  `theme`), `section`, `neonbyte.hero` (slots `hero_media` + `hero_content`),
  `canvas-image`, `background-image`, `heading`, `text`, `flex-wrapper`, `button`,
  `card-canvas`/`card-full-image-canvas` (service cards), `grid-cell`, `icon-list`(+`-item`),
  `carousel`(+`content-card`), `fieldset`, `statistic`, `meridian.icon`.

## 0. Pre-flight

```bash
WS="/Users/andywaldrop/Sites/prime service partners"; SITE=2cool   # target site dir
cd "$WS/$SITE"
ddev drush config:get system.site page.front           # front page (e.g. /page/2)
ddev drush ev 'foreach(\Drupal::entityTypeManager()->getStorage("canvas_page")->loadMultiple() as $e){ echo $e->id()." | ".$e->label()."\n"; }'
```
- Identify the **target `canvas_page` id** for the page being migrated.
- Identify a **completed sibling page** to use as the quality reference (e.g. Action's
  Home, id 2). Same master → identical component library, so its tree is a valid recipe.
- Get the **origin page URL**.

## 1. Recon the origin (ground truth)

Scrape structure + content, and **screenshot it** (the screenshot resolves ambiguity that
scraping cannot — column counts, which sections have images, section order):

```bash
UA='Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'
curl -sL -A "$UA" '<ORIGIN_URL>' -o /tmp/origin.html
# heading flow in document order:
perl -0777 -ne 'while(/<(h[1-3])[^>]*>(.*?)<\/\1>/gis){my $t=$2;$t=~s/<[^>]+>//g;$t=~s/\s+/ /g;$t=~s/^\s+|\s+$//g;print uc($1).": $t\n" if length($t)>2&&length($t)<120;}' /tmp/origin.html
# section/content images (Scorpion uses semantic names: mainstage=hero, content-sN, services/*, values=why-choose):
grep -oE '/assets/[a-z]+/[a-z0-9-]+\.(jpg|png|webp)[^"]*' /tmp/origin.html | sed -E 's/\.[0-9]+\././' | sort -u
# full-page screenshot:
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --disable-gpu \
  --hide-scrollbars --window-size=1440,9000 --screenshot=/tmp/origin.png '<ORIGIN_URL>'
```
Read `/tmp/origin.png`. Write down the **ordered section list**: for each — heading(s),
body copy, CTA(s), image(s), and **layout** (two-column image/text, N-column grid, hero,
carousel). Confirm any 4-column vs two-column calls with the user if unsure — these are
easy to misread from scraping alone.

## 2. Recon the target + reference

Inventory the target page's component tree and the sibling reference. Write to a file and
read it back (drush stdout can be flaky under load):

```bash
ddev drush ev '$e=\Drupal::entityTypeManager()->getStorage("canvas_page")->load(<ID>);
 $vals=$e->get("components")->getValue(); $out=""; $i=0;
 foreach($vals as $v){ $cid=preg_replace("/^sdc\./","",$v["component_id"]); $t="";
   if(!empty($v["inputs"])){ $in=json_decode($v["inputs"],TRUE);
     if(isset($in["text"])&&is_string($in["text"]))$t=substr($in["text"],0,30);
     elseif(isset($in["text"]["value"]))$t="[".substr(strip_tags($in["text"]["value"]),0,22)."]";
     elseif(isset($in["image"]["target_id"]))$t="img#".$in["image"]["target_id"]; }
   $out.=sprintf("%2d|%-22s|u=%s|p=%s|s=%-8s|%s\n",$i++,substr($cid,0,22),substr($v["uuid"],0,6),substr($v["parent_uuid"]??"ROOT",0,6),$v["slot"]??"-",$t);}
 file_put_contents("/tmp/tree.txt",$out); echo "rows ".count($vals)."\n";'
ddev exec cat /tmp/tree.txt
```
Map each origin section (step 1) to either an **existing** target component you can
re-skin, or a **section to clone/build**. PSP master templates already ship a rich
generic homepage — often migration is mostly re-skinning + a few new sections.

## 3. Media pipeline (origin images -> Drupal media)

```bash
cd "$WS/$SITE/web/sites/default/files" && mkdir -p migrated && cd migrated
curl -sL '<ORIGIN_IMG_URL>' -o hero-bg.jpg          # repeat per image; keep semantic names
cd "$WS/$SITE"
ddev drush ev '$files=["hero-bg.jpg"=>"Hero Background","intro-img.png"=>"Intro"];
 foreach($files as $fn=>$name){ $uri="public://migrated/".$fn;
   if(!file_exists(\Drupal::service("file_system")->realpath($uri))){ echo "MISSING $uri\n"; continue; }
   $f=\Drupal\file\Entity\File::create(["uri"=>$uri,"status"=>1]); $f->save();
   $m=\Drupal\media\Entity\Media::create(["bundle"=>"image","name"=>$name,
     "field_media_image"=>["target_id"=>$f->id(),"alt"=>$name]]); $m->save();
   echo "$fn => media:".$m->id()."\n"; }'
```
Note the returned **media ids** — you reference them via `inputs.image.target_id`. (Files
land in `public://migrated/`; mutagen syncs host→container, usually within a second.)

## 4. Edit content (safe, no structure change)

Back up first, then edit inline `inputs`. Prefer **content-matched** edits (match on the
current string) over index-based — indices shift after any add/remove. Always **dry-run**.

```bash
# BACK UP the page before any structural work:
ddev drush ev '$e=\Drupal::entityTypeManager()->getStorage("canvas_page")->load(<ID>);
 file_put_contents("/tmp/page-backup.json", json_encode($e->get("components")->getValue()));' ; ddev exec cat /tmp/page-backup.json > /tmp/page-backup.json
```
Editing patterns (in a `drush php:script` file to avoid shell-quoting hell with apostrophes):
- iterate `$e->get('components')`, decode each item's `inputs`,
- set `inputs.text` (heading), `inputs.text.value` (body, wrap in `<p>…</p>`),
  `inputs.image.target_id` (image), then `$item->set('inputs', json_encode($in))`,
- `$e->save()` once at the end.

Gate writes behind `getenv('APPLY')==='1'`; run dry first (print hit counts + NOT-FOUND
list), then `ddev exec "APPLY=1 drush php:script /var/www/html/script.php"`.

## 5. Tree surgery (add / remove / reorder / clone)

To change structure, **rebuild the whole field** — never `setValue()` a detached item
(it trips `assert(is_string($name))` in ComponentTreeItem):

```php
$vals = $e->get('components')->getValue();   // indexed array of item-values
// …filter (remove), splice (insert), or append (add)…
$e->set('components', $newVals); $e->save();
```
- **Remove a section**: drop its root item + all descendants (BFS on `parent_uuid`).
  Removing a parent's content but keeping an N-cell `layout-dynamic` leaves empty
  columns — also drop the empty cells or lower `row_count`/`column_count`.
- **Clone a section** (best way to add a new two-column / card section): collect a proven
  section's subtree (root uuid + descendants), **generate new uuids for every item and
  remap `parent_uuid`** through that map, edit content/image, then splice the new items
  into the field **before/after** a target section (find it by uuid prefix). Alternate an
  image/text two-column by **swapping the `slot`** (`cell_1`<->`cell_2`) of the section's
  direct children. See the 2cool build for a working `build_section()` implementation.
- **Order** of top-level sections = their order in the field array; splice at the right
  index, don't just append.

## 5b. Replicating a layout across similar pages & creating a new page

A set of sibling pages (e.g. Air Conditioning / Heating / Indoor Air Quality) usually share
one layout with different content. Lock the layout on the **first** page (get user sign-off),
then:

- **Reusable "finish" step**: factor the structural extras into one script keyed by
  `TARGET` page id, so it runs identically on every page — e.g. remove a template-only
  hero offer box (drop the fieldset subtree), and **clone the Reviews + Contact sections
  from a page that already has them** (the homepage) into the target (insert reviews after
  "Explore Our Services", append contact at the end). Same `subtree()` + uuid-remap clone
  as §5. The contact section's `block.webform_block` clones fine — it just references the
  webform by id.
- **Per-page content** (hero/intro/value-props) stays separate — recon each origin page,
  swap heading/body/image. Sibling pages often reuse the same template indices (hero bg
  comp 2, hero heading comp 4, intro placeholder "Headline Goes Here", etc.).
- **No `canvas_page` for the page?** (the master ships only some service pages.) Create one
  by **duplicating a finished sibling** rather than building from scratch:
  ```php
  $src = ...->load(<FINISHED_ID>); $new = $src->createDuplicate();
  $new->set('title', '<Page Title>');
  $new->set('path', ['alias' => '/<slug>', 'langcode' => 'en']);   // path field drives the alias
  $new->save(); echo $new->id();
  ```
  Then **reskin** the duplicate by content-match (old headings/bodies → new) and a simple
  **media-id swap** (`image.target_id` old→new) — far easier than rebuilding. Finally add
  nav links so it's reachable, matching how siblings are linked (here: top-level `main`
  menu + a `footer` child), e.g.
  `MenuLinkContent::create(['title'=>…, 'link'=>['uri'=>'internal:/page/<id>'], 'menu_name'=>'main', 'weight'=>N])`.

## 6. Verify

```bash
ddev drush cache:rebuild
curl -sk https://$SITE.ddev.site/<path> -o /tmp/page.html         # 200?
grep -oF '<each expected heading>' /tmp/page.html                  # sections present
grep -oF '&amp;amp;' /tmp/page.html | wc -l                        # MUST be 0 (no double-escape)
for a in <each migrated image>; do curl -sk -o /dev/null -w "$a %{http_code}\n" \
  "https://$SITE.ddev.site/sites/default/files/migrated/$a"; done   # images 200
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --disable-gpu \
  --hide-scrollbars --ignore-certificate-errors --window-size=1440,9500 \
  --screenshot=/tmp/result.png "https://$SITE.ddev.site/<path>"     # then Read it
```
Read the screenshot and compare section-by-section to `/tmp/origin.png`. Note lazy images
(`loading="lazy"`) won't appear in a static screenshot even when they're fine — confirm
via the `<img>` `srcset` + a 200 on the file rather than the screenshot.

## Gotchas (all hit on the 2cool build)

- **Heading entities**: heading `inputs.text` is a plain string Twig escapes — use
  **literal characters**, never HTML entities. `&amp;` renders as `&amp;`, `&rsquo;` as
  `&rsquo;`, `&mdash;` as `&mdash;` — all double-escape. Safest belt-and-suspenders after
  writing headings: decode every heading prop on the page —
  `$in['text'] = html_entity_decode($in['text'], ENT_QUOTES|ENT_HTML5, 'UTF-8')`. Body HTML
  (`text.value`) is the opposite: entities (`&amp;`, `&mdash;`, `&rsquo;`) are correct there.
- **Never re-run an index-based script.** After one add/remove the indices shift; a second
  run edits the wrong components. Make scripts content-matched or idempotent, and delete
  temp scripts after use.
- **`drush config:set … false`** stores the string `"false"`; set booleans via
  `drush ev` with real `FALSE`.
- **Canvas draft/publish split**: drush edits the *published* `canvas_page`; the Canvas UI
  keeps a separate autosave/draft. If the user edits in the UI while you script (or vice
  versa), changes appear to "not take" or get clobbered — coordinate; don't edit both ways
  at once.
- **`ddev-router` can go unhealthy** under rapid Canvas writes (curl returns HTTP 000).
  Fix: `docker restart ddev-router`, wait ~10s, recheck `docker inspect --format
  '{{.State.Health.Status}}' ddev-router`. Web/db containers stay healthy independently.
- **drush stdout under load** is unreliable here — write results to a file and
  `ddev exec cat` it (or read the host-mounted path).
- **Image styles**: Canvas emits responsive `srcset` AVIF derivatives; the one largest
  width may 404 harmlessly. The base file 200 is what matters.

## What's in scope vs the user's final touches

- **You do:** section structure + order, headline/body copy, CTAs, origin images wired in,
  alternating layouts, contrast fixes.
- **They do (fine to leave):** swapping in better per-section photos, varying icons,
  fine-tuning section background rhythm and spacing, real testimonials.

## What lives where

Canvas page content (the whole component tree, the block content, media references) lives
in the **database**, not git — it travels with the DB seed, not `config:export`. The
migrated image **files** live in `web/sites/default/files/migrated/` (gitignored). So a
page migration produces **no committable diff**; capture/transport it via the DB.
